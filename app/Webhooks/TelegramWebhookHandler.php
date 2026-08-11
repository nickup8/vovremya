<?php

namespace App\Webhooks;

use App\Constants\CacheKeys;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Exceptions\PastAppointmentException;
use App\Events\AppointmentCreated;
use App\Events\UserChannelsUpdated;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\User;
use App\Services\AppointmentStatusService;
use App\Services\Notification\MasterNotificationService;
use App\Services\SlugService;
use DefStudio\Telegraph\Handlers\WebhookHandler;
use DefStudio\Telegraph\Keyboard\Button;
use DefStudio\Telegraph\Keyboard\Keyboard;
use DefStudio\Telegraph\Keyboard\ReplyKeyboard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Throwable;

class TelegramWebhookHandler extends WebhookHandler
{
    public function __construct(
        private AppointmentStatusService $statusService,
    ) {
        parent::__construct();
    }

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
                __('bot.welcome')
            )->send();

            Log::info('[TG] start() welcome sent', ['ok' => $result !== null]);

            return;
        }

        if (str_starts_with($parameter, 'auth_')) {
            $loginToken = $parameter;

            Cache::put(
                CacheKeys::TG_CHAT_TOKEN.$chatId,
                $loginToken,
                config('booking.token_ttl'),
            );

            Log::info('[TG] start(auth_) cache stored', ['login_token' => $loginToken]);

            // Проверка: мастер уже дал согласие актуальной версии?
            $existingMaster = User::where('telegram_id', $chatId)->first();
            $consentOk = $existingMaster
                && $existingMaster->pdn_consent_version === config('legal.version');

            if (! $consentOk) {
                $this->sendConsentBarrier('auth');

                return;
            }

            $message = __('bot.contact_request.auth');

            $keyboard = ReplyKeyboard::make()
                ->button(__('bot.buttons.share_phone'))->requestContact()
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

        if (str_starts_with($parameter, 'link_')) {
            $linkToken = $parameter;
            $userId = Cache::pull("tg_link:{$linkToken}");

            if ($userId) {
                $user = User::find($userId);
                if ($user) {
                    $user->update([
                        'telegram_id' => $chatId,
                        'telegram_notifications' => true,
                    ]);

                    broadcast(new UserChannelsUpdated($user));

                    $this->chat->html(__('bot.notifications.linked_success'))->send();

                    Log::info('[TG] link_ binding completed', [
                        'user_id' => $user->id,
                        'chat_id' => $chatId,
                    ]);
                }
            } else {
                $this->chat->html(__('bot.notifications.link_expired'))->send();
            }

            return;
        }

        // Handle bare telegram_auth_token (no prefix) for legacy binding
        $user = User::where('telegram_auth_token', $parameter)->first();
        if ($user) {
            $user->update([
                'telegram_id' => $chatId,
                'telegram_chat_id' => $chatId,
                'telegram_notifications' => true,
            ]);

            broadcast(new UserChannelsUpdated($user));

            $this->chat->html(__('bot.notifications.linked_success'))->send();

            Log::info('[TG] auth_token binding completed', [
                'user_id' => $user->id,
                'chat_id' => $chatId,
            ]);

            return;
        }

        if (str_starts_with($parameter, 'book_')) {
            $this->handleBookingFlow($parameter, $chatId);

            return;
        }

        Log::info('[TG] start() unknown param');

        $this->chat->html(__('bot.errors.unknown_command'))->send();
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

        $appointment = Appointment::with(['master'])
            ->find($appointmentId);

        if (! $appointment) {
            Log::warning('[TG] book_ appointment not found', ['id' => $appointmentId]);
            $this->chat->html(__('bot.errors.appointment_not_found'))->send();

            return;
        }

        $master = $appointment->master;
        $tz = $master->getTimezone();

        $date = $appointment->start_time->timezone($tz)->format('d.m.Y');
        $time = $appointment->start_time->timezone($tz)->format('H:i');
        $serviceName = $appointment->display_name;
        $masterName = $master->name ?? __('bot.fallback.master_name');

        $details = __('bot.booking_details', [
            'master' => $masterName,
            'service' => $serviceName,
            'date' => $date,
            'time' => $time,
        ]);

        // Проверяем, является ли пользователь постоянным клиентом этого мастера
        $client = Client::byTelegramId($chatId)
            ->where('user_id', $appointment->master_id)
            ->first();

        if ($client) {
            $this->syncClientTelegramAvatar($client, $chatId);

            // Постоянный клиент — предлагаем подтверждение через Inline-кнопки
            $keyboard = Keyboard::make()
                ->row([
                    Button::make(__('bot.buttons.confirm_booking'))
                        ->action('confirmBooking')
                        ->param('id', $appointment->id),
                ])
                ->row([
                    Button::make(__('bot.buttons.cancel'))
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
                    'exception' => $e,
                ]);
            }
        } else {
            // Клиент новый — запрашиваем контакт
            Cache::put(CacheKeys::TG_BOOKING_DRAFT.$chatId, $appointmentId, config('booking.draft_ttl'));

            // Проверка: клиент уже дал согласие актуальной версии?
            $consentOk = $client // тут $client === null (ветка нового), проверяем повторный визит по telegram_id
                ? false
                : (bool) Client::byTelegramId($chatId)
                    ->where('user_id', $appointment->master_id)
                    ->where('pdn_consent_version', config('legal.version'))
                    ->exists();

            if (! $consentOk) {
                $this->sendConsentBarrier('book');

                return;
            }

            $contactMessage = $details."\n\n"
                .__('bot.contact_request.booking');

            $keyboard = ReplyKeyboard::make()
                ->button(__('bot.buttons.share_phone'))->requestContact()
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

        $appointment = Appointment::with(['master'])->find($appointmentId);

        if (! $appointment) {
            $this->reply(__('bot.errors.appointment_not_found'));

            return;
        }

        // Атомарная блокировка: если уже обработано — просто закрываем клавиатуру
        $lockKey = 'master_notified_'.$appointment->id;

        if (! Cache::add($lockKey, true, now()->addMinutes(10))) {
            $this->chat->deleteKeyboard($this->messageId)->send();

            return;
        }

        $client = Client::byTelegramId($this->chat->chat_id)
            ->where('user_id', $appointment->master_id)
            ->first();

        if (! $client) {
            $this->reply(__('bot.errors.client_not_found'));

            return;
        }

        $this->syncClientTelegramAvatar($client, $this->chat->chat_id);

        $appointment->update([
            'client_id' => $client->id,
            'source' => AppointmentSource::Telegram,
        ]);

        $this->statusService->transition($appointment, AppointmentStatus::Booked);

        broadcast(new AppointmentCreated(
            $appointment->load(['client'])
        ));

        $tz = $appointment->master->getTimezone();
        $date = $appointment->start_time->timezone($tz)->format('d.m.Y');
        $time = $appointment->start_time->timezone($tz)->format('H:i');

        $confirmedText = __('bot.booking_confirmed', [
            'service' => $appointment->display_name,
            'date' => $date,
            'time' => $time,
            'price' => $appointment->display_price,
        ]);

        if ($appointment->master->address) {
            $confirmedText .= __('bot.booking_confirmed_address', ['address' => $appointment->master->address]);
        }

        $confirmedText .= __('bot.booking_confirmed_suffix');

        try {
            $this->chat->edit($this->messageId)
                ->html($confirmedText)
                ->send();

            $this->chat->deleteKeyboard($this->messageId)->send();

            $this->reply($confirmedText);

            // Уведомляем мастера
            $phone = $client->phone ?? __('bot.fallback.phone');
            $clientName = $client->name ?? __('bot.fallback.client_name');

            app(MasterNotificationService::class)
                ->sendToMaster($appointment->master, __('bot.master.new_booking', [
                    'client' => $clientName,
                    'phone' => $phone,
                    'service' => $appointment->display_name,
                    'date' => $date,
                    'time' => $time,
                ]));

            Log::info('[TG] confirmBooking: success', [
                'appointment_id' => $appointmentId,
                'client_id' => $client->id,
            ]);
        } catch (Throwable $e) {
            Log::error('[TG] confirmBooking: FAILED', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Рисует экран согласия ПДн с inline-кнопками.
     * $flow: 'book' (клиент) | 'auth' (мастер).
     */
    private function sendConsentBarrier(string $flow): void
    {
        $text = $flow === 'auth'
            ? __('bot.consent.master_text')
            : __('bot.consent.client_text');

        $keyboard = Keyboard::make()
            ->row([
                Button::make(__('bot.consent.button_accept'))
                    ->action('acceptConsent')
                    ->param('flow', $flow),
            ])
            ->row([
                Button::make(__('bot.consent.button_offer'))
                    ->url(config('legal.offer_url')),
                Button::make(__('bot.consent.button_privacy'))
                    ->url(config('legal.privacy_url')),
            ]);

        try {
            $this->chat->html($text)
                ->keyboard($keyboard)
                ->send();

            Log::info('[TG] consent barrier sent', ['flow' => $flow, 'chat_id' => $this->chat->chat_id]);
        } catch (\Throwable $e) {
            Log::error('[TG] consent barrier FAILED', ['error' => $e->getMessage(), 'flow' => $flow]);
        }
    }

    /**
     * Callback кнопки «Принимаю». Пишет факт согласия в кэш и ведёт к запросу контакта.
     */
    public function acceptConsent(): void
    {
        $chatId = $this->chat->chat_id;
        $flow = $this->data->get('flow');

        // Кладём версию согласия в кэш (перенесётся в БД при получении контакта).
        Cache::put(
            CacheKeys::TG_CONSENT_PENDING.$chatId,
            config('legal.version'),
            config('booking.draft_ttl'),
        );

        Log::info('[TG] consent accepted', ['flow' => $flow, 'chat_id' => $chatId]);

        if ($flow === 'auth') {
            $message = __('bot.consent.after_accept_master');
        } else {
            $message = __('bot.consent.after_accept_client');
        }

        $keyboard = ReplyKeyboard::make()
            ->button(__('bot.buttons.share_phone'))->requestContact()
            ->resize()
            ->oneTime();

        try {
            $this->chat->html($message)
                ->replyKeyboard($keyboard)
                ->send();
        } catch (\Throwable $e) {
            Log::error('[TG] acceptConsent send FAILED', ['error' => $e->getMessage(), 'flow' => $flow]);
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
            $this->reply(__('bot.errors.appointment_not_found'));

            return;
        }

        $client = Client::byTelegramId($this->chat->chat_id)->first();

        if (! $client || $appointment->client_id !== $client->id) {
            Log::warning('[TG] cancelBooking: ownership violation', [
                'appointment_id' => $appointmentId,
                'chat_id' => $this->chat?->chat_id,
                'appointment_client_id' => $appointment->client_id,
                'resolved_client_id' => $client?->id,
            ]);

            $this->reply(__('bot.errors.appointment_not_found'));

            return;
        }

        if ($appointment->status === AppointmentStatus::Cancelled) {
            $this->reply(__('bot.booking_cancelled.reply'));

            return;
        }

        try {
            $this->statusService->transition($appointment, AppointmentStatus::Cancelled, $client);
        } catch (PastAppointmentException) {
            $this->reply(__('Нельзя отменить прошедшую запись'));

            return;
        } catch (InvalidStatusTransitionException $e) {
            Log::warning('[TG] cancelBooking: invalid transition', [
                'appointment_id' => $appointmentId,
                'status' => $appointment->status,
                'error' => $e->getMessage(),
            ]);
            $this->reply(__('bot.errors.appointment_not_found'));

            return;
        }

        try {
            $this->chat->edit($this->messageId)
                ->html(__('bot.booking_cancelled.edit_message'))
                ->send();

            $this->chat->deleteKeyboard($this->messageId)->send();

            $this->reply(__('bot.booking_cancelled.reply'));

            Log::info('[TG] cancelBooking: success', [
                'appointment_id' => $appointmentId,
                'client_id' => $client->id,
            ]);
        } catch (Throwable $e) {
            Log::error('[TG] cancelBooking: FAILED', [
                'error' => $e->getMessage(),
                'exception' => $e,
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
        $draftAppointmentId = Cache::pull(CacheKeys::TG_BOOKING_DRAFT.$chatId);

        if ($draftAppointmentId) {
            $this->handleBookingContact($contact, $chatId, $draftAppointmentId);

            return;
        }

        // Проверяем флоу авторизации
        $loginToken = Cache::pull(CacheKeys::TG_CHAT_TOKEN.$chatId);

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
        $fullName = trim($firstName.' '.$lastName);

        Log::info('[TG] handleBookingContact()', [
            'chat_id' => $chatId,
            'appointment_id' => $appointmentId,
            'phone' => $phone,
        ]);

        if (empty($phone)) {
            $this->chat->html(__('bot.errors.phone_detection_failed'))->send();

            return;
        }

        $appointment = Appointment::with(['master'])->find($appointmentId);

        if (! $appointment) {
            $this->chat->html(__('bot.errors.appointment_not_found_retry'))->send();

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
                'workspace_id' => $appointment->master?->workspace_id,
                'name' => $fullName ?: __('bot.fallback.client_name')." {$phone}",
                'phone' => $phone,
                'telegram_id' => $telegramId,
                'auth_token' => Client::generateAuthToken(),
                'pdn_consent_at' => now(),
                'pdn_consent_version' => Cache::pull(CacheKeys::TG_CONSENT_PENDING.$chatId) ?? config('legal.version'),
            ]);

            Log::info('[TG] handleBookingContact: client created', ['client_id' => $client->id]);
        } else {
            $clientUpdates = [];
            if ($client->telegram_id !== $telegramId) {
                $clientUpdates['telegram_id'] = $telegramId;
            }
            $pendingConsent = Cache::pull(CacheKeys::TG_CONSENT_PENDING.$chatId);
            if ($pendingConsent && $client->pdn_consent_version !== $pendingConsent) {
                $clientUpdates['pdn_consent_at'] = now();
                $clientUpdates['pdn_consent_version'] = $pendingConsent;
            }
            if ($clientUpdates) {
                $client->update($clientUpdates);
            }

            Log::info('[TG] handleBookingContact: existing client', ['client_id' => $client->id]);
        }

        $this->syncClientTelegramAvatar($client, $telegramId);

        // Проверяем блокировку клиента
        if ($client->isBlocked()) {
            $appointment->delete();
            $this->chat->html(__('bot.errors.booking_unavailable'))->send();

            return;
        }

        // Привязываем запись
        $appointment->update(['client_id' => $client->id, 'source' => AppointmentSource::Telegram]);

        broadcast(new AppointmentCreated(
            $appointment->load(['client'])
        ));

        // Атомарная блокировка: если уже обработано — выходим
        $lockKey = 'master_notified_'.$appointment->id;

        if (! Cache::add($lockKey, true, now()->addMinutes(10))) {
            return;
        }

        // Уведомляем мастера
        $phone = $client->phone ?? __('bot.fallback.phone');
        $clientName = $client->name ?? __('bot.fallback.client_name');
        $tz = $appointment->master->getTimezone();
        $date = $appointment->start_time->timezone($tz)->format('d.m.Y');
        $time = $appointment->start_time->timezone($tz)->format('H:i');

        $masterNotification = __('bot.master.new_booking', [
            'client' => $clientName,
            'phone' => $phone,
            'service' => $appointment->display_name,
            'date' => $date,
            'time' => $time,
        ]);

        app(MasterNotificationService::class)
            ->sendToMaster($appointment->master, $masterNotification);

        // Формируем подтверждение клиенту
        // Если запись была создана через MAX — не отправляем подтверждение в Telegram
        if ($appointment->source === AppointmentSource::Max) {
            Log::info('[TG] handleBookingContact: skipped — booking originated from MAX', [
                'appointment_id' => $appointmentId,
            ]);

            return;
        }

        $message = __('bot.booking_confirmed', [
            'service' => $appointment->display_name,
            'date' => $date,
            'time' => $time,
            'price' => $appointment->display_price,
        ]);

        if ($appointment->master->address) {
            $message .= __('bot.booking_confirmed_address', ['address' => $appointment->master->address]);
        }

        $message .= __('bot.booking_confirmed_suffix');

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
                'exception' => $e,
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

            $this->chat->html(__('bot.errors.phone_detection_failed'))->send();

            return;
        }

        $user = User::where('telegram_id', $telegramId)->first();

        if (! $user) {
            $user = User::where('phone', $phone)->first();

            if ($user && $user->telegram_id && $user->telegram_id !== $telegramId) {
                $this->chat->html('❌ Этот номер телефона уже привязан к другому Telegram-аккаунту.')->send();

                return;
            }
        }

        if (! $user) {
            $baseName = trim($firstName.' '.$lastName);
            if ($baseName === '') {
                $baseName = __('bot.fallback.master_name').' '.$phone;
            }

            $username = $this->request->input('message.from.username');
            $slug = app(SlugService::class)->generate($username, $firstName, $lastName);

            $user = User::create([
                'name' => $baseName,
                'phone' => $phone,
                'telegram_id' => $telegramId,
                'telegram_notifications' => true,
                'is_master' => true,
                'master_slug' => $slug,
                'specialty' => null,
                'address' => null,
                'pdn_consent_at' => now(),
                'pdn_consent_version' => Cache::pull(CacheKeys::TG_CONSENT_PENDING.$chatId) ?? config('legal.version'),
            ]);

            if (! $user->workspace_id) {
                app(\App\Services\WorkspaceService::class)->createForUser($user);
                $user->refresh();
            }

            Log::info('[TG] handleAuthContact: user created', ['user_id' => $user->id]);
        } else {
            $updates = [];

            if ($user->telegram_id !== $telegramId) {
                $updates['telegram_id'] = $telegramId;
            }

            if (! $user->telegram_notifications) {
                $updates['telegram_notifications'] = true;
            }

            $fullName = trim($firstName.' '.$lastName);
            if ($fullName !== '' && $user->name !== $fullName) {
                $updates['name'] = $fullName;
            }

            $pendingConsent = Cache::pull(CacheKeys::TG_CONSENT_PENDING.$chatId);
            if ($pendingConsent && $user->pdn_consent_version !== $pendingConsent) {
                $updates['pdn_consent_at'] = now();
                $updates['pdn_consent_version'] = $pendingConsent;
            }

            if (! empty($updates)) {
                $user->update($updates);
            }

            Log::info('[TG] handleAuthContact: existing user', ['user_id' => $user->id]);
        }

        $this->syncTelegramAvatar($user, $telegramId);

        broadcast(new UserChannelsUpdated($user));

        $authCacheKey = CacheKeys::TG_AUTH.$loginToken;
        Cache::put($authCacheKey, [
            'status' => 'authenticated',
            'user_id' => $user->id,
        ], config('booking.token_ttl'));

        Cache::forget(CacheKeys::TG_CHAT_TOKEN.$chatId);

        Log::info('[TG] handleAuthContact: sending confirmation');

        try {
            $magicToken = Str::random(32);
            Cache::put('magic_login_'.$magicToken, $user->id, now()->addMinutes(15));
            $magicUrl = route('auth.magic', ['token' => $magicToken]);

            $keyboard = Keyboard::make()
                ->row([
                    Button::make('Открыть кабинет 🚀')->url($magicUrl),
                ]);

            $this->chat->html('✅ <b>Авторизация пройдена!</b>')
                ->removeReplyKeyboard()
                ->keyboard($keyboard)
                ->send();

            Log::info('[TG] handleAuthContact: confirmation sent', ['ok' => true]);
        } catch (Throwable $e) {
            Log::error('[TG] handleAuthContact: confirmation FAILED', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    protected function handleChatMessage(Stringable $text): void
    {
        Log::info('[TG] handleChatMessage', ['text' => $text->toString()]);
        $this->reply(__('bot.errors.use_site_button'));
    }

    protected function handleUnknownCommand(Stringable $text): void
    {
        Log::info('[TG] handleUnknownCommand', ['cmd' => $text->toString()]);
        $this->reply(__('bot.errors.unknown_command'));
    }

    /**
     * Скачивает профильное фото из Telegram и сохраняет как аватар мастера.
     * Вызывается только если у мастера ещё нет фото.
     */
    private function syncTelegramAvatar(User $master, string $telegramId): void
    {
        if ($master->avatar_url) {
            return;
        }

        try {
            $token = config('services.telegram.bot_token');

            if (empty($token)) {
                return;
            }

            $photosResponse = Http::timeout(10)->get("https://api.telegram.org/bot{$token}/getUserProfilePhotos", [
                'user_id' => $telegramId,
                'limit' => 1,
            ]);

            if (! $photosResponse->ok() || $photosResponse->json('result.total_count', 0) === 0) {
                return;
            }

            $photosArray = $photosResponse->json('result.photos');
            $photos = $photosArray[0] ?? [];

            if (empty($photos)) {
                return;
            }

            $fileId = $photos[array_key_last($photos)]['file_id'];

            $fileResponse = Http::timeout(10)->get("https://api.telegram.org/bot{$token}/getFile", [
                'file_id' => $fileId,
            ]);

            if (! $fileResponse->ok()) {
                return;
            }

            $filePath = $fileResponse->json('result.file_path');

            $content = Http::timeout(15)
                ->get("https://api.telegram.org/file/bot{$token}/{$filePath}");

            if ($content->failed()) {
                Log::warning('[TG] syncTelegramAvatar: file download failed', [
                    'user_id' => $master->id,
                    'status' => $content->status(),
                ]);

                return;
            }

            $body = $content->body();

            if (empty($body)) {
                return;
            }

            $filename = "tg_avatar_{$telegramId}_".time().'.jpg';
            Storage::disk('public')->put("avatars/{$filename}", $body);

            $master->update(['avatar_url' => "/storage/avatars/{$filename}"]);

            Log::info('[TG] syncTelegramAvatar: saved', [
                'user_id' => $master->id,
                'filename' => $filename,
            ]);
        } catch (Throwable $e) {
            Log::warning('[TG] syncTelegramAvatar: failed', [
                'user_id' => $master->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Скачивает профильное фото клиента из Telegram.
     */
    private function syncClientTelegramAvatar(Client $client, string $telegramId): void
    {
        if ($client->avatar_url) {
            return;
        }

        try {
            $token = config('services.telegram.bot_token');

            if (empty($token)) {
                return;
            }

            $photosResponse = Http::timeout(10)->get("https://api.telegram.org/bot{$token}/getUserProfilePhotos", [
                'user_id' => $telegramId,
                'limit' => 1,
            ]);

            if (! $photosResponse->ok() || $photosResponse->json('result.total_count', 0) === 0) {
                return;
            }

            $photosArray = $photosResponse->json('result.photos');
            $photos = $photosArray[0] ?? [];

            if (empty($photos)) {
                return;
            }

            $fileId = $photos[array_key_last($photos)]['file_id'];

            $fileResponse = Http::timeout(10)->get("https://api.telegram.org/bot{$token}/getFile", [
                'file_id' => $fileId,
            ]);

            if (! $fileResponse->ok()) {
                return;
            }

            $filePath = $fileResponse->json('result.file_path');

            $content = Http::timeout(15)
                ->get("https://api.telegram.org/file/bot{$token}/{$filePath}");

            if ($content->failed()) {
                Log::warning('[TG] syncClientTelegramAvatar: file download failed', [
                    'client_id' => $client->id,
                    'status' => $content->status(),
                ]);

                return;
            }

            $body = $content->body();

            if (empty($body)) {
                return;
            }

            $filename = "tg_avatar_client_{$telegramId}_".time().'.jpg';
            Storage::disk('public')->put("avatars/clients/{$filename}", $body);

            $client->update(['avatar_url' => "/storage/avatars/clients/{$filename}"]);

            Log::info('[TG] syncClientTelegramAvatar: saved', [
                'client_id' => $client->id,
                'filename' => $filename,
            ]);
        } catch (Throwable $e) {
            Log::warning('[TG] syncClientTelegramAvatar: failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
