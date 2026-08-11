<?php

namespace App\Jobs;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Services\MaxApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendAppointmentReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function __construct(
        private Appointment $appointment,
        private string $type,
    ) {}

    public function handle(): void
    {
        if ($this->appointment->fresh()->status !== AppointmentStatus::Booked) {
            return;
        }

        $appointment = $this->appointment->load(['master', 'client']);

        if (! $appointment->client) {
            return;
        }

        $client = $appointment->client;
        $source = $appointment->source;
        $sourceValue = $source instanceof AppointmentSource ? $source->value : $source;

        if ($client->telegram_id && ($sourceValue === 'telegram' || ! $source)) {
            $lockTg = "reminder_{$this->type}_tg_{$appointment->id}";
            if (Cache::add($lockTg, true, now()->addHours(12))) {
                try {
                    $this->sendTelegram($appointment, $client);
                } catch (\Throwable $e) {
                    Cache::forget($lockTg);
                    throw $e;
                }
            }
        }

        if ($client->max_id && ($sourceValue === 'max' || (! $source && ! $client->telegram_id))) {
            $lockMax = "reminder_{$this->type}_max_{$appointment->id}";
            if (Cache::add($lockMax, true, now()->addHours(12))) {
                try {
                    $this->sendMax($appointment, $client);
                } catch (\Throwable $e) {
                    Cache::forget($lockMax);
                    throw $e;
                }
            }
        }

        $this->markAsSent($appointment);
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
                $button = \DefStudio\Telegraph\Keyboard\Button::make(__('bot.buttons.confirm_visit'))
                    ->action('confirmVisit')
                    ->param('id', $appointment->id)
                    ->toArray();

                $payload['reply_markup'] = [
                    'inline_keyboard' => [[$button]],
                ];
            }

            $response = Http::retry(3, 500, function ($exception) {
                return $exception instanceof \Illuminate\Http\Client\ConnectionException;
            }, throw: false)
                ->connectTimeout(5)
                ->timeout(15)
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

        if (! app(MaxApiClient::class)->sendMessage($client->max_id, $text)) {
            throw new \Exception('MAX API failed to send reminder');
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

        $appointment->update([$field => now()]);
    }
}
