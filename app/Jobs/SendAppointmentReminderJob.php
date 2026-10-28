<?php

namespace App\Jobs;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Exceptions\VkMessagesNotAllowedException;
use App\Models\Appointment;
use App\Models\Client;
use App\Services\MaxApiClient;
use App\Services\Notification\AppointmentChannelResolver;
use App\Services\VkApiClient;
use DefStudio\Telegraph\Keyboard\Button;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Напоминание клиенту о визите (24h / final).
 *
 * Перед отправкой перечитываются время, статус, клиент и канал.
 * Устаревшая задача (запись перенесена/отменена после постановки) не
 * отправляет напоминание по прежнему расписанию и не портит поля новой
 * версии: failed() проверяет, что sent_at не появился и что ожидаемое
 * start_time/напоминание ещё соответствуют текущей строке.
 *
 * Канал — общий AppointmentChannelResolver (тот же, что у уведомлений):
 * рабочий канал записи, иначе MAX → ВК. Telegram — только telegram-источник.
 */
class SendAppointmentReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** Snapshot start_time на момент постановки: защищает failed()-hook
     *  от пометки failed у новой версии после переноса. */
    public string $expectedStartTime;

    public function __construct(
        private Appointment $appointment,
        private string $type,
    ) {
        $this->expectedStartTime = $appointment->start_time?->toIso8601String() ?? '';
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(): void
    {
        $fresh = $this->appointment->fresh();

        if ($fresh === null) {
            return;
        }

        // Перечитываем статус, время, клиента: перенос/отмена/смена клиента
        // до выполнения job'а отменяют отправку.
        if (! in_array($fresh->status, [AppointmentStatus::Booked, AppointmentStatus::Prepaid], true)) {
            return;
        }

        // Слишком поздно обработанная задача не шлёт напоминание уже
        // начавшегося визита. Окно 23–25 ч для 24h отсекает команда
        // (whereBetween); job дополнительно не шлёт «задним числом»:
        // если до визита < 2 ч, «завтра» уже бессмысленно.
        if ($fresh->start_time->isPast()) {
            return;
        }

        if ($this->type === '24h') {
            $hoursUntil = (float) now()->diffInHours($fresh->start_time, false);
            if ($hoursUntil < 2) {
                return;
            }
        } else {
            $hoursBeforeFinal = $fresh->master?->getReminderHoursBeforeFinal() ?? 0;
            if ($hoursBeforeFinal === 0) {
                return;
            }
        }

        $appointment = $fresh->load(['master', 'client']);

        if (! $appointment->client) {
            return;
        }

        $client = $appointment->client;
        $source = $appointment->source;
        $sourceValue = $source instanceof AppointmentSource ? $source->value : $source;

        $channel = app(AppointmentChannelResolver::class)->resolve($appointment);

        if ($channel === null) {
            $this->markTerminalFailed($appointment, 'No routable channel for reminder');

            return;
        }

        $sent = false;

        if ($channel === AppointmentChannelResolver::CHANNEL_TELEGRAM) {
            $lockTg = "reminder_{$this->type}_tg_{$appointment->id}";
            if (Cache::add($lockTg, true, now()->addHours(12))) {
                try {
                    $this->sendTelegram($appointment, $client);
                    $sent = true;
                } catch (Throwable $e) {
                    Cache::forget($lockTg);
                    throw $e;
                }
            }
        }

        if ($channel === AppointmentChannelResolver::CHANNEL_MAX) {
            $lockMax = "reminder_{$this->type}_max_{$appointment->id}";
            if (Cache::add($lockMax, true, now()->addHours(12))) {
                try {
                    $this->sendMax($appointment, $client);
                    $sent = true;
                } catch (Throwable $e) {
                    Cache::forget($lockMax);
                    throw $e;
                }
            }
        }

        if ($channel === AppointmentChannelResolver::CHANNEL_VK) {
            $lockVk = "reminder_{$this->type}_vk_{$appointment->id}";
            if (Cache::add($lockVk, true, now()->addHours(12))) {
                try {
                    $this->sendVk($appointment, $client);
                    $sent = true;
                } catch (VkMessagesNotAllowedException) {
                    $client->update(['vk_messages_allowed' => false]);
                    $this->markTerminalFailed($appointment, 'VK messages not allowed (901)');

                    return;
                } catch (Throwable $e) {
                    Cache::forget($lockVk);
                    throw $e;
                }
            }
        }

        if ($sent) {
            $this->markAsSent($appointment);

            return;
        }

        $this->markTerminalFailed($appointment, 'No routable channel for reminder');
    }

    private function sendTelegram(Appointment $appointment, Client $client): void
    {
        $token = config('services.telegram.bot_token');

        if (empty($token)) {
            return;
        }

        $text = $this->buildMessage($appointment, 'telegram');

        try {
            $payload = [
                'chat_id' => $client->telegram_id,
                'text' => $text,
                'parse_mode' => 'HTML',
            ];

            // Кнопка «Подтверждаю» — только в напоминании за 24 часа
            if ($this->type === '24h') {
                $button = Button::make(__('bot.buttons.confirm_visit'))
                    ->action('confirmVisit')
                    ->param('id', $appointment->id)
                    ->toArray();

                $payload['reply_markup'] = [
                    'inline_keyboard' => [[$button]],
                ];
            }

            $response = Http::connectTimeout(3)
                ->timeout(20)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);

            if ($response->failed()) {
                throw new \Exception('TG API failed: '.$response->body());
            }
        } catch (\Exception $e) {
            Log::error('Telegram reminder failed', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            throw $e;
        }
    }

    private function sendMax(Appointment $appointment, Client $client): void
    {
        $text = $this->buildMessage($appointment, 'max');

        $extra = [];
        if ($this->type === '24h') {
            $extra['attachments'] = [[
                'type' => 'inline_keyboard',
                'payload' => [
                    'buttons' => [
                        [
                            [
                                'type' => 'callback',
                                'text' => __('bot.buttons.confirm_visit'),
                                'payload' => 'cv_'.$appointment->id,
                            ],
                        ],
                    ],
                ],
            ]];
        }

        $mid = app(MaxApiClient::class)->sendMessage($client->max_id, $text, $extra);
        if ($mid === null) {
            throw new \Exception('MAX API failed to send reminder');
        }
    }

    private function sendVk(Appointment $appointment, Client $client): void
    {
        $text = $this->buildMessage($appointment, 'vk');
        $peerId = (string) $client->vk_id;

        if ($this->type === '24h') {
            $keyboard = [
                'one_time' => false,
                'inline' => true,
                'buttons' => [[[
                    'action' => [
                        'type' => 'callback',
                        'label' => __('bot.buttons.confirm_visit'),
                        'payload' => json_encode(
                            ['command' => 'cv_'.$appointment->id],
                            JSON_UNESCAPED_UNICODE
                        ),
                    ],
                    'color' => 'positive',
                ]]],
            ];

            $mid = app(VkApiClient::class)->sendMessageWithKeyboard($peerId, $text, $keyboard);
        } else {
            $mid = app(VkApiClient::class)->sendMessage($peerId, $text);
        }

        if ($mid === null) {
            throw new \Exception('VK API failed to send reminder');
        }
    }

    private function buildMessage(Appointment $appointment, string $provider): string
    {
        $master = $appointment->master;
        $time = $appointment->start_time->timezone($master->getTimezone())->format('H:i');
        $address = $master->address ?? __('bot.fallback.address');
        $hours = $this->type === 'final'
            ? $appointment->master->getReminderHoursBeforeFinal()
            : 24;

        $key = $this->type === '24h' ? 'reminder_24h' : 'reminder_final';

        $template = __("notifications.{$key}", [
            'master' => $master->name,
            'service' => $appointment->display_name,
            'time' => $time,
            'address' => $address,
            'hours' => $hours,
        ]);

        return $template;
    }

    private function markAsSent(Appointment $appointment): void
    {
        $field = $this->type === '24h' ? 'reminder_24h_sent_at' : 'reminder_final_sent_at';
        $failedField = $this->type === '24h' ? 'reminder_24h_failed_at' : 'reminder_final_failed_at';

        $appointment->update([$field => now(), $failedField => null]);
    }

    private function markTerminalFailed(Appointment $appointment, string $reason): void
    {
        $failedField = $this->type === '24h' ? 'reminder_24h_failed_at' : 'reminder_final_failed_at';
        $sentField = $this->type === '24h' ? 'reminder_24h_sent_at' : 'reminder_final_sent_at';

        $update = [$failedField => now()];
        $appointment->update($update);

        Log::warning($reason, [
            'appointment_id' => $appointment->id,
            'reminder_type' => $this->type,
            'source' => $appointment->source?->value,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $appointment = $this->appointment->fresh();

        if ($appointment === null) {
            return;
        }

        // Поздний failed()-хук старой задачи не портит новую версию:
        // после переноса start_time изменился — не помечаем failed.
        if ($this->expectedStartTime !== ''
            && $appointment->start_time?->toIso8601String() !== $this->expectedStartTime) {
            return;
        }

        $sentField = $this->type === '24h' ? 'reminder_24h_sent_at' : 'reminder_final_sent_at';
        $failedField = $this->type === '24h' ? 'reminder_24h_failed_at' : 'reminder_final_failed_at';

        if ($appointment->{$sentField} !== null) {
            return;
        }

        $appointment->update([$failedField => now()]);

        Log::warning('Reminder job terminal failure', [
            'appointment_id' => $appointment->id,
            'reminder_type' => $this->type,
            'error' => $exception->getMessage(),
        ]);
    }
}
