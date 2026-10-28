<?php

namespace App\Services\Notification;

use App\Exceptions\VkMessagesNotAllowedException;
use App\Models\Appointment;
use App\Models\Client;
use App\Services\MaxApiClient;
use App\Services\VkApiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Синхронная отправка текста клиенту (один канал).
 *
 * Канал выбирается общим AppointmentChannelResolver — тот же, что у
 * напоминаний и outbox-уведомлений. Не использует глобальный
 * Client::where(vk_id)->first(): работает только с карточкой клиента
 * самой записи.
 *
 * Для клиентских уведомлений о создании/отмене/переносе/правках и
 * widget-подтверждений предпочтительный путь — outbox
 * (AppointmentNotificationRecorder); этот сервис оставлен для
 * точечных синхронных сценариев (если остались) и Telegram.
 */
class ClientNotificationService
{
    public function __construct(
        private AppointmentChannelResolver $resolver = new AppointmentChannelResolver,
    ) {}

    /**
     * Отправить клиенту уведомление в ОДИН канал — по правилу resolver'а.
     */
    public function sendToClientBySource(Appointment $appointment, string $text): void
    {
        $client = $appointment->client;

        if (! $client) {
            return;
        }

        $channel = $this->resolver->resolve($appointment);

        match ($channel) {
            AppointmentChannelResolver::CHANNEL_TELEGRAM => $this->sendTelegram((string) $client->telegram_id, $text),
            AppointmentChannelResolver::CHANNEL_MAX => $this->sendMax((string) $client->max_id, $text),
            AppointmentChannelResolver::CHANNEL_VK => $this->sendVk($appointment, $client, $text),
            default => null, // нет доступного канала — явно ничего не шлём
        };
    }

    private function sendTelegram(string $chatId, string $text): void
    {
        $token = config('services.telegram.bot_token');

        if (empty($token)) {
            return;
        }

        try {
            $response = Http::connectTimeout(3)
                ->timeout(20)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
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

    private function sendVk(Appointment $appointment, \App\Models\Client $client, string $text): void
    {
        try {
            app(VkApiClient::class)->sendMessage((string) $client->vk_id, $text);
        } catch (VkMessagesNotAllowedException) {
            $client->update(['vk_messages_allowed' => false]);
        }
    }
}
