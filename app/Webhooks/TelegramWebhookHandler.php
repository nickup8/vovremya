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
use Illuminate\Database\Eloquent\Model;
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

            $this->chat->html('Управление записями:')
                ->keyboard(Keyboard::make()->row([
                    Button::make('📋 Мои записи')->action('myBookings'),
                ]))
                ->send();

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

            // Устаревшее/отсутствующее согласие — показываем барьер ДО подтверждения
            if ($this->needsPdnConsent($client)) {
                Cache::put(CacheKeys::TG_BOOKING_DRAFT.$chatId, $appointment->id, config('booking.draft_ttl'));
                $this->sendConsentBarrier('book');

                return;
            }

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
            // Клиент новый — сохраняем черновик и показываем барьер согласия
            Cache::put(CacheKeys::TG_BOOKING_DRAFT.$chatId, $appointmentId, config('booking.draft_ttl'));

            // Новый клиент по telegram_id — актуального согласия заведомо нет
            if ($this->needsPdnConsent(null)) {
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

        // Последний рубеж: без актуального согласия ПДн не финализируем — показываем барьер.
        if ($this->needsPdnConsent($client)) {
            Cache::forget($lockKey); // снимаем блокировку, чтобы после согласия можно было подтвердить
            Cache::put(CacheKeys::TG_BOOKING_DRAFT.$this->chat->chat_id, $appointment->id, config('booking.draft_ttl'));
            $this->sendConsentBarrier('book');

            return;
        }

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
     * Callback кнопки «✅ Подтверждаю» из напоминания за 24ч.
     * Пишет client_confirmed_at, уведомляет мастера, обновляет календарь в реалтайме.
     */
    public function confirmVisit(): void
    {
        $appointmentId = $this->data->get('id');

        $appointment = Appointment::with(['master', 'client'])->find($appointmentId);

        if (! $appointment) {
            $this->reply(__('bot.errors.appointment_not_found'));

            return;
        }

        // Владелец записи — клиент этого мастера по telegram_id
        $client = Client::byTelegramId($this->chat->chat_id)
            ->where('user_id', $appointment->master_id)
            ->first();

        if (! $client || $appointment->client_id !== $client->id) {
            Log::warning('[TG] confirmVisit: ownership violation', [
                'appointment_id' => $appointmentId,
                'chat_id' => $this->chat?->chat_id,
            ]);
            $this->reply(__('bot.errors.appointment_not_found'));

            return;
        }

        // Подтверждать можно только активную (Booked) запись
        if ($appointment->status !== AppointmentStatus::Booked) {
            $this->reply(__('bot.visit_confirm.not_available'));

            return;
        }

        // Защита от повторного нажатия
        if ($appointment->client_confirmed_at !== null) {
            $this->reply(__('bot.visit_confirm.already'));

            return;
        }

        $appointment->update(['client_confirmed_at' => now()]);

        Log::info('[TG] confirmVisit: success', [
            'appointment_id' => $appointmentId,
            'client_id' => $client->id,
        ]);

        // Real-time обновление календаря мастера
        broadcast(new \App\Events\AppointmentVisitConfirmed(
            $appointment->fresh()->load(['client'])
        ));

        // Обновляем исходное сообщение (убираем кнопку) + отвечаем клиенту
        try {
            $this->chat->deleteKeyboard($this->messageId)->send();
        } catch (Throwable $e) {
            Log::error('[TG] confirmVisit: deleteKeyboard FAILED', ['error' => $e->getMessage()]);
        }

        $this->reply(__('bot.visit_confirm.client_thanks'));

        // Уведомляем мастера
        $tz = $appointment->master->getTimezone();
        $date = $appointment->start_time->timezone($tz)->format('d.m.Y');
        $time = $appointment->start_time->timezone($tz)->format('H:i');

        app(MasterNotificationService::class)
            ->sendToMaster($appointment->master, __('bot.master.visit_confirmed', [
                'client' => $client->name ?? __('bot.fallback.client_name'),
                'date' => $date,
                'time' => $time,
            ]));
    }

    /**
     * Требуется ли барьер согласия ПДн:
     * нет согласия ИЛИ версия устарела относительно config('legal.version').
     */
    private function needsPdnConsent(?Model $subject): bool
    {
        return $subject === null
            || empty($subject->pdn_consent_at)
            || $subject->pdn_consent_version !== config('legal.version');
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

        // Кладём версию согласия в кэш (перенесётся в БД при получении контакта — для нового клиента/мастера).
        Cache::put(
            CacheKeys::TG_CONSENT_PENDING.$chatId,
            config('legal.version'),
            config('booking.draft_ttl'),
        );

        Log::info('[TG] consent accepted', ['flow' => $flow, 'chat_id' => $chatId]);

        // Поток мастера (auth) — как прежде: просим контакт.
        if ($flow === 'auth') {
            $keyboard = ReplyKeyboard::make()
                ->button(__('bot.buttons.share_phone'))->requestContact()
                ->resize()
                ->oneTime();

            try {
                $this->chat->html(__('bot.consent.after_accept_master'))
                    ->replyKeyboard($keyboard)
                    ->send();
            } catch (\Throwable $e) {
                Log::error('[TG] acceptConsent send FAILED', ['error' => $e->getMessage(), 'flow' => $flow]);
            }

            return;
        }

        // Поток записи (book): проверяем — существующий ли это клиент (контакт уже есть).
        $appointmentId = Cache::get(CacheKeys::TG_BOOKING_DRAFT.$chatId);
        $appointment = $appointmentId ? Appointment::find($appointmentId) : null;

        $client = $appointment
            ? Client::byTelegramId($chatId)->where('user_id', $appointment->master_id)->first()
            : null;

        if ($client) {
            // Вариант 1 (мягкий): контакт уже есть — записываем согласие в БД,
            // повторно телефон НЕ просим, показываем кнопку «Подтвердить».
            $client->update([
                'pdn_consent_at' => now(),
                'pdn_consent_version' => Cache::pull(CacheKeys::TG_CONSENT_PENDING.$chatId) ?? config('legal.version'),
            ]);

            Log::info('[TG] consent accepted → existing client, showing confirm button', [
                'client_id' => $client->id,
                'appointment_id' => $appointmentId,
            ]);

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
                $this->chat->html(__('bot.consent.after_accept_client_returning'))
                    ->keyboard($keyboard)
                    ->send();
            } catch (\Throwable $e) {
                Log::error('[TG] acceptConsent confirm button FAILED', ['error' => $e->getMessage()]);
            }

            return;
        }

        // Новый клиент — записи в БД ещё нет, просим контакт (согласие перенесётся при создании Client).
        $keyboard = ReplyKeyboard::make()
            ->button(__('bot.buttons.share_phone'))->requestContact()
            ->resize()
            ->oneTime();

        try {
            $this->chat->html(__('bot.consent.after_accept_client'))
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
     * Кнопка «Мои записи»: показать будущие активные записи клиента.
     */
    public function myBookings(): void
    {
        Log::info('[TG] myBookings: entered', ['chat_id' => $this->chat->chat_id]);

        $client = Client::byTelegramId($this->chat->chat_id)->first();

        Log::info('[TG] myBookings: client lookup', ['found' => (bool) $client, 'client_id' => $client?->id]);

        if (! $client) {
            $this->chat->html('У вас пока нет предстоящих записей.')->send();

            return;
        }

        $appointments = Appointment::with(['master'])
            ->where('client_id', $client->id)
            ->where('status', AppointmentStatus::Booked)
            ->where('start_time', '>', now())
            ->orderBy('start_time')
            ->get();

        Log::info('[TG] myBookings: appointments', ['count' => $appointments->count()]);

        if ($appointments->isEmpty()) {
            $this->chat->html('У вас пока нет предстоящих записей.')->send();

            return;
        }

        foreach ($appointments as $appointment) {
            try {
                $tz = $appointment->master?->getTimezone();
                $when = $appointment->start_time->timezone($tz ?? config('app.timezone'))->format('d.m.Y H:i');

                $masterName = $appointment->master?->name ?? __('bot.fallback.master_name');

                $text = "📅 <b>{$appointment->display_name}</b>\n"
                    . "👤 Мастер: {$masterName}\n"
                    . "🕒 {$when}";

                if ($appointment->display_price) {
                    $text .= "\n💰 " . number_format((float) $appointment->display_price, 0, '.', ' ') . " ₽";
                }

                if (! empty($appointment->master?->address)) {
                    $text .= "\n📍 " . $appointment->master->address;
                }

                $chat = $this->chat;
                $apptId = $appointment->id;
                $this->sendWithRetry(fn () => $chat->html($text)
                    ->keyboard(Keyboard::make()->row([
                        Button::make('❌ Отменить')
                            ->action('confirmCancel')
                            ->param('id', $apptId),
                    ]))
                    ->send());

                Log::info('[TG] myBookings: item sent', ['appointment_id' => $appointment->id]);
            } catch (\Throwable $e) {
                Log::error('[TG] myBookings: item FAILED', [
                    'appointment_id' => $appointment->id,
                    'error' => $e->getMessage(),
                    'class' => get_class($e),
                ]);
            }
        }
    }

    private function sendWithRetry(callable $send, int $tries = 4): void
    {
        $delays = [300000, 600000, 1200000]; // мкс

        for ($i = 0; $i < $tries; $i++) {
            try {
                $response = $send();

                if ($response instanceof \DefStudio\Telegraph\Client\TelegraphResponse
                    && ! $response->telegraphOk()) {
                    throw new \Exception(
                        'Telegram API error: ' . ($response->json('description') ?? 'unknown')
                    );
                }

                return;
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                if ($i === $tries - 1) {
                    throw $e;
                }

                usleep($delays[$i] ?? 1200000);
            }
        }
    }

    /**
     * Шаг подтверждения отмены: «Точно отменить?» да/нет.
     */
    public function confirmCancel(): void
    {
        $appointmentId = $this->data->get('id');

        $appointment = Appointment::with(['master'])->find($appointmentId);
        $client = Client::byTelegramId($this->chat->chat_id)->first();

        if (! $appointment || ! $client || $appointment->client_id !== $client->id) {
            $this->reply(__('bot.errors.appointment_not_found'));

            return;
        }

        if ($appointment->status !== AppointmentStatus::Booked || $appointment->start_time <= now()) {
            $this->reply('Эту запись уже нельзя отменить.');

            return;
        }

        $tz = $appointment->master->getTimezone();
        $when = $appointment->start_time->timezone($tz)->format('d.m.Y H:i');

        $this->chat->html("Точно отменить запись на <b>{$when}</b>?")
            ->keyboard(Keyboard::make()->row([
                Button::make('✅ Да, отменить')
                    ->action('doCancel')
                    ->param('id', $appointment->id),
                Button::make('↩️ Нет')
                    ->action('myBookings'),
            ]))
            ->send();
    }

    /**
     * Реальная отмена записи клиентом: проверка владельца, дедлайна мастера,
     * переход в Cancelled + уведомление мастеру.
     */
    public function doCancel(): void
    {
        $appointmentId = $this->data->get('id');

        $appointment = Appointment::with(['master'])->find($appointmentId);
        $client = Client::byTelegramId($this->chat->chat_id)->first();

        if (! $appointment || ! $client || $appointment->client_id !== $client->id) {
            Log::warning('[TG] cancelAppointment: ownership violation', [
                'appointment_id' => $appointmentId,
                'chat_id' => $this->chat?->chat_id,
            ]);
            $this->reply(__('bot.errors.appointment_not_found'));

            return;
        }

        if ($appointment->status !== AppointmentStatus::Booked || $appointment->start_time <= now()) {
            $this->reply('Эту запись уже нельзя отменить.');

            return;
        }

        // Дедлайн отмены, заданный мастером (часы). null/0 = без ограничения.
        $deadlineHours = $appointment->master->cancellation_deadline_hours;

        if ($deadlineHours !== null && $deadlineHours > 0) {
            $limit = $appointment->start_time->copy()->subHours($deadlineHours);

            if (now() >= $limit) {
                $master = $appointment->master;
                $contact = 'Пожалуйста, свяжитесь с мастером напрямую.';

                if ($master && ! empty($master->phone)) {
                    $masterName = e($master->name);

                    $digits = preg_replace('/\D+/', '', (string) $master->phone);

                    // Нормализация российских номеров: 8XXXXXXXXXX -> 7XXXXXXXXXX
                    if (strlen($digits) === 11 && $digits[0] === '8') {
                        $digits = '7'.substr($digits, 1);
                    }

                    // Красивое отображение для 11-значного российского номера: +7 920 041-25-41
                    if (strlen($digits) === 11) {
                        $display = '+'.$digits[0].' '
                            .substr($digits, 1, 3).' '
                            .substr($digits, 4, 3).'-'
                            .substr($digits, 7, 2).'-'
                            .substr($digits, 9, 2);
                    } else {
                        $display = '+'.$digits;
                    }
                    $displaySafe = e($display);

                    $contact = "Пожалуйста, свяжитесь с мастером напрямую:\n{$masterName} — <code>{$displaySafe}</code>";
                }

                $this->chat->html(
                    "Отменить запись онлайн можно не позднее чем за {$deadlineHours} ч до визита. "
                    .$contact
                )->send();

                return;
            }
        }

        // Отмена — тем же способом, что и cancelBooking (актор = клиент).
        try {
            $this->statusService->transition($appointment, AppointmentStatus::Cancelled, $client);
        } catch (PastAppointmentException) {
            $this->reply('Нельзя отменить прошедшую запись.');

            return;
        } catch (InvalidStatusTransitionException $e) {
            Log::warning('[TG] cancelAppointment: invalid transition', [
                'appointment_id' => $appointmentId,
                'status' => $appointment->status,
                'error' => $e->getMessage(),
            ]);
            $this->reply(__('bot.errors.appointment_not_found'));

            return;
        }

        // Уведомление мастеру об отмене клиентом.
        $tz = $appointment->master->getTimezone();
        $when = $appointment->start_time->timezone($tz)->format('d.m.Y H:i');

        app(MasterNotificationService::class)->sendToMaster(
            $appointment->master,
            "❌ Клиент отменил запись:\n💇 {$appointment->display_name}\n🕒 {$when}"
        );

        // Обновляем сообщение + отвечаем клиенту.
        try {
            $this->chat->edit($this->messageId)
                ->html('✅ Запись отменена.')
                ->send();

            $this->chat->deleteKeyboard($this->messageId)->send();

            $this->reply('Запись отменена.');

            Log::info('[TG] cancelAppointment: success', [
                'appointment_id' => $appointmentId,
                'client_id' => $client->id,
            ]);
        } catch (Throwable $e) {
            Log::error('[TG] cancelAppointment: FAILED', [
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
