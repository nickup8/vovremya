<?php

namespace App\Services\Notification;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MasterNotificationService
{
    public function sendNewAppointmentAlert(Appointment $appointment): void
    {
        $master = $appointment->master;
        $client = $appointment->client;
        $service = $appointment->service;

        $clientName = $client?->name ?? 'Клиент не указан';
        $serviceName = $service?->title ?? 'Услуга';
        $time = $appointment->start_time->format('d.m.Y H:i');

        $text = "🔔 Новая запись!\n\n"
            ."Клиент: {$clientName}\n"
            ."Услуга: {$serviceName}\n"
            ."Время: {$time}\n"
            .'Стоимость: '.($service?->price ?? 0).'₽';

        $this->sendToMaster($master, $text);
    }

    public function sendSubscriptionExpired(User $master): void
    {
        $text = "⚠️ Ваша подписка истекла.\n\n"
            ."Вы переведены на бесплатный тариф.\n"
            .'Обновите подписку для продолжения работы.';

        $this->sendToMaster($master, $text);
    }

    public function sendToMaster(User $master, string $text): void
    {
        if ($master->telegram_id) {
            $this->sendTelegram($master->telegram_id, $text);
        }

        if ($master->max_id) {
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
            Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]);
        } catch (\Exception $e) {
            Log::error('Telegram master notification failed', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendMax(string $chatId, string $text): void
    {
        $apiUrl = config('services.max.api_url');
        $token = config('services.max.bot_token');

        if (! $apiUrl || ! $token) {
            Log::info('Max master notification (stub)', [
                'chat_id' => $chatId,
                'text' => $text,
            ]);

            return;
        }

        try {
            Http::timeout(10)->post("{$apiUrl}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
            ]);
        } catch (\Exception $e) {
            Log::error('Max master notification failed', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
