<?php

namespace App\Http\Controllers\Api\MiniApp;

use App\Enums\AppointmentNotificationEventType;
use App\Http\Controllers\Controller;
use App\Jobs\SendAppointmentNotificationJob;
use App\Models\Appointment;
use App\Models\AppointmentNotificationDelivery;
use App\Models\AppointmentNotificationEvent;
use App\Models\Client;
use App\Services\MiniAppClientResolver;
use App\Services\Notification\AppointmentChannelResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VkPermissionController extends Controller
{
    public function __invoke(
        Request $request,
        MiniAppClientResolver $resolver,
    ): JsonResponse {
        $request->validate([
            'appointment_id' => 'required|string',
            'granted' => 'required|boolean',
        ]);

        $vkUserId = $request->attributes->get('vk_launch')->userId;

        $clientIds = $resolver->resolveClientIds($request);

        $appointment = Appointment::with(['client'])->find($request->input('appointment_id'));

        if ($appointment === null) {
            return response()->json(['error' => 'appointment_not_found'], 404);
        }

        if ($appointment->client_id === null || ! $clientIds->contains($appointment->client_id)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $client = $appointment->client;

        if (! $client) {
            return response()->json(['error' => 'client_not_found'], 404);
        }

        $granted = $request->boolean('granted');

        if ($granted) {
            // Каждый granted=true — новый акт разрешения: версия растёт даже
            // при уже сохранённом true. Job сверяет версию до HTTP с CAS 901:
            // так новое разрешение отличимо от старого (сравнение одного
            // boolean недостаточно — grant может прийти при уже true).
            Client::query()
                ->whereKey($client->getKey())
                ->update([
                    'vk_messages_allowed' => true,
                    'vk_permission_version' => DB::raw('vk_permission_version + 1'),
                ]);
            $client->refresh();

            // Повтор granted=true обязан восстанавливать недоставленное
            // подтверждение, даже если разрешение уже true: при пересечении
            // permission и job первый retry мог не найти delivery-строк
            // (job ещё не записал no_channel), а повторный переход
            // false→true больше не наступит — без retry каждого запроса
            // доставка терялась навсегда. CAS-сброс и постановка job —
            // идемпотентны: чужой живой claim не трогается, дубль не рождается.
            $this->retryUndeliveredBookingConfirmed($appointment);
        } else {
            if ($client->vk_messages_allowed === null) {
                $client->update(['vk_messages_allowed' => false]);
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Повтор недоставленного подтверждения после granted=true.
     *
     * Вызывается при КАЖДОМ granted=true, не только при переходе false→true:
     * единичный переход не покрывает гонку, когда retry не нашёл
     * delivery-строк до записи no_channel самим job.
     *
     * Канал перечитывается через resolveForEvent: подтверждение,
     * созданное через ВК-вход (vk_only), повторяется только в ВК.
     *
     * Общий протокол с markFailed job'а: строка (event, channel) берётся
     * через SELECT … FOR UPDATE и решение о сбросе принимается ПОД ЭТИМ
     * lock'ом. FOR UPDATE ждёт блокировку job'а ДАЖЕ для pending-строки
     * (в отличие от UPDATE с фильтром status, который pending-строку не
     * матчит и вовсе не ждёт) и после пробуждения отдаёт закоммиченное
     * состояние строки: failed → сброс в pending + dispatch, pending
     * (grant гонялся с guard'ом markFailed) → без dispatch, продолжение
     * делает retry job'а. Так grant «между чтением версии в markFailed и
     * записью failed» не может пропустить будущий failed: его решение
     * всегда ждёт lock терминализации.
     *
     * Порядок блокировок совместим: обе стороны ждут только строку
     * доставки (claim держит event→строку, markFailed и этот tx — только
     * строку, по одной за транзакцию), никто не удерживает строку, ожидая
     * другую блокировку; транзакция не держится во время HTTP, dispatch —
     * после её коммита.
     *
     * Чужой живой claim не затрагивается: pending-строка не переводится.
     * Job ставится только если строка фактически вернулась в pending —
     * параллельные permission-запросы не дают второго redispatch'а.
     * Идентичность события не меняется (без bump'а версии).
     */
    private function retryUndeliveredBookingConfirmed(Appointment $appointment): void
    {
        $events = AppointmentNotificationEvent::query()
            ->where('appointment_id', $appointment->getKey())
            ->where('event_type', AppointmentNotificationEventType::BookingConfirmed)
            ->orderByDesc('event_version')
            ->get();

        $resolver = app(AppointmentChannelResolver::class);

        foreach ($events as $event) {
            $channel = $resolver->resolveForEvent($event, $appointment);

            if ($channel === null) {
                continue;
            }

            $reset = DB::transaction(function () use ($event, $channel): bool {
                // FOR UPDATE по (event, channel) без фильтра статуса:
                // блокировка берётся в любом состоянии строки — именно она
                // сериализует grant с ещё не закоммиченным failed.
                $row = AppointmentNotificationDelivery::query()
                    ->where('appointment_notification_event_id', $event->getKey())
                    ->where('channel', $channel)
                    ->lockForUpdate()
                    ->first();

                if ($row === null
                    || ! in_array($row->status, [
                        AppointmentNotificationDelivery::STATUS_NO_CHANNEL,
                        AppointmentNotificationDelivery::STATUS_FAILED,
                    ], true)) {
                    // pending с чужим живым claim'ом (или уже
                    // перехваченный) не трогаем — без dispatch.
                    return false;
                }

                $row->update([
                    'status' => AppointmentNotificationDelivery::STATUS_PENDING,
                    'claim_token' => null,
                    'lease_expires_at' => null,
                    'failure_reason' => null,
                    'failed_at' => null,
                ]);

                return true;
            });

            if ($reset) {
                // Коммит уже состоялся — dispatch вне транзакции.
                SendAppointmentNotificationJob::dispatch($event->getKey());

                break;
            }

            // Паритет прежнего break: событие с терминальными строками
            // (возможно, только на другом канале) завершает перебор —
            // старые события не получают redispatch из-за гонки каналов.
            if ($event->deliveries()
                ->whereIn('status', [
                    AppointmentNotificationDelivery::STATUS_NO_CHANNEL,
                    AppointmentNotificationDelivery::STATUS_FAILED,
                ])
                ->exists()) {
                break;
            }
        }
    }
}
