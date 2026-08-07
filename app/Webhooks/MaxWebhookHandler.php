<?php

namespace App\Webhooks;

use App\Constants\CacheKeys;
use App\Enums\AppointmentSource;
use App\Enums\UserRole;
use App\Events\AppointmentCreated;
use App\Events\UserChannelsUpdated;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvite;
use App\Services\Client\ClientMergeService;
use App\Services\MaxApiClient;
use App\Services\Notification\MasterNotificationService;
use App\Services\SlugService;
use App\Services\WorkspaceService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MaxWebhookHandler
{
    public function __construct(
        private ClientMergeService $clientMergeService,
        private MaxApiClient $maxApi,
    ) {}

    /**
     * Process incoming MAX webhook event.
     *
     * MAX webhook payload structure for message_created:
     * {
     *   "update_type": "message_created",
     *   "timestamp": 1771409719000,
     *   "message": {
     *     "sender": { "user_id": 67890, "name": "..." },
     *     "recipient": { "chat_id": 12345 },
     *     "body": { "mid": "...", "text": "...", "attachments": [...] }
     *   }
     * }
     */
    public function handle(array $payload): void
    {
        $updateType = $payload['update_type'] ?? null;

        if ($updateType === 'message_created') {
            $chatId = (string) ($payload['message']['recipient']['chat_id'] ?? $payload['chat_id'] ?? '');
            $userId = (string) ($payload['message']['sender']['user_id'] ?? $payload['user']['user_id'] ?? '');
        } else {
            $chatId = (string) ($payload['chat_id'] ?? '');
            $userId = (string) ($payload['user']['user_id'] ?? '');
        }

        Log::info('[MAX] webhook received', [
            'update_type' => $updateType,
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);

        if (empty($userId)) {
            Log::warning('[MAX] missing user_id', ['payload' => $payload]);

            return;
        }

        try {
            match ($updateType) {
                'bot_started' => $this->handleBotStarted($payload, $userId),
                'message_created' => $this->handleMessageCreated($payload, $userId),
                'message_callback' => $this->handleCallback($payload, $userId),
                default => Log::info('[MAX] unhandled update_type', ['type' => $updateType]),
            };
        } catch (\Throwable $e) {
            Log::error('[MAX] webhook handler failed', [
                'update_type' => $updateType,
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Handle bot_started event — user tapped "Start" in MAX.
     *
     * MAX sends this when:
     * - User first opens the bot
     * - User taps "Start" after stopping the bot
     *
     * The start parameter is in payload.start_param (if provided via deep link).
     */
    private function handleBotStarted(array $payload, string $userId): void
    {
        $startParam = $payload['payload'] ?? $payload['start_param'] ?? $payload['data']['start_param'] ?? '';

        Log::info('[MAX] bot_started', [
            'user_id' => $userId,
            'start_param' => $startParam,
        ]);

        if (empty($startParam)) {
            $this->sendMessage($userId, __('bot.welcome'));

            return;
        }

        if (str_starts_with($startParam, 'auth_')) {
            $this->handleAuthStart($userId, $startParam);

            return;
        }

        if (str_starts_with($startParam, 'inv_')) {
            $this->handleInviteStart($userId, str_replace('inv_', '', $startParam));

            return;
        }

        if (str_starts_with($startParam, 'book_')) {
            $this->handleBookingStart($userId, $startParam);

            return;
        }

        if (str_starts_with($startParam, 'link_')) {
            $linkToken = $startParam;
            $masterUserId = Cache::pull("max_link:{$linkToken}");

            if ($masterUserId) {
                $user = User::find($masterUserId);
                if ($user) {
                    $user->update([
                        'max_id' => $userId,
                        'max_notifications' => true,
                    ]);

                    broadcast(new UserChannelsUpdated($user));

                    $this->sendMessage($userId, __('bot.notifications.linked_success'));

                    Log::info('[MAX] link_ binding completed', [
                        'user_id' => $user->id,
                        'max_id' => $userId,
                    ]);
                }
            } else {
                $this->sendMessage($userId, __('bot.notifications.link_expired'));
            }

            return;
        }

        $this->sendMessage($userId, __('bot.errors.unknown_command'));
    }

    /**
     * Handle message_created event — user sent a message or command.
     */
    private function handleMessageCreated(array $payload, string $userId): void
    {
        $message = $payload['message'] ?? [];
        $body = $message['body'] ?? [];
        $text = $body['text'] ?? '';

        // Check for contact attachment (user shared phone via request_contact button)
        $attachments = $body['attachments'] ?? [];
        foreach ($attachments as $attachment) {
            if (($attachment['type'] ?? '') === 'contact') {
                $this->handleContact($userId, $attachment);

                return;
            }
        }

        // Handle /start commands
        if (str_starts_with($text, '/start')) {
            $param = trim(str_replace('/start', '', $text));

            if (! empty($param)) {
                if (str_starts_with($param, 'auth_')) {
                    $this->handleAuthStart($userId, $param);

                    return;
                }

                if (str_starts_with($param, 'inv_')) {
                    $this->handleInviteStart($userId, str_replace('inv_', '', $param));

                    return;
                }

                if (str_starts_with($param, 'book_')) {
                    $this->handleBookingStart($userId, $param);

                    return;
                }
            }

            $this->sendMessage($userId, __('bot.welcome'));

            return;
        }

        Log::info('[MAX] unhandled message', ['text' => $text, 'user_id' => $userId]);
        $this->sendMessage($userId, __('bot.errors.use_site_button'));
    }

    /**
     * Handle callback query (inline button press).
     */
    private function handleCallback(array $payload, string $userId): void
    {
        $callbackData = $payload['callback_data'] ?? $payload['data']['callback_data'] ?? '';

        Log::info('[MAX] callback received', [
            'user_id' => $userId,
            'data' => $callbackData,
        ]);

        // Delegate to existing WebhookController logic
        // This will be handled by the controller's handleCallback method
    }

    /**
     * Handle auth start: /start auth_TOKEN
     *
     * Flow:
     * 1. Store login token in cache keyed by user_id
     * 2. Send message asking user to share phone number
     * 3. When phone received, find/create User by phone, update max_id
     * 4. Update cache with 'authenticated' status
     * 5. Frontend polls checkAuthStatus and logs user in
     */
    private function handleAuthStart(string $userId, string $loginToken): void
    {
        Cache::put(
            CacheKeys::MAX_CHAT_TOKEN.$userId,
            $loginToken,
            config('booking.token_ttl'),
        );

        Log::info('[MAX] auth_start cache stored', [
            'user_id' => $userId,
            'login_token' => $loginToken,
        ]);

        $this->sendMessage($userId, __('bot.contact_request.auth'), [
            [
                'type' => 'inline_keyboard',
                'payload' => [
                    'buttons' => [
                        [
                            [
                                'type' => 'request_contact',
                                'text' => __('bot.buttons.share_phone'),
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Handle booking start: /start book_APPOINTMENT_ID
     */
    private function handleBookingStart(string $userId, string $startParam): void
    {
        $appointmentId = str_replace('book_', '', $startParam);

        // Store pending booking in cache
        Cache::put(
            CacheKeys::MAX_BOOKING_DRAFT.$userId,
            $appointmentId,
            config('booking.draft_ttl'),
        );

        Log::info('[MAX] booking_start', [
            'user_id' => $userId,
            'appointment_id' => $appointmentId,
        ]);

        $this->sendMessage($userId, __('bot.contact_request.booking'), [
            [
                'type' => 'inline_keyboard',
                'payload' => [
                    'buttons' => [
                        [
                            [
                                'type' => 'request_contact',
                                'text' => __('bot.buttons.share_phone'),
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Handle contact shared by user.
     *
     * MAX contact attachment structure:
     * {
     *   "type": "contact",
     *   "payload": {
     *     "vcf_info": "BEGIN:VCARD...",
     *     "max_info": { "user_id": 123, "name": "..." },
     *     "hash": "hmac_signature"
     *   }
     * }
     */
    private function handleContact(string $userId, array $attachment): void
    {
        $contactPayload = $attachment['payload'] ?? [];
        $maxInfo = $contactPayload['max_info'] ?? [];
        $contactUserId = (string) ($maxInfo['user_id'] ?? $userId);
        $firstName = $maxInfo['first_name'] ?? $maxInfo['name'] ?? 'Мастер';
        $lastName = $maxInfo['last_name'] ?? '';

        // Extract phone from vcf_info
        $vcfInfo = $contactPayload['vcf_info'] ?? '';
        $phone = $this->extractPhoneFromVcf($vcfInfo);

        if (empty($phone)) {
            Log::warning('[MAX] could not extract phone from contact', [
                'user_id' => $userId,
                'vcf_info' => $vcfInfo,
            ]);
            $this->sendMessage($userId, __('bot.errors.phone_detection_failed'));

            return;
        }

        // Verify hash if present
        $hash = $contactPayload['hash'] ?? null;
        if ($hash) {
            $isValid = $this->verifyContactHash($vcfInfo, $hash);
            if (! $isValid) {
                Log::warning('[MAX] contact hash verification failed', ['user_id' => $userId]);
                $this->sendMessage($userId, __('bot.errors.hash_verification_failed'));

                return;
            }
        }

        // Determine flow: invite, auth, or booking
        $inviteToken = Cache::pull(CacheKeys::MAX_INVITE_TOKEN.$userId);
        $loginToken = Cache::get(CacheKeys::MAX_CHAT_TOKEN.$userId);
        $draftAppointmentId = Cache::get(CacheKeys::MAX_BOOKING_DRAFT.$userId);

        if ($inviteToken) {
            $this->handleInviteContact($userId, $contactUserId, $phone, $firstName, $lastName, $inviteToken);
        } elseif ($loginToken) {
            $this->handleAuthContact($userId, $contactUserId, $phone, $firstName, $lastName, $loginToken);
        } elseif ($draftAppointmentId) {
            $this->handleBookingContact($userId, $contactUserId, $phone, $firstName, $lastName, $draftAppointmentId);
        } else {
            Log::info('[MAX] contact received without active flow', ['user_id' => $userId]);
            $this->sendMessage($userId, __('bot.errors.no_active_session'));
        }
    }

    /**
     * Handle contact in auth flow.
     */
    private function handleAuthContact(
        string $userId,
        string $contactUserId,
        string $phone,
        string $firstName,
        string $lastName,
        string $loginToken,
    ): void {
        Log::info('[MAX] handleAuthContact', ['user_id' => $userId, 'phone' => $phone]);

        $user = User::where('max_id', $userId)->first();

        if (! $user) {
            $user = User::where('phone', $phone)->first();

            if ($user && $user->max_id && $user->max_id !== $userId) {
                $this->sendMessage($userId, '❌ Этот номер телефона уже привязан к другому MAX-аккаунту.');

                return;
            }
        }

        if (! $user) {
            $baseName = trim($firstName.' '.$lastName);
            if ($baseName === '') {
                $baseName = __('bot.fallback.master_name').' '.$phone;
            }

            $slug = app(SlugService::class)->generate(null, $firstName, $lastName);

            try {
                $user = User::create([
                    'name' => $baseName,
                    'phone' => $phone,
                    'max_id' => $userId,
                    'max_notifications' => true,
                    'is_master' => true,
                    'master_slug' => $slug,
                ]);

                if (! $user->workspace_id) {
                    app(WorkspaceService::class)->createForUser($user);
                    $user->refresh();
                }

                Log::info('[MAX] handleAuthContact: user created', ['user_id' => $user->id]);
            } catch (UniqueConstraintViolationException $e) {
                Log::info('[MAX] handleAuthContact: user already created by parallel request');
                $user = User::where('max_id', $userId)->first()
                    ?? User::where('phone', $phone)->firstOrFail();
            }
        } else {
            $updates = [];

            if ($user->max_id !== $userId) {
                $updates['max_id'] = $userId;
            }

            if (! $user->max_notifications) {
                $updates['max_notifications'] = true;
            }

            $fullName = trim($firstName.' '.$lastName);
            if ($fullName !== '' && $user->name !== $fullName) {
                $updates['name'] = $fullName;
            }

            if (! empty($updates)) {
                $user->update($updates);
            }

            Log::info('[MAX] handleAuthContact: existing user', ['user_id' => $user->id]);
        }

        broadcast(new UserChannelsUpdated($user));

        $authCacheKey = CacheKeys::MAX_AUTH.$loginToken;
        Cache::put($authCacheKey, [
            'status' => 'authenticated',
            'user_id' => $user->id,
        ], config('booking.token_ttl'));

        Log::info('[MAX] handleAuthContact: sending confirmation');

        $magicToken = Str::random(32);
        Cache::put('magic_login_'.$magicToken, $user->id, now()->addMinutes(15));
        $magicUrl = route('auth.magic', ['token' => $magicToken]);

        $this->sendMessage($userId, "✅ Авторизация пройдена!\n\nНажмите на ссылку ниже, чтобы открыть кабинет:\n👉 ".$magicUrl);

        Cache::forget(CacheKeys::MAX_CHAT_TOKEN.$userId);
    }

    /**
     * Handle contact in booking flow.
     */
    private function handleBookingContact(
        string $userId,
        string $contactUserId,
        string $phone,
        string $firstName,
        string $lastName,
        string $appointmentId,
    ): void {
        Log::info('[MAX] handleBookingContact', [
            'user_id' => $userId,
            'appointment_id' => $appointmentId,
            'phone' => $phone,
        ]);

        $appointment = Appointment::with(['master', 'service'])
            ->find($appointmentId);

        if (! $appointment) {
            $this->sendMessage($userId, __('bot.errors.appointment_not_found_retry'));

            return;
        }

        $masterId = $appointment->master_id;
        $fullName = trim($firstName.' '.$lastName);

        $client = $this->clientMergeService->findOrCreateByPhone(
            $masterId,
            $phone,
            '',
            $fullName ?: __('bot.fallback.client_name')." {$phone}",
            $appointment->master?->workspace_id,
        );

        // Link max_id to client
        if (empty($client->max_id)) {
            $this->clientMergeService->linkProvider($client, 'max', $userId);
        }

        if ($client->isBlocked()) {
            $appointment->delete();
            $this->sendMessage($userId, __('bot.errors.booking_unavailable'));

            return;
        }

        $appointment->update(['client_id' => $client->id, 'source' => AppointmentSource::Max]);

        broadcast(new AppointmentCreated(
            $appointment->load(['client', 'service'])
        ));

        $service = $appointment->service;
        $master = $appointment->master;
        $tz = $master->getTimezone();
        $date = $appointment->start_time->timezone($tz)->format('d.m.Y');
        $time = $appointment->start_time->timezone($tz)->format('H:i');

        $message = __('bot.booking_confirmed', [
            'service' => $appointment->display_name,
            'date' => $date,
            'time' => $time,
            'price' => $appointment->display_price,
        ]);

        if ($master->address) {
            $message .= __('bot.booking_confirmed_address', ['address' => $master->address]);
        }

        $message .= __('bot.booking_confirmed_suffix');

        // Атомарная блокировка: защищаем и клиента, и мастера от дублей (retry вебхука)
        $lockKey = 'master_notified_'.$appointment->id;

        if (! Cache::add($lockKey, true, now()->addMinutes(10))) {
            Log::info('[MAX] Повторный вебхук пойман в handleBookingContact, глушим отправку.');

            return;
        }

        $this->sendMessage($userId, $message);

        $phone = $client->phone ?? __('bot.fallback.phone');
        $clientName = $client->name ?? __('bot.fallback.client_name');

        app(MasterNotificationService::class)
            ->sendToMaster($master, __('bot.master.new_booking', [
                'client' => $clientName,
                'phone' => $phone,
                'service' => $appointment->display_name,
                'date' => $date,
                'time' => $time,
            ]));

        Cache::forget(CacheKeys::MAX_BOOKING_DRAFT.$userId);
    }

    /**
     * Handle invite start: /start inv_TOKEN
     *
     * Validates invite token, stores it in cache, requests contact from user.
     * User creation is deferred to handleInviteContact after phone is received.
     */
    private function handleInviteStart(string $userId, string $token): void
    {
        $invite = WorkspaceInvite::where('token', $token)
            ->where('expires_at', '>', now())
            ->first();

        if (! $invite) {
            $this->sendMessage($userId, '❌ Ссылка-приглашение недействительна или просрочена.');

            return;
        }

        Cache::put(
            CacheKeys::MAX_INVITE_TOKEN.$userId,
            $token,
            config('booking.token_ttl'),
        );

        Log::info('[MAX] invite_start cache stored', [
            'user_id' => $userId,
            'token' => $token,
        ]);

        $this->sendMessage($userId, __('bot.contact_request.invite'), [
            [
                'type' => 'inline_keyboard',
                'payload' => [
                    'buttons' => [
                        [
                            [
                                'type' => 'request_contact',
                                'text' => __('bot.buttons.share_phone'),
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Handle contact in invite flow.
     *
     * Ported from TelegramWebhookHandler::handleInviteContact with MAX adaptations:
     * - telegram_id → max_id
     * - telegram_notifications → max_notifications
     * - $this->chat->html()->send() → $this->sendMessage()
     * - syncTelegramAvatar → removed (no avatar API in MAX)
     * - Cache::pull for invite token already done in handleContact
     */
    private function handleInviteContact(
        string $userId,
        string $contactUserId,
        string $phone,
        string $firstName,
        string $lastName,
        string $inviteToken,
    ): void {
        $fullName = trim($firstName.' '.$lastName);

        Log::info('[MAX] handleInviteContact()', [
            'user_id' => $userId,
            'phone' => $phone,
        ]);

        if (empty($phone)) {
            $this->sendMessage($userId, __('bot.errors.phone_detection_failed'));

            return;
        }

        $invite = WorkspaceInvite::where('token', $inviteToken)
            ->where('expires_at', '>', now())
            ->first();

        if (! $invite) {
            $this->sendMessage($userId, '❌ Ссылка-приглашение недействительна или просрочена.');

            return;
        }

        $user = User::where('max_id', $userId)->first();

        if (! $user) {
            $user = User::where('phone', $phone)->first();

            if ($user && $user->max_id && $user->max_id !== $userId) {
                $this->sendMessage($userId, '❌ Этот номер телефона уже привязан к другому MAX-аккаунту.');

                return;
            }
        }

        if ($user && $user->workspace_id && $user->workspace_id !== $invite->workspace_id) {
            $currentWs = $user->workspace;
            $isSoloOwner = $currentWs
                && $currentWs->owner_id === $user->id
                && $currentWs->users()->count() <= 1;

            if (! $isSoloOwner) {
                $this->sendMessage($userId, '❌ Вы уже состоите в другой команде.');

                return;
            }
        }

        if ($user && $user->id === $invite->workspace->owner_id) {
            $this->sendMessage($userId, '❌ Вы уже являетесь владельцем этой студии.');

            return;
        }

        // Лимит мест провайдеров: мастер ест место, одиночка без подписки не блокируется.
        if (($invite->role ?? UserRole::Master) === UserRole::Master
            && $invite->workspace
            && $invite->workspace->activeSubscription()
            && ! $invite->workspace->canAddProvider()) {
            $this->sendMessage($userId, '❌ В студии закончились места по тарифу. Попросите владельца освободить место или повысить тариф, затем перейдите по ссылке снова.');

            return;
        }

        if (! $user) {
            $baseName = $fullName !== '' ? $fullName : __('bot.fallback.master_name').' '.$phone;

            $slug = app(SlugService::class)->generate(null, $firstName, $lastName);

            try {
                $user = User::create([
                    'name' => $baseName,
                    'phone' => $phone,
                    'max_id' => $userId,
                    'max_notifications' => true,
                    'is_master' => true,
                    'master_slug' => $slug,
                    'workspace_id' => $invite->workspace_id,
                ]);
                $user->role = $invite->role ?? UserRole::Master;
                $user->is_service_provider = ($invite->role ?? UserRole::Master) === UserRole::Master;
                $user->save();

                Log::info('[MAX] handleInviteContact: user created', ['user_id' => $user->id]);
            } catch (UniqueConstraintViolationException $e) {
                Log::info('[MAX] handleInviteContact: user already created by parallel request');
                $user = User::where('max_id', $userId)->first()
                    ?? User::where('phone', $phone)->firstOrFail();
            }
        } else {
            // Лимит мест провайдеров: мастер ест место, одиночка без подписки не блокируется.
            if (($invite->role ?? UserRole::Master) === UserRole::Master
                && $invite->workspace
                && $invite->workspace->activeSubscription()
                && ! $invite->workspace->canAddProvider()) {
                $this->sendMessage($userId, '❌ В студии закончились места по тарифу. Попросите владельца освободить место или повысить тариф, затем перейдите по ссылке снова.');

                return;
            }

            $oldWorkspaceId = $user->workspace_id;

            $updateData = [
                'max_id' => $userId,
                'max_notifications' => true,
                'name' => $fullName !== '' ? $fullName : $user->name,
                'workspace_id' => $invite->workspace_id,
                'is_master' => true,
            ];

            if (empty($user->master_slug)) {
                $slug = app(SlugService::class)->generate(null, $firstName, $lastName);
                $updateData['master_slug'] = $slug;
            }

            $user->update($updateData);
            $user->role = $invite->role ?? UserRole::Master;
            $user->is_service_provider = ($invite->role ?? UserRole::Master) === UserRole::Master;
            $user->save();

            if ($oldWorkspaceId && $oldWorkspaceId !== $invite->workspace_id) {
                try {
                    $oldWs = Workspace::find($oldWorkspaceId);
                    if ($oldWs && $oldWs->owner_id === $user->id && $oldWs->users()->count() === 0) {
                        $oldWs->delete();
                    }
                } catch (\Throwable $e) {
                    Log::warning('Failed to clean up old personal workspace', [
                        'workspace_id' => $oldWorkspaceId,
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('[MAX] handleInviteContact: existing user updated', ['user_id' => $user->id]);
        }

        $invite->delete();

        try {
            $this->sendMessage($userId, '✅ Вы успешно добавлены в команду! Теперь вы можете управлять своими записями.');
        } catch (\Throwable $e) {
            Log::error('[MAX] handleInviteContact: confirmation FAILED', [
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('[MAX] handleInviteContact: success', [
            'user_id' => $user->id,
            'user_id_max' => $userId,
        ]);
    }

    /**
     * Extract phone number from vCard string.
     */
    private function extractPhoneFromVcf(string $vcfInfo): string
    {
        if (preg_match('/TEL[^:]*:(.+)/', $vcfInfo, $matches)) {
            return preg_replace('/[^0-9]/', '', $matches[1]);
        }

        return '';
    }

    /**
     * Verify contact hash using HMAC-SHA256.
     *
     * MAX uses: HMAC-SHA256(access_token, vcf_info)
     * where vcf_info has literal \r\n converted to real newlines.
     */
    private function verifyContactHash(string $vcfInfo, string $expectedHash): bool
    {
        $token = trim(config('services.max.bot_token'));

        if (empty($token)) {
            Log::error('[MAX] Bot token is empty');

            return false;
        }

        // Normalize all newlines to \r\n and ensure trailing \r\n after END:VCARD
        $vcfFormatted = rtrim(preg_replace("/\r\n|\r|\n/", "\r\n", $vcfInfo))."\r\n";

        $actualHash = hash_hmac('sha256', $vcfFormatted, $token);

        if (! hash_equals($expectedHash, $actualHash)) {
            Log::warning('[MAX] contact hash verification failed', ['expected' => $expectedHash, 'actual' => $actualHash]);

            return false;
        }

        return true;
    }

    /**
     * Send message via MAX Bot API.
     */
    public function sendMessage(string $userId, string $text, ?array $attachments = null): void
    {
        $extra = [];
        if ($attachments) {
            $extra['attachments'] = $attachments;
        }

        $this->maxApi->sendMessage($userId, $text, $extra);
    }
}
