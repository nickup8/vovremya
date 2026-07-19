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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendAppointmentReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

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

        $appointment = $this->appointment->load(['master', 'service', 'client']);

        if (! $appointment->client) {
            return;
        }

        $client = $appointment->client;
        $source = $appointment->source;
        $sourceValue = $source instanceof \App\Enums\AppointmentSource ? $source->value : $source;

        if ($client->telegram_id && ($sourceValue === 'telegram' || ! $source)) {
            $lockTg = "reminder_{$this->type}_tg_{$appointment->id}";
            if (\Illuminate\Support\Facades\Cache::add($lockTg, true, now()->addHours(12))) {
                try {
                    $this->sendTelegram($appointment, $client);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Cache::forget($lockTg);
                    throw $e;
                }
            }
        }

        if ($client->max_id && ($sourceValue === 'max' || (! $source && ! $client->telegram_id))) {
            $lockMax = "reminder_{$this->type}_max_{$appointment->id}";
            if (\Illuminate\Support\Facades\Cache::add($lockMax, true, now()->addHours(12))) {
                try {
                    $this->sendMax($appointment, $client);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Cache::forget($lockMax);
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
            Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $client->telegram_id,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]);
        } catch (\Exception $e) {
            Log::error('Telegram reminder failed', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
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
        $service = $appointment->service;
        $time = $appointment->start_time->timezone($master->getTimezone())->format('H:i');
        $address = $master->address ?? __('bot.fallback.address');
        $hours = $this->type === 'final'
            ? $appointment->master->getReminderHoursBeforeFinal()
            : 24;

        $key = $this->type === '24h' ? 'reminder_24h' : 'reminder_final';

        $template = __("notifications.{$key}", [
            'master' => $master->name,
            'service' => $service->title,
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
