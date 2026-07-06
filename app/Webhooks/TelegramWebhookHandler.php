<?php

namespace App\Webhooks;

use App\Models\User;
use DefStudio\Telegraph\Handlers\WebhookHandler;
use DefStudio\Telegraph\Models\TelegraphChat;

class TelegramWebhookHandler extends WebhookHandler
{
    /**
     * Обработка команды /start для привязки аккаунта мастера.
     */
    public function start(?string $token = null): void
    {
        // Если токен не передан, отправляем инструкцию
        if (empty($token)) {
            $this->chat->html('Пожалуйста, используйте специальную кнопку в настройках профиля на сайте для привязки аккаунта.')->send();

            return;
        }

        // Ищем пользователя по токену
        $user = User::where('telegram_auth_token', $token)->first();

        if ($user) {
            // Привязываем Telegram chat_id к пользователю
            $user->update([
                'telegram_chat_id' => $this->chat->chat_id,
                'telegram_auth_token' => null,
            ]);

            // Отправляем сообщение об успешной привязке
            $this->chat->html('✅ Аккаунт успешно привязан!')->send();
        } else {
            // Отправляем сообщение об ошибке
            $this->chat->html('❌ Ошибка: ссылка устарела или недействительна. Попробуйте сгенерировать новую в настройках профиля.')->send();
        }
    }
}