<?php

namespace App\Webhooks;

use App\Models\User;
use DefStudio\Telegraph\Handlers\WebhookHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;

class TelegramWebhookHandler extends WebhookHandler
{
    private const AUTH_CACHE_PREFIX = 'tg_auth:';
    private const CHAT_TOKEN_PREFIX = 'tg_chat_token:';
    private const CHAT_TOKEN_TTL = 300; // 5 минут

    /**
     * Обработка команды /start.
     *
     * Если параметр начинается с auth_ — запускаем флоу авторизации:
     * 1. Сохраняем связь chat_id → login_token в Cache
     * 2. Отправляем сообщение с кнопкой запроса контакта
     *
     * Если параметр начинается с book_ — флоу записи (существующая логика).
     */
    public function start(?string $parameter = null): void
    {
        $chatId = $this->chat->chat_id;

        Log::info('Telegram Bot /start', [
            'param' => $parameter,
            'chat_id' => $chatId,
        ]);

        if (empty($parameter)) {
            $this->chat->html(
                'Добро пожаловать! Для входа в личный кабинет нажмите кнопку «Войти через Telegram» на сайте.'
            )->send();

            return;
        }

        // ─── Флоу авторизации через Deep Linking ───
        if (str_starts_with($parameter, 'auth_')) {
            $loginToken = $parameter;

            // Сохраняем связь chat_id → login_token (нужно для обработки контакта)
            Cache::put(
                self::CHAT_TOKEN_PREFIX . $chatId,
                $loginToken,
                self::CHAT_TOKEN_TTL,
            );

            // Отправляем сообщение с ReplyKeyboard для запроса контакта
            $keyboard = [
                'keyboard' => [
                    [
                        [
                            'text' => '📱 Поделиться номером телефона',
                            'request_contact' => true,
                        ],
                    ],
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ];

            $this->chat->html(
                "Для завершения авторизации, пожалуйста, поделитесь номером телефона.\n\n"
                . "Нажмите кнопку ниже 👇"
            )->keyboard($keyboard)->send();

            return;
        }

        // ─── Флоу записи ( book_{appointment_id} ) ───
        if (str_starts_with($parameter, 'book_')) {
            // Передаём в WebhookController — здесь не обрабатываем
            // (этот webhook может не получать book_ если WebhookController — отдельный endpoint)
            $this->chat->html('Запись обрабатывается...')->send();

            return;
        }

        $this->chat->html('Неизвестная команда. Используйте кнопку на сайте для входа.')->send();
    }

    /**
     * Перехват сообщений для обработки контактов.
     *
     * Переопределяем handleMessage() чтобы проверить наличие контакта
     * ДО стандартной обработки текстовых команд.
     */
    protected function handleMessage(): void
    {
        // Проверяем, не является ли сообщение контактом
        $contact = $this->request->input('message.contact');
        if ($contact) {
            $this->handleAuthContact($contact);
            return;
        }

        parent::handleMessage();
    }

    /**
     * Обработка полученного контакта при флоу авторизации.
     *
     * 1. Достаём login_token для данного chat_id из Cache
     * 2. Находим или создаём пользователя по телефону
     * 3. Обновляем статус токена на authenticated
     * 4. Отправляем подтверждение
     */
    private function handleAuthContact(array $contact): void
    {
        $chatId = $this->chat->chat_id;

        // Проверяем, есть ли активный auth-флоу для этого chat_id
        $loginToken = Cache::get(self::CHAT_TOKEN_PREFIX . $chatId);

        if (! $loginToken) {
            // Это может быть контакт из флоу записи — пропускаем
            Log::info('Telegram auth: получен контакт без активного флоу', [
                'chat_id' => $chatId,
            ]);

            return;
        }

        // Извлекаем данные из контакта
        $phone = preg_replace('/[^0-9]/', '', $contact['phone_number'] ?? '');
        $telegramId = (string) ($contact['user_id'] ?? $contact['from']['id'] ?? '');
        $firstName = $contact['first_name'] ?? '';
        $lastName = $contact['last_name'] ?? '';

        if (empty($phone)) {
            $this->chat->html('Не удалось определить номер телефона. Попробуйте снова.')->send();

            return;
        }

        // Ищем существующего пользователя по телефону
        $user = User::where('phone', $phone)->first();

        if (! $user) {
            // Создаём нового мастера
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

            Log::info('Telegram auth: создан новый мастер', [
                'user_id' => $user->id,
                'phone' => $phone,
                'telegram_id' => $telegramId,
            ]);
        } else {
            // Обновляем telegram_id и имя, если нужно
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

            Log::info('Telegram auth: вход существующего пользователя', [
                'user_id' => $user->id,
                'phone' => $phone,
            ]);
        }

        // Обновляем статус токена на authenticated
        $authCacheKey = self::AUTH_CACHE_PREFIX . $loginToken;
        Cache::put($authCacheKey, [
            'status' => 'authenticated',
            'user_id' => $user->id,
        ], self::CHAT_TOKEN_TTL);

        // Удаляем связь chat_id → token (одноразовая)
        Cache::forget(self::CHAT_TOKEN_PREFIX . $chatId);

        // Убираем ReplyKeyboard и отправляем подтверждение
        $this->chat->html(
            '✅ Успешная авторизация! Возвращайтесь в браузер.'
        )->removeKeyboard()->send();

        Log::info('Telegram auth: авторизация завершена', [
            'user_id' => $user->id,
            'chat_id' => $chatId,
        ]);
    }

    /**
     * Обработка обычных текстовых сообщений.
     */
    protected function handleChatMessage(Stringable $text): void
    {
        Log::info('Telegram Chat Message', ['text' => $text->toString()]);
        $this->reply('Используйте кнопку на сайте для входа в личный кабинет.');
    }

    /**
     * Обработка неизвестных команд.
     */
    protected function handleUnknownCommand(Stringable $text): void
    {
        Log::info('Telegram Unknown Command', ['cmd' => $text->toString()]);
        $this->reply('Неизвестная команда. Используйте кнопку на сайте для входа.');
    }
}
