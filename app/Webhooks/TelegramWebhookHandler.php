<?php

namespace App\Webhooks;

use App\Models\User;
use DefStudio\Telegraph\Handlers\WebhookHandler;
use DefStudio\Telegraph\Keyboard\ReplyKeyboard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Throwable;

class TelegramWebhookHandler extends WebhookHandler
{
    private const AUTH_CACHE_PREFIX = 'tg_auth:';
    private const CHAT_TOKEN_PREFIX = 'tg_chat_token:';
    private const CHAT_TOKEN_TTL = 300;

    public function start(?string $parameter = null): void
    {
        $chatId = $this->chat->chat_id;

        Log::info('[TG] start() entered', [
            'chat_id' => $chatId,
            'parameter' => $parameter,
            'bot_id' => $this->bot->id,
        ]);

        if (empty($parameter)) {
            Log::info('[TG] start() sending welcome');

            $result = $this->chat->html(
                'Добро пожаловать! Для входа в личный кабинет нажмите кнопку «Войти через Telegram» на сайте.'
            )->send();

            Log::info('[TG] start() welcome sent', ['ok' => $result !== null]);

            return;
        }

        if (str_starts_with($parameter, 'auth_')) {
            $loginToken = $parameter;

            Cache::put(
                self::CHAT_TOKEN_PREFIX . $chatId,
                $loginToken,
                self::CHAT_TOKEN_TTL,
            );

            Log::info('[TG] start(auth_) cache stored', ['login_token' => $loginToken]);

            $message = "Для завершения авторизации, пожалуйста, поделитесь номером телефона.\n\n"
                . "Нажмите кнопку ниже 👇";

            $keyboard = ReplyKeyboard::make()
                ->button('📱 Поделиться номером телефона')->requestContact()
                ->resize()
                ->oneTime();

            try {
                Log::info('[TG] start(auth_) calling replyKeyboard()->send()');

                $result = $this->chat->html($message)
                    ->replyKeyboard($keyboard)
                    ->send();

                Log::info('[TG] start(auth_) send OK', ['ok' => true]);
            } catch (Throwable $e) {
                Log::error('[TG] start(auth_) send FAILED', [
                    'error' => $e->getMessage(),
                    'class' => get_class($e),
                ]);
            }

            return;
        }

        if (str_starts_with($parameter, 'book_')) {
            Log::info('[TG] start(book_) sending placeholder');

            $result = $this->chat->html('Запись обрабатывается...')->send();

            Log::info('[TG] start(book_) sent', ['ok' => $result !== null]);

            return;
        }

        Log::info('[TG] start() unknown param');

        $this->chat->html('Неизвестная команда. Используйте кнопку на сайте для входа.')->send();
    }

    protected function handleMessage(): void
    {
        $contact = $this->request->input('message.contact');

        Log::info('[TG] handleMessage()', [
            'has_contact' => $contact !== null,
            'text' => $this->request->input('message.text'),
            'chat_id' => $this->chat?->chat_id,
        ]);

        if ($contact) {
            Log::info('[TG] handleMessage() → handleAuthContact');
            $this->handleAuthContact($contact);

            return;
        }

        parent::handleMessage();
    }

    private function handleAuthContact(array $contact): void
    {
        $chatId = $this->chat->chat_id;

        $loginToken = Cache::get(self::CHAT_TOKEN_PREFIX . $chatId);

        Log::info('[TG] handleAuthContact()', [
            'chat_id' => $chatId,
            'has_login_token' => $loginToken !== null,
        ]);

        if (! $loginToken) {
            Log::info('[TG] handleAuthContact: no active flow, skipping');

            return;
        }

        $phone = preg_replace('/[^0-9]/', '', $contact['phone_number'] ?? '');
        $telegramId = (string) ($contact['user_id'] ?? $contact['from']['id'] ?? '');
        $firstName = $contact['first_name'] ?? '';
        $lastName = $contact['last_name'] ?? '';

        if (empty($phone)) {
            Log::warning('[TG] handleAuthContact: empty phone');

            $this->chat->html('Не удалось определить номер телефона. Попробуйте снова.')->send();

            return;
        }

        $user = User::where('phone', $phone)->first();

        if (! $user) {
            $baseName = trim($firstName . ' ' . $lastName);
            if ($baseName === '') {
                $baseName = 'Мастер ' . $phone;
            }

            $slug = Str::slug($baseName);
            $originalSlug = $slug;

            $counter = 1;
            while (User::where('master_slug', $slug)->exists()) {
                $slug = $originalSlug . '-' . $counter;
                $counter++;
            }

            $user = User::create([
                'name' => $baseName,
                'phone' => $phone,
                'telegram_id' => $telegramId,
                'is_master' => true,
                'master_slug' => $slug,
                'specialty' => null,
                'address' => null,
            ]);

            Log::info('[TG] handleAuthContact: user created', ['user_id' => $user->id]);
        } else {
            $updates = [];

            if ($user->telegram_id !== $telegramId) {
                $updates['telegram_id'] = $telegramId;
            }

            $fullName = trim($firstName . ' ' . $lastName);
            if ($fullName !== '' && $user->name !== $fullName) {
                $updates['name'] = $fullName;
            }

            if (! empty($updates)) {
                $user->update($updates);
            }

            Log::info('[TG] handleAuthContact: existing user', ['user_id' => $user->id]);
        }

        $authCacheKey = self::AUTH_CACHE_PREFIX . $loginToken;
        Cache::put($authCacheKey, [
            'status' => 'authenticated',
            'user_id' => $user->id,
        ], self::CHAT_TOKEN_TTL);

        Cache::forget(self::CHAT_TOKEN_PREFIX . $chatId);

        Log::info('[TG] handleAuthContact: sending confirmation');

        try {
            $result = $this->chat->html(
                '✅ Успешная авторизация! Возвращайтесь в браузер.'
            )->removeReplyKeyboard()->send();

            Log::info('[TG] handleAuthContact: confirmation sent', ['ok' => true]);
        } catch (Throwable $e) {
            Log::error('[TG] handleAuthContact: confirmation FAILED', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function handleChatMessage(Stringable $text): void
    {
        Log::info('[TG] handleChatMessage', ['text' => $text->toString()]);
        $this->reply('Используйте кнопку на сайте для входа в личный кабинет.');
    }

    protected function handleUnknownCommand(Stringable $text): void
    {
        Log::info('[TG] handleUnknownCommand', ['cmd' => $text->toString()]);
        $this->reply('Неизвестная команда. Используйте кнопку на сайте для входа.');
    }
}
