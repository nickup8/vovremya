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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Напоминание клиенту о визите (24h / final).
 *
 * Идентичность поколения расписания: job несёт снимок
 * appointments.schedule_version на момент постановки (expectedScheduleVersion)
 * и сверяет его с текущей строкой ПЕРЕД отправкой; запись результата
 * (sent_at / failed_at) — условная по тому же поколению в транзакции под
 * блокировкой строки. Это отсекает перенос A→B→A (версия уже другая, хотя
 * start_time совпадает), смену получателя (клиент меняет версию) и поздние
 * success/failed старого исполнителя. Изменение цены версию не двигает.
 *
 * Совместимость старых payload'ов: оба поля имеют значения по умолчанию,
 * поэтому десериализация payload'а без них не падает. Политика для
 * старых payload'ов: без поколения и без снимка start_time отправка и
 * запись терминальных полей запрещены (нельзя доказать актуальность);
 * payload со снимком start_time (промежуточная ревизия) сверяет время.
 *
 * Окно отправки проверяется внутри job'а, а не только в scheduler'е:
 * визит не должен быть в прошлом и не дальше горизонта SEND_HORIZON_HOURS
 * (дополнительно к нижним границам типа 2ч для 24h).
 *
 * Ключ кэша-дедупликатора содержит поколение: ключ прошлого поколения
 * (12 часов TTL) не блокирует напоминание нового расписания, а дубль
 * того же поколения по-прежнему блокируется.
 *
 * Канал — общий AppointmentChannelResolver (тот же, что у уведомлений):
 * рабочий канал записи, иначе MAX → ВК. Telegram — только telegram-источник.
 */
class SendAppointmentReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** Горизонт «не раньше» в job'е: визит дальше — окно не наступило. */
    private const SEND_HORIZON_HOURS = 48;

    /** Снимок start_time на момент постановки (промежуточная ревизия payload'а). */
    public string $expectedStartTime = '';

    /**
     * Снимок appointments.schedule_version на момент постановки.
     * null = payload старой ревизии без поля — см. политику выше.
     */
    public ?int $expectedScheduleVersion = null;

    public function __construct(
        private Appointment $appointment,
        private string $type,
    ) {
        $this->expectedStartTime = $appointment->start_time->toIso8601String();
        $this->expectedScheduleVersion = (int) $appointment->schedule_version;
    }

    /**
     * @return array<int, int>
     */
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

        // Перечитываем статус: перенос/отмена/смена клиента до выполнения
        // job'а отменяют отправку.
        if (! in_array($fresh->status, [AppointmentStatus::Booked, AppointmentStatus::Prepaid], true)) {
            return;
        }

        // Генерация поколения: старое поколение (перенос, A→B→A, смена
        // получателя) и payload без верифицируемых снимков не отправляются.
        if (! $this->generationMatches($fresh)) {
            return;
        }

        if ($fresh->start_time->isPast()) {
            return;
        }

        // Окно отправки проверяется и внутри job'а, не только в scheduler'е:
        // визит дальше горизонта — напоминание ещё не наступило (защита и
        // для legacy-payload'ов, у которых проверка генерации слабее).
        $hoursUntil = (float) now()->diffInHours($fresh->start_time, false);
        if ($hoursUntil > self::SEND_HORIZON_HOURS) {
            return;
        }

        if ($this->type === '24h') {
            // Нижняя граница: «задним числом» (в т.ч. через 2ч после старта)
            // «завтра» уже бессмысленно.
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

        // Ключ дедупликатора содержит поколение расписания: после переноса
        // ключ прошлого поколения не блокирует новое напоминание, а дубль
        // текущего поколения блокируется.
        $generation = $this->expectedScheduleVersion ?? (int) $appointment->schedule_version;
        $lockKey = "reminder_{$this->type}_{$channel}_{$appointment->id}_v{$generation}";

        $sent = false;

        if ($channel === AppointmentChannelResolver::CHANNEL_TELEGRAM) {
            if (Cache::add($lockKey, true, now()->addHours(12))) {
                try {
                    $this->sendTelegram($appointment, $client);
                    $sent = true;
                } catch (Throwable $e) {
                    Cache::forget($lockKey);
                    throw $e;
                }
            }
        }

        if ($channel === AppointmentChannelResolver::CHANNEL_MAX) {
            if (Cache::add($lockKey, true, now()->addHours(12))) {
                try {
                    $this->sendMax($appointment, $client);
                    $sent = true;
                } catch (Throwable $e) {
                    Cache::forget($lockKey);
                    throw $e;
                }
            }
        }

        if ($channel === AppointmentChannelResolver::CHANNEL_VK) {
            if (Cache::add($lockKey, true, now()->addHours(12))) {
                try {
                    $this->sendVk($appointment, $client);
                    $sent = true;
                } catch (VkMessagesNotAllowedException) {
                    $client->update(['vk_messages_allowed' => false]);
                    $this->markTerminalFailed($appointment, 'VK messages not allowed (901)');

                    return;
                } catch (Throwable $e) {
                    Cache::forget($lockKey);
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

    /**
     * Сверка поколения расписания. Снимок поколения главнее снимка времени:
     * только время не различает A→B→A.
     */
    private function generationMatches(Appointment $fresh): bool
    {
        if ($this->expectedScheduleVersion !== null) {
            return (int) $fresh->schedule_version === $this->expectedScheduleVersion;
        }

        if ($this->expectedStartTime !== '') {
            // Payload промежуточной ревизии: сверяем снимок start_time.
            return $fresh->start_time->toIso8601String() === $this->expectedStartTime;
        }

        // Полностью старый payload: без поколения и без снимка времени
        // актуальность не доказуема — не отправляем и не пишем поля.
        return false;
    }

    /**
     * Условная запись состояния напоминания: только если поколение записи
     * всё ещё соответствует снимку job'а (поколение ушло — поздний
     * success/failed исполнителя старого поколения ничего не меняет).
     */
    private function writeReminderState(Appointment $appointment, callable $apply): void
    {
        DB::transaction(function () use ($appointment, $apply): void {
            $row = Appointment::query()
                ->whereKey($appointment->getKey())
                ->lockForUpdate()
                ->first();

            if ($row === null || ! $this->generationMatches($row)) {
                return;
            }

            $apply($row);
        });
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

        $this->writeReminderState($appointment, function (Appointment $row) use ($field, $failedField): void {
            $row->update([$field => now(), $failedField => null]);
        });
    }

    private function markTerminalFailed(Appointment $appointment, string $reason): void
    {
        $failedField = $this->type === '24h' ? 'reminder_24h_failed_at' : 'reminder_final_failed_at';

        $this->writeReminderState($appointment, function (Appointment $row) use ($failedField): void {
            $row->update([$failedField => now()]);
        });

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

        $sentField = $this->type === '24h' ? 'reminder_24h_sent_at' : 'reminder_final_sent_at';
        $failedField = $this->type === '24h' ? 'reminder_24h_failed_at' : 'reminder_final_failed_at';

        // Поздний failed()-хук не портит новую версию: запись условная по
        // поколению (payload без снимков — политика «не писать»), и sent_at
        // уже установленный не перекрывается.
        $this->writeReminderState($appointment, function (Appointment $row) use ($sentField, $failedField, $exception): void {
            if ($row->{$sentField} !== null) {
                return;
            }

            $row->update([$failedField => now()]);

            Log::warning('Reminder job terminal failure', [
                'appointment_id' => $row->id,
                'reminder_type' => $this->type,
                'error' => $exception->getMessage(),
            ]);
        });
    }
}
