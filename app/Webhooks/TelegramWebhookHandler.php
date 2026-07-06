<?php

namespace App\Webhooks;

use App\Models\User;
use DefStudio\Telegraph\Handlers\WebhookHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Stringable;

class TelegramWebhookHandler extends WebhookHandler
{
    /**
     * Обработка команды /start для привязки аккаунта мастера.
     */
    public function start(?string $parameter = null): void
    {
        Log::info('Telegram Bot /start hit', ['param' => $parameter, 'chat_id' => $this->chat?->chat_id]);

        // Если токен не передан, отправляем инструкцию
        if (empty($parameter)) {
            $this->chat->html('Пожалуйста, используйте специальную кнопку в настройках профиля на сайте для привязки аккаунта.')->send();

            return;
        }

        // Ищем пользователя по токену
        $user = User::where('telegram_auth_token', $parameter)->first();

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

    /**
     * Обработка обычных текстовых сообщений (эхо-режим для отладки).
     */
    protected function handleChatMessage(Stringable $text): void
    {
        Log::info('Telegram Chat Message', ['text' => $text->toString()]);
        $this->reply('Я получил текст: ' . $text->toString());
    }

    /**
     * Обработка неизвестных команд.
     */
    protected function handleUnknownCommand(Stringable $text): void
    {
        Log::info('Telegram Unknown Command', ['cmd' => $text->toString()]);
        $this->reply('Неизвестная команда: ' . $text->toString());
    }
}