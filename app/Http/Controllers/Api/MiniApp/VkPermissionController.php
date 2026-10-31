<?php

namespace App\Http\Controllers\Api\MiniApp;

use App\Enums\AppointmentNotificationEventType;
use App\Http\Controllers\Controller;
use App\Jobs\SendAppointmentNotificationJob;
use App\Models\Appointment;
use App\Models\AppointmentNotificationDelivery;
use App\Models\AppointmentNotificationEvent;
use App\Services\MiniAppClientResolver;
use App\Services\Notification\AppointmentChannelResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            if ($client->vk_messages_allowed !== true) {
                $client->update(['vk_messages_allowed' => true]);
            }

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
     * Возврат в pending — CAS по (строка, status in no_channel/failed):
     * работающий job мог уже перехватить строку (failed→pending+lease) —
     * чужой живой claim не удаляется и не сбрасывается, дубль отправки
     * исключён. Job ставится только если строка фактически вернулась
     * в pending — параллельные permission-запросы не дают второго
     * redispatch'а. Идентичность события не меняется (без bump'а версии).
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

            $undelivered = $event->deliveries()
                ->whereIn('status', [
                    AppointmentNotificationDelivery::STATUS_NO_CHANNEL,
                    AppointmentNotificationDelivery::STATUS_FAILED,
                ])
                ->get();

            if ($undelivered->isEmpty()) {
                continue;
            }

            $reset = false;

            foreach ($undelivered as $row) {
                if ($row->channel !== $channel) {
                    continue;
                }

                $affected = AppointmentNotificationDelivery::query()
                    ->whereKey($row->getKey())
                    ->whereIn('status', [
                        AppointmentNotificationDelivery::STATUS_NO_CHANNEL,
                        AppointmentNotificationDelivery::STATUS_FAILED,
                    ])
                    ->update([
                        'status' => AppointmentNotificationDelivery::STATUS_PENDING,
                        'claim_token' => null,
                        'lease_expires_at' => null,
                        'failure_reason' => null,
                        'failed_at' => null,
                    ]);

                $reset = $reset || $affected > 0;
            }

            if ($reset) {
                SendAppointmentNotificationJob::dispatch($event->getKey());
            }

            break; // достаточно одного последнего события
        }
    }
}
