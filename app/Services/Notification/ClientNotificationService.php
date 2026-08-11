<?php

namespace App\Services\Notification;

use App\Models\Client;
use App\Services\MaxApiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClientNotificationService
{
    public function sendToClient(Client $client, string $text): void
    {
        if (! empty($client->telegram_id)) {
            $this->sendTelegram($client->telegram_id, $text);
        }

        if (! empty($client->max_id)) {
            $this->sendMax($client->max_id, $text);
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
