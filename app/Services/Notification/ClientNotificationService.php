<?php

namespace App\Services\Notification;

use App\Enums\AppointmentSource;
use App\Models\Appointment;
use App\Services\MaxApiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClientNotificationService
{
    /**
     * Отправить клиенту уведомление в ОДИН канал — по источнику записи.
     * telegram → TG, max → MAX, null → фолбэк (TG если есть, иначе MAX).
     * Зеркалит логику выбора канала из SendAppointmentReminderJob.
     */
    public function sendToClientBySource(Appointment $appointment, string $text): void
    {
        $client = $appointment->client;

        if (! $client) {
            return;
        }

        $source = $appointment->source instanceof AppointmentSource
            ? $appointment->source->value
            : $appointment->source;

        $hasTelegram = ! empty($client->telegram_id);
        $hasMax = ! empty($client->max_id);

        // Telegram: source == telegram, либо (null-фолбэк и есть telegram_id)
        if ($hasTelegram && ($source === 'telegram' || empty($source))) {
            $this->sendTelegram($client->telegram_id, $text);

            return;
        }

        // MAX: source == max, либо (null-фолбэк без telegram_id)
        if ($hasMax && ($source === 'max' || empty($source))) {
            $this->sendMax($client->max_id, $text);

            return;
        }

        // source == admin или клиент не привязан к боту — уведомить некуда, тихо выходим.
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
                throw new \Exception('TG API failed: '.$response->body());
            }
        } catch (\Exception $e) {
            Log::error('Telegram client notification failed', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function sendMax(string $chatId, string $text): void
    {
        if (! app(MaxApiClient::class)->sendMessage($chatId, $text)) {
            throw new \Exception('MAX API failed to send client notification');
        }
    }
}
