<?php

namespace App\Jobs;

use App\Enums\AppointmentNotificationEventType;
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
 *   delivery помечается superseded, HTTP не выполняется;
 * - перед HTTP — короткий claim-транзакционный insert/lock строки
 *   appointment_notification_deliveries по (event, channel) со свежим
 *   claim_token; два worker'а сериализуются, второй отступает (busy);
 * - HTTP вне какой-либо DB-транзакции; null от API / исключение ≠ успех;
 * - success-write и release — CAS по (event, channel, pending, own token);
 * - lease 60s > job timeout 30s > API timeout 10s; просроченный lease
 *   перехватывается с новым токеном;
 * - НЕ exactly-once: если API принял сообщение, а запись результата упала
 *   (или HTTP-исход неизвестен), ретрай может отправить дубль. Дубли
 *   ограничены deadline'ом, lease и строкой delivery;
 * - канал клиента перечитывается перед HTTP; исчезнувший канал →
 *   no_channel (терминально);
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

        $appointment = Appointment::with(['master', 'client', 'masterService.catalog'])
            ->find($event->appointment_id);

        if ($appointment === null) {
            return;
        }

        // Устаревшее событие: запись уже описана более новым событием.
        // Напоминание/подтверждение по старому расписанию не шлём.
        if ((int) $appointment->notification_version !== (int) $event->event_version) {
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

        if ($this->needsRecheck && $this->job !== null) {
            $this->job->release($this->busyRecheckDelay());
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendAppointmentNotificationJob: all retries exhausted', [
            'event_id' => $this->eventId,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Полный проход одного канала. Ничего не выходит наружу: исключение
     * любого шага — это failed канала, не mask'ит основной исход.
     *
     * @return 'delivered'|'already'|'busy'|'stale'|'failed'
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

            if ($fresh === null
                || (int) $fresh->notification_version !== (int) $event->event_version) {
                $this->releaseClaimSafely($event, $channel, $claimToken, $step);
                $this->markSuperseded($event);

                return 'stale';
            }

            // Канал перечитываем: клиент мог потерять привязку/согласие.
            $channelNow = app(AppointmentChannelResolver::class)->resolve($fresh);

            if ($channelNow === null) {
                $this->releaseClaimSafely($event, $channel, $claimToken, $step);
                $this->recordNoChannel($event);

                return 'stale';
            }

            $channel = $channelNow;
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

            if ($claimToken !== null) {
                $this->releaseClaimSafely($event, $channel, $claimToken, $step);
            }

            $this->markFailed($event, $channel, 'vk_messages_not_allowed');

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
                $this->releaseClaimSafely($event, $channel, $claimToken, $step);
            }

            return 'failed';
        }
    }

    /**
     * @return array{state: 'claimed'|'delivered'|'busy'|'failed', token?: string, lease_expires_at?: \DateTimeInterface|null}
     */
    private function claim(AppointmentNotificationEvent $event, string $channel): array
    {
        $claimToken = (string) Str::uuid();

        try {
            return DB::transaction(function () use ($event, $channel, $claimToken): array {
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
            ->delete();
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
            $row->save();

            return true;
        });
    }

    private function markSuperseded(AppointmentNotificationEvent $event): void
    {
        foreach ($event->deliveries()->where('status', AppointmentNotificationDelivery::STATUS_PENDING)->get() as $row) {
            $row->update([
                'status' => AppointmentNotificationDelivery::STATUS_SUPERSEDED,
                'claim_token' => null,
                'lease_expires_at' => null,
            ]);
        }
    }

    private function recordNoChannel(AppointmentNotificationEvent $event, string $reason = 'no_channel'): void
    {
        foreach ([AppointmentChannelResolver::CHANNEL_MAX, AppointmentChannelResolver::CHANNEL_VK] as $channel) {
            AppointmentNotificationDelivery::firstOrCreate(
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
        }
    }

    private function markFailed(
        AppointmentNotificationEvent $event,
        string $channel,
        string $reason,
    ): void {
        AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->getKey())
            ->where('channel', $channel)
            ->where('status', AppointmentNotificationDelivery::STATUS_PENDING)
            ->update([
                'status' => AppointmentNotificationDelivery::STATUS_FAILED,
                'claim_token' => null,
                'lease_expires_at' => null,
                'failure_reason' => $reason,
                'failed_at' => now(),
            ]);
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
        $type = $event->event_type instanceof AppointmentNotificationEventType
            ? $event->event_type->value
            : (string) $event->event_type;

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
