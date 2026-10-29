<?php

namespace App\Jobs;

use App\Enums\AppointmentNotificationEventType;
use App\Enums\AppointmentStatus;
use App\Events\AppointmentNotificationUpdated;
use App\Exceptions\VkMessagesNotAllowedException;
use App\Models\Appointment;
use App\Models\AppointmentNotificationDelivery;
use App\Models\AppointmentNotificationEvent;
use App\Services\MaxApiClient;
use App\Services\Notification\AppointmentChannelResolver;
use App\Services\VkApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Доставка ОДНОГО события клиентского уведомления о записи в выбранный
 * канал (MAX или ВК; Telegram — только для telegram-источника).
 *
 * Контракт (зеркалит SendReceiptUrlNotificationJob):
 * - событие = (appointment_id, event_type, event_version). Если запись уже
 *   ушла вперёд (notification_version > event_version), событие устарело:
 *   delivery переводится в superseded устойчиво (включая отсутствие строк
 *   до первого claim), HTTP не выполняется;
 * - событие, у которого уже есть доставленный канал, повторно НЕ
 *   отправляется в другой канал только из-за смены resolver'а;
 * - получатель зафиксирован в payload['client_id'] при записи события:
 *   смена клиента записи переводит событие в superseded, старый снимок
 *   не уходит новому клиенту;
 * - перед HTTP — claim-транзакция по (event, channel) со свежим
 *   claim_token, сериализованная lock'ом строки события (отсутствие
 *   delivery-строк не позволяет двум исполнителям взять разные каналы);
 *   если маршрут сменился между claim и HTTP, своё владение
 *   исходного канала освобождается, новое владение берётся для
 *   фактического канала; HTTP выполняется только под claim канала,
 *   который реально используется;
 * - HTTP вне какой-либо DB-транзакции; null от API / исключение ≠ успех;
 * - success-write и release — CAS по (event, channel, pending, own token):
 *   поздний исполнитель не затирает чужое владение и не подтверждает
 *   чужой успех;
 * - lease 60s > job timeout 30s > API timeout 10s; просроченный lease
 *   перехватывается с новым токеном;
 * - терминальные исходы хранятся устойчиво: failed() (исчерпание попыток /
 *   deadline / timeout) доводит бесхозные delivery до failed, но живые
 *   claims не трогает ни при каком состоянии очереди — осиротевшие
 *   терминализирует recovery после истечения lease; markFailed /
 *   markSuperseded не требуют предварительного claim и не трогают чужой
 *   живой claim; временная ошибка с предстоящим retry остаётся pending;
 * - для booking_confirmed ВК-клавиатура «Открыть» воспроизводится по
 *   типу события.
 */
class SendAppointmentNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 3;

    public int $retryUntil;

    public int $timeout = 30;

    private const LEASE_SECONDS = 60;

    private const LEASE_MARGIN_SECONDS = 2;

    private const JOB_DEADLINE_SECONDS = 600;

    private bool $needsRecheck = false;

    private ?\DateTimeInterface $busyUntil = null;

    public function __construct(public string $eventId)
    {
        $this->retryUntil = now()->addSeconds(self::JOB_DEADLINE_SECONDS)->getTimestamp();
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(
        AppointmentChannelResolver $resolver,
        MaxApiClient $maxApi,
        VkApiClient $vkApi,
    ): void {
        $this->needsRecheck = false;
        $this->busyUntil = null;

        $event = AppointmentNotificationEvent::find($this->eventId);

        if ($event === null) {
            return;
        }

        // Уже доставленный канал: повтор не уходит в другой канал
        // только из-за смены resolver'а.
        if ($event->deliveries()
            ->where('status', AppointmentNotificationDelivery::STATUS_DELIVERED)
            ->exists()) {
            return;
        }

        $appointment = Appointment::with(['master', 'client', 'masterService.catalog'])
            ->find($event->appointment_id);

        if ($appointment === null) {
            return;
        }

        // Устаревшее событие: запись уже описана более новым событием.
        // Напоминание/подтверждение по старому расписанию не шлём.
        if ($this->eventIsStale($event, $appointment)) {
            $this->markSuperseded($event);

            return;
        }

        // Получатель зафиксирован в событии: смена клиента записи —
        // старый снимок не уходит новому клиенту.
        $payloadClientId = $event->payload['client_id'] ?? null;
        if ($payloadClientId !== null
            && (string) $payloadClientId !== (string) $appointment->client_id) {
            $this->markSuperseded($event);

            return;
        }

        $channel = $resolver->resolve($appointment);

        if ($channel === null) {
            $this->recordNoChannel($event);

            return;
        }

        $text = (string) ($event->payload['text'] ?? '');

        if ($text === '') {
            $this->recordNoChannel($event, 'empty_text');

            return;
        }

        $result = $this->deliver($event, $channel, $appointment, $text, $maxApi, $vkApi);

        if ($result === 'failed') {
            throw new \RuntimeException(
                "Appointment notification delivery failed for channel [{$channel}]"
            );
        }

        if ($result === 'delivered') {
            $this->broadcastUpdated($event);
        }

        if ($this->needsRecheck && $this->job !== null) {
            $this->job->release($this->busyRecheckDelay());
        }
    }

    /**
     * Исчерпание попыток / deadline / timeout: терминальный итог должен
     * храниться устойчиво, а не растворяться вместе с освобождённым
     * claim'ом — иначе Summary навсегда показывает «Ожидает отправки».
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendAppointmentNotificationJob: all retries exhausted', [
            'event_id' => $this->eventId,
            'error' => $exception->getMessage(),
        ]);

        try {
            $event = AppointmentNotificationEvent::find($this->eventId);

            if ($event === null) {
                return;
            }

            $appointment = Appointment::with(['client'])->find($event->appointment_id);

            if ($appointment !== null
                && ($this->eventIsStale($event, $appointment)
                    || (($event->payload['client_id'] ?? null) !== null
                        && (string) $event->payload['client_id'] !== (string) $appointment->client_id))) {
                $this->markSuperseded($event);

                return;
            }

            // Любые оставшиеся pending-строки без чужого живого claim'а
            // доводятся до терминального failed.
            $this->markAllPendingFailed($event, 'retries_exhausted');

            // Живые claims НЕ терминализируются здесь: проверка очереди и
            // обновление разделены, между ними может появиться преемник
            // (в т.ч. с уже pop'нутым job'ом — строка jobs пуста). CAS по
            // чужому токену не доказывает владение. Осиротевшие claims
            // завершает recovery после истечения lease.

            if ($appointment === null) {
                return;
            }

            $channel = app(AppointmentChannelResolver::class)->resolve($appointment);

            if ($channel === null) {
                $this->recordNoChannel($event);

                return;
            }

            // Строк могло не быть вовсе (отсечена до первого claim'а) —
            // создаём терминальную строку для фактического канала.
            $this->markFailed($event, $channel, 'retries_exhausted');
        } catch (\Throwable $e) {
            Log::error('SendAppointmentNotificationJob: failed() could not persist terminal state', [
                'event_id' => $this->eventId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Полный проход одного канала. Ничего не выходит наружу: исключение
     * любого шага — это failed канала, не mask'ит основной исход.
     *
     * @return 'delivered'|'already'|'busy'|'stale'|'failed'
     *
     * @phpstan-impure
     */
    private function deliver(
        AppointmentNotificationEvent $event,
        string $channel,
        Appointment $appointment,
        string $text,
        MaxApiClient $maxApi,
        VkApiClient $vkApi,
    ): string {
        $claimToken = null;
        $step = 'claim';

        try {
            $claim = $this->claim($event, $channel);

            if ($claim['state'] !== 'claimed') {
                if ($claim['state'] === 'busy') {
                    $this->noteBusyRecheck($claim['lease_expires_at'] ?? null);
                }

                return $claim['state'];
            }

            $claimToken = $claim['token'];

            // Финальный freshness-gate перед сетью.
            $step = 'freshness';
            $fresh = Appointment::with(['master', 'client'])->find($event->appointment_id);

            if ($fresh === null || $this->eventIsStale($event, $fresh)) {
                $this->releaseClaimSafely($event, $channel, $claimToken, $step);
                $this->markSuperseded($event);

                return 'stale';
            }

            $payloadClientId = $event->payload['client_id'] ?? null;
            if ($payloadClientId !== null
                && (string) $payloadClientId !== (string) $fresh->client_id) {
                $this->releaseClaimSafely($event, $channel, $claimToken, $step);
                $this->markSuperseded($event);

                return 'stale';
            }

            // Канал перечитываем: клиент мог потерять привязку/согласие.
            $step = 'route';
            $channelNow = app(AppointmentChannelResolver::class)->resolve($fresh);

            if ($channelNow === null) {
                $this->releaseClaimSafely($event, $channel, $claimToken, $step);
                $this->recordNoChannel($event);

                return 'stale';
            }

            if ($channelNow !== $channel) {
                // Маршрут сменился: освобождаем ТОЛЬКО своё владение
                // исходного канала и корректно берём claim нового. HTTP
                // выполняется исключительно под claim фактического канала.
                $this->releaseClaimSafely($event, $channel, $claimToken, $step);

                $reclaim = $this->claim($event, $channelNow);

                if ($reclaim['state'] !== 'claimed') {
                    if ($reclaim['state'] === 'busy') {
                        $this->noteBusyRecheck($reclaim['lease_expires_at'] ?? null);
                    }

                    return $reclaim['state'];
                }

                $claimToken = $reclaim['token'];
                $channel = $channelNow;
            }

            $appointment = $fresh;

            $step = 'send';
            $providerMessageId = $this->httpSend($channel, $appointment, $text, $event, $maxApi, $vkApi);

            if ($providerMessageId === null) {
                $this->releaseClaimSafely($event, $channel, $claimToken, $step);

                return 'failed';
            }

            $step = 'record';
            if (! $this->recordDelivery($event, $channel, $claimToken, $providerMessageId)) {
                $this->noteBusyRecheck(null);

                return 'busy';
            }

            // Согласие ВК: успешная отправка подтверждает, что 901 не было.
            if ($channel === AppointmentChannelResolver::CHANNEL_VK
                && $appointment->client !== null
                && $appointment->client->vk_messages_allowed === null) {
                $appointment->client->update(['vk_messages_allowed' => true]);
            }

            return 'delivered';
        } catch (VkMessagesNotAllowedException) {
            if ($appointment->client !== null) {
                $appointment->client->update(['vk_messages_allowed' => false]);
            }

            // 901 терминален: сначала фиксируем failed ПОД своим claim'ом
            // (release до записи терминала терял строку), затем освобождение.
            if ($claimToken !== null) {
                $this->markFailed($event, $channel, 'vk_messages_not_allowed', $claimToken);
                $this->releaseClaimSafely($event, $channel, $claimToken, 'vk_901');
            } else {
                $this->markFailed($event, $channel, 'vk_messages_not_allowed');
            }

            // Терминально: 901 — клиент запретил сообщения сообщества.
            return 'failed';
        } catch (\Throwable $e) {
            Log::error('Appointment notification delivery pass failed', [
                'event_id' => $event->getKey(),
                'channel' => $channel,
                'step' => $step,
                'error' => $e->getMessage(),
            ]);

            if ($claimToken !== null) {
                // Whatever failed above, give OUR claim back so the bounded
                // retry can re-attempt this channel instead of waiting for
                // the lease. A failing release is logged separately and must
                // not replace the primary error recorded here.
                $this->releaseClaimSafely($event, $channel, $claimToken, $step);
            }

            return 'failed';
        }
    }

    /**
     * Claim (event, channel) before HTTP. Runs in its own short transaction
     * that is committed before any network call. The capture is serialized
     * by a FOR UPDATE lock on the shared event row, so executors racing on
     * different channels cannot both pass the empty-deliveries check.
     *
     * Every capture gets a fresh claim_token — first insert AND takeover of
     * an expired lease — which is returned to the executor: from then on the
     * success write and the release only ever match rows carrying it.
     *
     * @return array{state: 'claimed', token: string}|array{state: 'delivered'|'stale'|'busy'|'failed', lease_expires_at?: \DateTimeInterface|null}
     */
    private function claim(AppointmentNotificationEvent $event, string $channel): array
    {
        $claimToken = (string) Str::uuid();

        try {
            return DB::transaction(function () use ($event, $channel, $claimToken): array {
                // Захват сериализуется через ОБЩУЮ строку события: даже
                // когда delivery-строк ещё нет (оба исполнителя стартуют
                // одновременно), второй ждёт здесь и затем в ТОЙ ЖЕ
                // транзакции видит чужой живой claim/доставку. Без этой
                // блокировки оба прошли бы foreignLive-проверку по пустому
                // набору и создали claims разных каналов.
                $locked = AppointmentNotificationEvent::query()
                    ->whereKey($event->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($locked === null) {
                    // Событие исчезло — владение не нужно.
                    return ['state' => 'stale'];
                }

                // Согласование на уровне события: чужой живой claim на
                // ДРУГОМ канале блокирует новый проход — одно событие не
                // обрабатывается двумя живыми исполнителями (A держит max
                // внутри HTTP, маршрут меняется, B не должен увести vk).
                $foreignLive = AppointmentNotificationDelivery::query()
                    ->where('appointment_notification_event_id', $event->getKey())
                    ->where('channel', '!=', $channel)
                    ->where('status', AppointmentNotificationDelivery::STATUS_PENDING)
                    ->whereNotNull('lease_expires_at')
                    ->where('lease_expires_at', '>', now())
                    ->lockForUpdate()
                    ->first();

                if ($foreignLive !== null) {
                    return [
                        'state' => 'busy',
                        'lease_expires_at' => $foreignLive->lease_expires_at,
                    ];
                }

                $row = AppointmentNotificationDelivery::query()
                    ->where('appointment_notification_event_id', $event->getKey())
                    ->where('channel', $channel)
                    ->lockForUpdate()
                    ->first();

                if ($row !== null) {
                    if ($row->status === AppointmentNotificationDelivery::STATUS_DELIVERED) {
                        return ['state' => 'delivered'];
                    }

                    if (in_array($row->status, [
                        AppointmentNotificationDelivery::STATUS_FAILED,
                        AppointmentNotificationDelivery::STATUS_SUPERSEDED,
                        AppointmentNotificationDelivery::STATUS_NO_CHANNEL,
                    ], true)) {
                        return ['state' => $row->status === AppointmentNotificationDelivery::STATUS_SUPERSEDED
                            ? 'stale'
                            : 'delivered'];
                    }

                    if ($row->lease_expires_at !== null && $row->lease_expires_at->isFuture()) {
                        return [
                            'state' => 'busy',
                            'lease_expires_at' => $row->lease_expires_at,
                        ];
                    }

                    $row->update([
                        'claim_token' => $claimToken,
                        'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS),
                        'failure_reason' => null,
                        'failed_at' => null,
                    ]);

                    return ['state' => 'claimed', 'token' => $claimToken];
                }

                AppointmentNotificationDelivery::create([
                    'appointment_notification_event_id' => $event->getKey(),
                    'channel' => $channel,
                    'status' => AppointmentNotificationDelivery::STATUS_PENDING,
                    'claim_token' => $claimToken,
                    'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS),
                ]);

                return ['state' => 'claimed', 'token' => $claimToken];
            });
        } catch (QueryException $e) {
            $sqlState = $e->errorInfo[0] ?? '';
            if (in_array($sqlState, ['23505', '23000'], true)
                || stripos($e->getMessage(), 'unique') !== false) {
                return [
                    'state' => 'busy',
                    'lease_expires_at' => $this->currentLease($event, $channel),
                ];
            }

            throw $e;
        }
    }

    /**
     * Актуальность события.
     *
     * - Обычное событие устарело, если запись описана более новым
     *   событием (notification_version ушла вперёд);
     * - series-событие сверяется по ФАКТИЧЕСКИ затронутому расписанию,
     *   а не по версии якоря: правка цены не гасит серию, но перенос
     *   или отмена первого визита — гасит устаревший снимок. Частичная
     *   отмена не убивает создание/обновление целиком, а требует
     *   сверки счётчика и первого визита;
     * - SeriesCancelled не подавляется более поздними отменами той же
     *   серии: получатели и отменённые визиты могут различаться.
     */
    private function eventIsStale(
        AppointmentNotificationEvent $event,
        Appointment $appointment,
    ): bool {
        if ($event->recurring_series_id === null) {
            return (int) $appointment->notification_version !== (int) $event->event_version;
        }

        // Якорь больше не принадлежит серии события (split/rebind).
        if ((string) $appointment->recurring_series_id !== (string) $event->recurring_series_id) {
            return true;
        }

        // SeriesCancelled: исторический факт отмены для своих
        // получателей. Более поздняя отмена ДРУГОЙ части серии или
        // ДРУГОГО получателя не подавляет ранее зафиксированные
        // сообщения — счётчики и даты могут различаться, created_at
        // не доказательство замещения.
        if ($event->event_type === AppointmentNotificationEventType::SeriesCancelled) {
            return false;
        }

        // SeriesCreated / SeriesUpdated: снимок расписания сверяется
        // с фактическим состоянием серии.
        return $this->seriesScheduleChanged($event, $appointment);
    }

    /**
     * Фактическое расписание серии изменилось относительно снимка
     * в payload (счётчик активных визитов или первый визит).
     */
    private function seriesScheduleChanged(
        AppointmentNotificationEvent $event,
        Appointment $anchor,
    ): bool {
        $seriesId = $event->recurring_series_id;
        $clientId = $event->payload['client_id'] ?? $anchor->client_id;

        $active = Appointment::query()
            ->where('recurring_series_id', $seriesId)
            ->where('client_id', $clientId)
            ->whereIn('status', [
                AppointmentStatus::Booked,
                AppointmentStatus::PendingPayment,
                AppointmentStatus::Prepaid,
            ])
            ->where('start_time', '>', now())
            ->orderBy('start_time')
            ->get();

        $expectedCount = isset($event->payload['affected_count'])
            ? (int) $event->payload['affected_count']
            : null;

        if ($expectedCount !== null && $active->count() !== $expectedCount) {
            return true;
        }

        $expectedFirst = $event->payload['first_start_time'] ?? null;

        if ($expectedFirst !== null && $active->isNotEmpty()) {
            $actualFirst = $active->first()->start_time;

            if (Carbon::parse($expectedFirst)->getTimestamp()
                !== $actualFirst->getTimestamp()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Освобождение СВОЕГО владения: CAS по токену, строка остаётся
     * pending без claim'а — терминальные исходы later (failed() и т.п.)
     * дорабатывают именно эту строку, а не теряют её.
     */
    private function releaseClaim(
        AppointmentNotificationEvent $event,
        string $channel,
        string $claimToken,
    ): void {
        AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->getKey())
            ->where('channel', $channel)
            ->where('status', AppointmentNotificationDelivery::STATUS_PENDING)
            ->where('claim_token', $claimToken)
            ->update([
                'claim_token' => null,
                'lease_expires_at' => null,
            ]);
    }

    private function releaseClaimSafely(
        AppointmentNotificationEvent $event,
        string $channel,
        string $claimToken,
        string $reason,
    ): void {
        try {
            $this->releaseClaim($event, $channel, $claimToken);
        } catch (\Throwable $e) {
            Log::error('Appointment notification claim release failed', [
                'event_id' => $event->getKey(),
                'channel' => $channel,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function recordDelivery(
        AppointmentNotificationEvent $event,
        string $channel,
        string $claimToken,
        string $providerMessageId,
    ): bool {
        return DB::transaction(function () use ($event, $channel, $claimToken, $providerMessageId): bool {
            $row = AppointmentNotificationDelivery::query()
                ->where('appointment_notification_event_id', $event->getKey())
                ->where('channel', $channel)
                ->where('status', AppointmentNotificationDelivery::STATUS_PENDING)
                ->where('claim_token', $claimToken)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return false;
            }

            $row->status = AppointmentNotificationDelivery::STATUS_DELIVERED;
            $row->provider_message_id = $providerMessageId;
            $row->delivered_at = Carbon::now();
            $row->lease_expires_at = null;
            // Владение завершено успешной доставкой: токен не остаётся
            // висеть на терминальной строке.
            $row->claim_token = null;
            $row->save();

            return true;
        });
    }

    /**
     * Терминальный superseded устойчив: обновляются только pending-строки
     * без чужого живого claim'а; если строк не было вовсе (отсечена до
     * первого claim'а) — создаются строки, иначе Summary навсегда
     * показывал бы «Ожидает отправки».
     */
    private function markSuperseded(AppointmentNotificationEvent $event): void
    {
        $pending = $event->deliveries()
            ->where('status', AppointmentNotificationDelivery::STATUS_PENDING)
            ->get();

        foreach ($pending as $row) {
            if ($this->hasForeignLiveClaim($row)) {
                continue;
            }

            // CAS: переводим только свою/бесхозную pending-строку.
            AppointmentNotificationDelivery::query()
                ->whereKey($row->getKey())
                ->where('status', AppointmentNotificationDelivery::STATUS_PENDING)
                ->where(function ($query) use ($row) {
                    $query->whereNull('claim_token')
                        ->orWhere('claim_token', (string) $row->claim_token);
                })
                ->update([
                    'status' => AppointmentNotificationDelivery::STATUS_SUPERSEDED,
                    'claim_token' => null,
                    'lease_expires_at' => null,
                ]);
        }

        if ($event->deliveries()->exists()) {
            $this->broadcastUpdated($event);

            return;
        }

        foreach ([AppointmentChannelResolver::CHANNEL_MAX, AppointmentChannelResolver::CHANNEL_VK] as $channel) {
            AppointmentNotificationDelivery::firstOrCreate(
                [
                    'appointment_notification_event_id' => $event->getKey(),
                    'channel' => $channel,
                ],
                [
                    'status' => AppointmentNotificationDelivery::STATUS_SUPERSEDED,
                ],
            );
        }

        $this->broadcastUpdated($event);
    }

    private function recordNoChannel(AppointmentNotificationEvent $event, string $reason = 'no_channel'): void
    {
        foreach ([AppointmentChannelResolver::CHANNEL_MAX, AppointmentChannelResolver::CHANNEL_VK] as $channel) {
            $row = AppointmentNotificationDelivery::firstOrCreate(
                [
                    'appointment_notification_event_id' => $event->getKey(),
                    'channel' => $channel,
                ],
                [
                    'status' => AppointmentNotificationDelivery::STATUS_NO_CHANNEL,
                    'failure_reason' => $reason,
                    'failed_at' => now(),
                ],
            );

            // Строка могла остаться pending после освобождённого claim'а —
            // доводим до терминала, не трогая чужой живой claim.
            if ($row->status === AppointmentNotificationDelivery::STATUS_PENDING
                && ! $this->hasForeignLiveClaim($row)) {
                AppointmentNotificationDelivery::query()
                    ->whereKey($row->getKey())
                    ->where('status', AppointmentNotificationDelivery::STATUS_PENDING)
                    ->whereNull('claim_token')
                    ->update([
                        'status' => AppointmentNotificationDelivery::STATUS_NO_CHANNEL,
                        'failure_reason' => $reason,
                        'failed_at' => now(),
                        'lease_expires_at' => null,
                    ]);
            }
        }

        $this->broadcastUpdated($event);
    }

    /**
     * Терминальный failed одного канала. Без предварительного claim'а:
     * создаёт строку, если её не было; обновляет только pending без чужого
     * живого владения — поздний исполнитель не затирает чужой claim и не
     * переписывает подтверждённый успех.
     */
    private function markFailed(
        AppointmentNotificationEvent $event,
        string $channel,
        string $reason,
        ?string $claimToken = null,
    ): void {
        DB::transaction(function () use ($event, $channel, $reason, $claimToken): void {
            $row = AppointmentNotificationDelivery::query()
                ->where('appointment_notification_event_id', $event->getKey())
                ->where('channel', $channel)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                AppointmentNotificationDelivery::create([
                    'appointment_notification_event_id' => $event->getKey(),
                    'channel' => $channel,
                    'status' => AppointmentNotificationDelivery::STATUS_FAILED,
                    'failure_reason' => $reason,
                    'failed_at' => now(),
                ]);

                return;
            }

            if ($row->status !== AppointmentNotificationDelivery::STATUS_PENDING) {
                return;
            }

            if ($claimToken !== null) {
                if ((string) $row->claim_token !== $claimToken) {
                    return;
                }
            } elseif ($this->hasForeignLiveClaim($row)) {
                return;
            }

            $row->update([
                'status' => AppointmentNotificationDelivery::STATUS_FAILED,
                'claim_token' => null,
                'lease_expires_at' => null,
                'failure_reason' => $reason,
                'failed_at' => now(),
            ]);
        });

        $this->broadcastUpdated($event);
    }

    /**
     * Довести все pending-строки события до failed (без чужого живого
     * claim'а) — используется в failed() после исчерпания попыток.
     */
    private function markAllPendingFailed(AppointmentNotificationEvent $event, string $reason): void
    {
        foreach ($event->deliveries()
            ->where('status', AppointmentNotificationDelivery::STATUS_PENDING)
            ->get() as $row) {
            if ($this->hasForeignLiveClaim($row)) {
                continue;
            }

            AppointmentNotificationDelivery::query()
                ->whereKey($row->getKey())
                ->where('status', AppointmentNotificationDelivery::STATUS_PENDING)
                ->whereNull('claim_token')
                ->update([
                    'status' => AppointmentNotificationDelivery::STATUS_FAILED,
                    'failure_reason' => $reason,
                    'failed_at' => now(),
                    'lease_expires_at' => null,
                ]);
        }
    }

    /**
     * Терминальный исход зафиксирован — сообщаем календарю, чтобы сводка
     * «Уведомления клиенту» обновилась без ручного обновления страницы.
     * Сбой рассылки не должен ронять delivery-проход.
     */
    private function broadcastUpdated(AppointmentNotificationEvent $event): void
    {
        try {
            $appointment = Appointment::find($event->appointment_id);

            if ($appointment === null) {
                return;
            }

            broadcast(new AppointmentNotificationUpdated(
                (string) $event->appointment_id,
                (string) $appointment->master_id,
            ));
        } catch (\Throwable $e) {
            Log::warning('AppointmentNotificationUpdated broadcast failed', [
                'event_id' => $event->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Чужой живой claim: чужой токен и непросроченный lease — трогать
     * строку нельзя (поздний исполнитель не затирает чужое владение).
     */
    private function hasForeignLiveClaim(AppointmentNotificationDelivery $row): bool
    {
        return $row->claim_token !== null
            && $row->lease_expires_at !== null
            && $row->lease_expires_at->isFuture();
    }

    private function httpSend(
        string $channel,
        Appointment $appointment,
        string $text,
        AppointmentNotificationEvent $event,
        MaxApiClient $maxApi,
        VkApiClient $vkApi,
    ): ?string {
        $client = $appointment->client;

        if ($client === null) {
            return null;
        }

        if ($channel === AppointmentChannelResolver::CHANNEL_MAX) {
            return $maxApi->sendMessage((string) $client->max_id, $text);
        }

        if ($channel === AppointmentChannelResolver::CHANNEL_VK) {
            $keyboard = $this->vkKeyboard($event, $appointment);

            if ($keyboard !== null) {
                return $vkApi->sendMessageWithKeyboard((string) $client->vk_id, $text, $keyboard);
            }

            return $vkApi->sendMessage((string) $client->vk_id, $text);
        }

        if ($channel === AppointmentChannelResolver::CHANNEL_TELEGRAM) {
            // Telegram не развивается; синхронная доставка через старый путь
            // не переносится сюда — resolver возвращает telegram только для
            // telegram-источника, где подтверждение шлётся webhook-хендлером.
            return null;
        }

        return null;
    }

    /**
     * Клавиатура ВК-подтверждения брони — тот же open_app, что в
     * SendVkBookingConfirmation, чтобы кнопки мини-приложения сохранялись.
     *
     * @return array<string, mixed>|null
     */
    private function vkKeyboard(
        AppointmentNotificationEvent $event,
        Appointment $appointment,
    ): ?array {
        $type = $event->event_type->value;

        if ($type !== AppointmentNotificationEventType::BookingConfirmed->value) {
            return null;
        }

        if (empty(config('services.vk.group_id'))) {
            return null;
        }

        return [
            'one_time' => false,
            'inline' => true,
            'buttons' => [[[
                'action' => [
                    'type' => 'open_app',
                    'app_id' => (int) config('services.vk.app_id'),
                    'owner_id' => -(int) config('services.vk.group_id'),
                    'label' => '📅 Открыть ИРСИ',
                    'hash' => '',
                ],
            ]]],
        ];
    }

    private function currentLease(
        AppointmentNotificationEvent $event,
        string $channel,
    ): ?\DateTimeInterface {
        return AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->getKey())
            ->where('channel', $channel)
            ->first()
            ?->lease_expires_at;
    }

    /**
     * Запоминает, что проход упёрся в чужой живой claim и должен быть
     * повторён после lease'а.
     *
     * @phpstan-impure
     */
    private function noteBusyRecheck(?\DateTimeInterface $leaseExpiresAt): void
    {
        $this->needsRecheck = true;

        if ($leaseExpiresAt !== null
            && ($this->busyUntil === null
                || $leaseExpiresAt->getTimestamp() > $this->busyUntil->getTimestamp())) {
            $this->busyUntil = $leaseExpiresAt;
        }
    }

    private function busyRecheckDelay(): int
    {
        if ($this->busyUntil === null) {
            return 1;
        }

        $landAfter = $this->busyUntil->getTimestamp() + self::LEASE_MARGIN_SECONDS;

        return max(1, $landAfter - now()->getTimestamp());
    }
}
