<?php

namespace App\Services\Notification;

use App\Models\Appointment;
use App\Models\User;
use App\Services\MaxApiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MasterNotificationService
{
    public function sendNewAppointmentAlert(Appointment $appointment): void
    {
        $master = $appointment->master;
        $client = $appointment->client;
        $service = $appointment->service;

        $clientName = $client?->name ?? __('bot.fallback.client_name');
        $serviceName = $service?->title ?? __('bot.fallback.service_name');
        $time = $appointment->start_time->format('d.m.Y H:i');

        $text = __('bot.master.new_booking', [
            'client' => $clientName,
            'phone' => $client->phone ?? __('bot.fallback.phone'),
            'service' => $serviceName,
            'date' => $appointment->start_time->format('d.m.Y'),
            'time' => $appointment->start_time->format('H:i'),
        ]);

        $this->sendToMaster($master, $text);
    }

    public function sendSubscriptionExpired(User $master): void
    {
        $text = __('bot.master.subscription_expired');

        $this->sendToMaster($master, $text);
    }

    public function sendToMaster(User $master, string $text): void
    {
        if (! empty($master->telegram_id) && $master->telegram_notifications === true) {
            $this->sendTelegram($master->telegram_id, $text);
        }

        if (! empty($master->max_id) && $master->max_notifications === true) {
            $this->sendMax($master->max_id, $text);
        }
    }

    private function sendTelegram(string $chatId, string $text): void
    {
        $token = config('services.telegram.bot_token');

        if (empty($token)) {
            return;
        }

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]);

            if ($response->failed()) {
                throw new \Exception('TG API failed: ' . $response->body());
            }
        } catch (\Exception $e) {
            Log::error('Telegram master notification failed', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            throw $e;
        }
    }

    private function sendMax(string $chatId, string $text): void
    {
        if (! app(MaxApiClient::class)->sendMessage($chatId, $text)) {
            throw new \Exception('MAX API failed to send master notification');
        }
    }
}
