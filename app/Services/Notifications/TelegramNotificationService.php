<?php

namespace App\Services\Notifications;

use App\Models\User;
use DefStudio\Telegraph\Facades\Telegraph;
use Illuminate\Support\Facades\Log;

class TelegramNotificationService
{
    /**
     * Отправить текстовое сообщение пользователю в Telegram.
     *
     * Метод проверяет наличие chat_id у пользователя и настроек уведомлений.
     * Если условия не выполнены — сообщение отправляется в лог, а не в Telegram.
     */
    public function sendMessage(User $user, string $message): bool
    {
        if (! $this->shouldNotify($user)) {
            Log::info('Telegram: пропуск отправки — пользователь не настроен', [
                'user_id' => $user->id,
                'has_chat_id' => (bool) $user->telegram_chat_id,
                'notifications_enabled' => (bool) $user->telegram_notifications,
            ]);

            return false;
        }

        try {
            Telegram::chat($user->telegram_chat_id)->message($message)->send();

            Log::info('Telegram: сообщение отправлено', [
                'user_id' => $user->id,
                'chat_id' => $user->telegram_chat_id,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Telegram: ошибка отправки сообщения', [
                'user_id' => $user->id,
                'chat_id' => $user->telegram_chat_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Проверка — стоит ли отправлять уведомление данному пользователю.
     */
    private function shouldNotify(User $user): bool
    {
        return $user->telegram_chat_id !== null && $user->telegram_notifications === true;
    }
}
