<?php

namespace App\Jobs;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Client;
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

    public function __construct(
        private Appointment $appointment,
        private string $type,
    ) {}

    public function handle(): void
    {
        $appointment = $this->appointment->load(['master', 'service', 'client']);

        if (! $appointment->client) {
            return;
        }

        $client = $appointment->client;

        if ($client->telegram_id) {
            $this->sendTelegram($appointment, $client);
        }

        if ($client->max_id) {
            $this->sendMax($appointment, $client);
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
        }
    }

    private function sendMax(Appointment $appointment, Client $client): void
    {
        $text = $this->buildMessage($appointment, 'max');

        Log::info('Max reminder (stub)', [
            'max_id' => $client->max_id,
            'text' => $text,
            'appointment_id' => $appointment->id,
        ]);
    }

    private function buildMessage(Appointment $appointment, string $provider): string
    {
        $master = $appointment->master;
        $service = $appointment->service;
        $time = $appointment->start_time->format('H:i');
        $address = $master->address ?? 'не указан';
        $hours = $appointment->start_time->diffInHours(now());

        $key = $this->type === '24h' ? 'reminder_24h' : 'reminder_final';

        $template = __("notifications.{$key}.{$provider}", [
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
        $field = $this->type === '24h' ? 'reminder_24h_sent' : 'reminder_final_sent';

        $appointment->update([$field => true]);
    }
}
