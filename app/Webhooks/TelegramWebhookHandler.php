<?php

namespace App\Webhooks;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\User;
use App\Services\Notification\MasterNotificationService;
use DefStudio\Telegraph\Handlers\WebhookHandler;
use DefStudio\Telegraph\Keyboard\Button;
use DefStudio\Telegraph\Keyboard\Keyboard;
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
    private const BOOKING_DRAFT_PREFIX = 'booking_draft_';
    private const TOKEN_TTL = 300;
    private const DRAFT_TTL = 900;

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
                self::TOKEN_TTL,
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
            $this->handleBookingFlow($parameter, $chatId);

            return;
        }

        Log::info('[TG] start() unknown param');

        $this->chat->html('Неизвестная команда. Используйте кнопку на сайте для входа.')->send();
    }

    /**
     * Обработка флоу бронирования: /start book_{ID}
     *
     * 1. Ищет Appointment по ID
     * 2. Если клиент уже привязан (Client с telegram_id) — отправляет InlineKeyboard с подтверждением
     * 3. Если клиент новый — запрашивает контакт через ReplyKeyboard
     */
    private function handleBookingFlow(string $parameter, string $chatId): void
    {
        $appointmentId = str_replace('book_', '', $parameter);

        $appointment = Appointment::with(['master', 'service'])
            ->find($appointmentId);

        if (! $appointment) {
            Log::warning('[TG] book_ appointment not found', ['id' => $appointmentId]);
            $this->chat->html('Запись не найдена. Возможно, она уже была отменена.')->send();

            return;
        }

        $service = $appointment->service;
        $master = $appointment->master;

        $date = $appointment->start_time->format('d.m.Y');
        $time = $appointment->start_time->format('H:i');
        $serviceName = $service?->title ?? 'Услуга';
        $masterName = $master->name ?? 'Мастер';

        $details = "📋 **Детали записи:**\n\n"
            . "👤 Мастер: {$masterName}\n"
            . "💇 Услуга: {$serviceName}\n"
            . "📅 Дата: {$date}\n"
            . "⏰ Время: {$time}";

        // Проверяем, является ли пользователь постоянным клиентом этого мастера
        $client = Client::where('telegram_id', $chatId)
            ->where('user_id', $appointment->master_id)
            ->first();

        if ($client) {
            // Постоянный клиент — предлагаем подтверждение через Inline-кнопки
            $keyboard = Keyboard::make()
                ->row([
                    Button::make('✅ Подтвердить запись')
                        ->action('confirmBooking')
                        ->param('id', $appointment->id),
                ])
                ->row([
                    Button::make('❌ Отменить')
                        ->action('cancelBooking')
                        ->param('id', $appointment->id),
                ]);

            try {
                $this->chat->html($details)
                    ->keyboard($keyboard)
                    ->send();

                Log::info('[TG] book_ inline keyboard sent (returning client)', [
                    'appointment_id' => $appointmentId,
                    'client_id' => $client->id,
                    'chat_id' => $chatId,
                ]);
            } catch (Throwable $e) {
                Log::error('[TG] book_ inline keyboard FAILED', [
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            // Клиент новый — запрашиваем контакт
            Cache::put(self::BOOKING_DRAFT_PREFIX . $chatId, $appointmentId, self::DRAFT_TTL);

            $contactMessage = $details . "\n\n"
                . "Для завершения записи, пожалуйста, поделитесь номером телефона.\n\n"
                . "Нажмите кнопку ниже 👇";

            $keyboard = ReplyKeyboard::make()
                ->button('📱 Поделиться номером телефона')->requestContact()
                ->resize()
                ->oneTime();

            try {
                $this->chat->html($contactMessage)
                    ->replyKeyboard($keyboard)
                    ->send();

                Log::info('[TG] book_ contact requested', [
                    'appointment_id' => $appointmentId,
                    'chat_id' => $chatId,
                ]);
            } catch (Throwable $e) {
                Log::error('[TG] book_ contact request FAILED', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Обработка нажатия кнопки «✅ Подтвердить запись»
     */
    public function confirmBooking(): void
    {
        $appointmentId = $this->data->get('id');

        $appointment = Appointment::with(['service'])->find($appointmentId);

        if (! $appointment) {
            $this->reply('Запись не найдена. Возможно, она уже была отменена.');

            return;
        }

        $client = Client::where('telegram_id', $this->chat->chat_id)
            ->where('user_id', $appointment->master_id)
            ->first();

        if (! $client) {
            $this->reply('Клиент не найден. Пожалуйста, поделитесь номером телефона.');

            return;
        }

        $appointment->update([
            'client_id' => $client->id,
            'status' => AppointmentStatus::Booked,
        ]);

        $service = $appointment->service;
        $date = $appointment->start_time->format('d.m.Y');
        $time = $appointment->start_time->format('H:i');

        $phone = $client->phone ?? 'не указан';
        $clientName = $client->name ?? 'Клиент';

        $masterNotification = "🎉 У вас новая запись!\n\n"
            . "👤 Клиент: {$clientName} ({$phone})\n"
            . "💇‍♂️ Услуга: {$service?->title}\n"
            . "📅 Дата: {$date} в {$time}";

        app(MasterNotificationService::class)
            ->sendToMaster($appointment->master, $masterNotification);

        $confirmedText = "✅ Запись успешно подтверждена! Ждём вас.\n\n"
            . "💇 {$service?->title}\n"
            . "📅 {$date} в {$time}";

        try {
            $this->chat->edit($this->messageId)
                ->html($confirmedText)
                ->send();

            $this->chat->deleteKeyboard($this->messageId)->send();

            $this->reply('Запись подтверждена!');

            Log::info('[TG] confirmBooking: success', [
                'appointment_id' => $appointmentId,
                'client_id' => $client->id,
            ]);
        } catch (Throwable $e) {
            Log::error('[TG] confirmBooking: FAILED', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Обработка нажатия кнопки «❌ Отменить»
     */
    public function cancelBooking(): void
    {
        $appointmentId = $this->data->get('id');

        $appointment = Appointment::find($appointmentId);

        if (! $appointment) {
            $this->reply('Запись не найдена.');

            return;
        }

        $appointment->update([
            'status' => AppointmentStatus::Cancelled,
        ]);

        try {
            $this->chat->edit($this->messageId)
                ->html('❌ Вы отменили бронирование.')
                ->send();

            $this->chat->deleteKeyboard($this->messageId)->send();

            $this->reply('Запись отменена.');

            Log::info('[TG] cancelBooking: success', [
                'appointment_id' => $appointmentId,
            ]);
        } catch (Throwable $e) {
            Log::error('[TG] cancelBooking: FAILED', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Обработка контактов: определяет, это флоу авторизации или бронирования
     */
    protected function handleMessage(): void
    {
        $contact = $this->request->input('message.contact');

        Log::info('[TG] handleMessage()', [
            'has_contact' => $contact !== null,
            'text' => $this->request->input('message.text'),
            'chat_id' => $this->chat?->chat_id,
        ]);

        if ($contact) {
            $this->handleContact($contact);

            return;
        }

        parent::handleMessage();
    }

    /**
     * Единый обработчик контактов — определяет флоу по кэшу
     */
    private function handleContact(array $contact): void
    {
        $chatId = $this->chat->chat_id;

        // Проверяем флоу бронирования
        $draftAppointmentId = Cache::pull(self::BOOKING_DRAFT_PREFIX . $chatId);

        if ($draftAppointmentId) {
            $this->handleBookingContact($contact, $chatId, $draftAppointmentId);

            return;
        }

        // Проверяем флоу авторизации
        $loginToken = Cache::get(self::CHAT_TOKEN_PREFIX . $chatId);

        if ($loginToken) {
            $this->handleAuthContact($contact, $chatId, $loginToken);

            return;
        }

        Log::info('[TG] handleContact: no active flow', ['chat_id' => $chatId]);
    }

    /**
     * Обработка контакта в флоу бронирования
     */
    private function handleBookingContact(array $contact, string $chatId, string $appointmentId): void
    {
        $phone = preg_replace('/[^0-9]/', '', $contact['phone_number'] ?? '');
        $telegramId = (string) ($contact['user_id'] ?? $contact['from']['id'] ?? '');
        $firstName = $contact['first_name'] ?? '';
        $lastName = $contact['last_name'] ?? '';
        $fullName = trim($firstName . ' ' . $lastName);

        Log::info('[TG] handleBookingContact()', [
            'chat_id' => $chatId,
            'appointment_id' => $appointmentId,
            'phone' => $phone,
        ]);

        if (empty($phone)) {
            $this->chat->html('Не удалось определить номер телефона. Попробуйте снова.')->send();

            return;
        }

        $appointment = Appointment::find($appointmentId);

        if (! $appointment) {
            $this->chat->html('Запись не найдена. Попробуйте записаться заново.')->send();

            return;
        }

        $masterId = $appointment->master_id;

        // Ищем или создаём клиента
        $client = Client::where('user_id', $masterId)
            ->where('phone', $phone)
            ->first();

        if (! $client) {
            $client = Client::create([
                'user_id' => $masterId,
                'name' => $fullName ?: "Клиент {$phone}",
                'phone' => $phone,
                'telegram_id' => $telegramId,
                'auth_token' => Client::generateAuthToken(),
            ]);

            Log::info('[TG] handleBookingContact: client created', ['client_id' => $client->id]);
        } else {
            // Обновляем telegram_id если нужно
            if ($client->telegram_id !== $telegramId) {
                $client->update(['telegram_id' => $telegramId]);
            }

            Log::info('[TG] handleBookingContact: existing client', ['client_id' => $client->id]);
        }

        // Привязываем запись
        $appointment->update(['client_id' => $client->id]);

        // Уведомляем мастера
        $service = $appointment->service;
        $date = $appointment->start_time->format('d.m.Y');
        $time = $appointment->start_time->format('H:i');
        $phone = $client->phone ?? 'не указан';
        $clientName = $client->name ?? 'Клиент';

        $masterNotification = "🎉 У вас новая запись!\n\n"
            . "👤 Клиент: {$clientName} ({$phone})\n"
            . "💇‍♂️ Услуга: {$service?->title}\n"
            . "📅 Дата: {$date} в {$time}";

        app(MasterNotificationService::class)
            ->sendToMaster($appointment->master, $masterNotification);

        // Формируем подтверждение клиенту
        $message = "✅ **Запись подтверждена!**\n\n"
            . "💇 {$service->title}\n"
            . "📅 {$date} в {$time}\n\n"
            . "Ждём вас!}";

        try {
            $this->chat->html($message)
                ->removeReplyKeyboard()
                ->send();

            Log::info('[TG] handleBookingContact: confirmation sent', [
                'appointment_id' => $appointmentId,
                'client_id' => $client->id,
            ]);
        } catch (Throwable $e) {
            Log::error('[TG] handleBookingContact: confirmation FAILED', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Обработка контакта в флоу авторизации (существующая логика)
     */
    private function handleAuthContact(array $contact, string $chatId, string $loginToken): void
    {
        Log::info('[TG] handleAuthContact()', [
            'chat_id' => $chatId,
            'has_login_token' => true,
        ]);

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
        ], self::TOKEN_TTL);

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
