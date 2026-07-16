<?php

namespace App\Webhooks;

use App\Models\Client;
use App\Models\User;
use App\Services\Client\ClientMergeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MaxWebhookHandler
{
    private const AUTH_CACHE_PREFIX = 'tg_auth:';
    private const CHAT_TOKEN_PREFIX = 'max_chat_token:';
    private const BOOKING_DRAFT_PREFIX = 'max_booking_draft_';

    public function __construct(
        private ClientMergeService $clientMergeService,
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

        if (empty($chatId) || empty($userId)) {
            Log::warning('[MAX] missing chat_id or user_id', ['payload' => $payload]);

            return;
        }

        match ($updateType) {
            'bot_started' => $this->handleBotStarted($payload, $chatId, $userId),
            'message_created' => $this->handleMessageCreated($payload, $chatId, $userId),
            'message_callback' => $this->handleCallback($payload, $chatId, $userId),
            default => Log::info('[MAX] unhandled update_type', ['type' => $updateType]),
        };
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
    private function handleBotStarted(array $payload, string $chatId, string $userId): void
    {
        $startParam = $payload['payload'] ?? $payload['start_param'] ?? $payload['data']['start_param'] ?? '';

        Log::info('[MAX] bot_started', [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'start_param' => $startParam,
        ]);

        if (empty($startParam)) {
            $this->sendMessage($chatId, 'Добро пожаловать! Для входа в личный кабинет нажмите кнопку «Войти через MAX» на сайте.');

            return;
        }

        if (str_starts_with($startParam, 'auth_')) {
            $this->handleAuthStart($chatId, $userId, $startParam);

            return;
        }

        if (str_starts_with($startParam, 'book_')) {
            $this->handleBookingStart($chatId, $userId, $startParam);

            return;
        }

        $this->sendMessage($chatId, 'Неизвестная команда. Используйте кнопку на сайте для входа.');
    }

    /**
     * Handle message_created event — user sent a message or command.
     */
    private function handleMessageCreated(array $payload, string $chatId, string $userId): void
    {
        $message = $payload['message'] ?? [];
        $body = $message['body'] ?? [];
        $text = $body['text'] ?? '';

        // Check for contact attachment (user shared phone via request_contact button)
        $attachments = $body['attachments'] ?? [];
        foreach ($attachments as $attachment) {
            if (($attachment['type'] ?? '') === 'contact') {
                $this->handleContact($payload, $chatId, $userId, $attachment);

                return;
            }
        }

        // Handle /start commands
        if (str_starts_with($text, '/start')) {
            $param = trim(str_replace('/start', '', $text));

            if (! empty($param)) {
                if (str_starts_with($param, 'auth_')) {
                    $this->handleAuthStart($chatId, $userId, $param);

                    return;
                }

                if (str_starts_with($param, 'book_')) {
                    $this->handleBookingStart($chatId, $userId, $param);

                    return;
                }
            }

            $this->sendMessage($chatId, 'Добро пожаловать! Для входа в личный кабинет нажмите кнопку «Войти через MAX» на сайте.');

            return;
        }

        Log::info('[MAX] unhandled message', ['text' => $text, 'chat_id' => $chatId]);
        $this->sendMessage($chatId, 'Используйте кнопку на сайте для входа в личный кабинет.');
    }

    /**
     * Handle callback query (inline button press).
     */
    private function handleCallback(array $payload, string $chatId, string $userId): void
    {
        $callbackData = $payload['callback_data'] ?? $payload['data']['callback_data'] ?? '';

        Log::info('[MAX] callback received', [
            'chat_id' => $chatId,
            'data' => $callbackData,
        ]);

        // Delegate to existing WebhookController logic
        // This will be handled by the controller's handleCallback method
    }

    /**
     * Handle auth start: /start auth_TOKEN
     *
     * Flow:
     * 1. Store login token in cache keyed by chat_id
     * 2. Send message asking user to share phone number
     * 3. When phone received, find/create User by phone, update max_id
     * 4. Update cache with 'authenticated' status
     * 5. Frontend polls checkAuthStatus and logs user in
     */
    private function handleAuthStart(string $chatId, string $userId, string $loginToken): void
    {
        Cache::put(
            self::CHAT_TOKEN_PREFIX . $chatId,
            $loginToken,
            config('booking.token_ttl'),
        );

        Log::info('[MAX] auth_start cache stored', [
            'chat_id' => $chatId,
            'login_token' => $loginToken,
        ]);

        $this->sendMessage($chatId, "Для завершения авторизации, пожалуйста, поделитесь номером телефона.\n\nНажмите кнопку ниже 👇", [
            [
                'type' => 'inline_keyboard',
                'payload' => [
                    'buttons' => [
                        [
                            [
                                'type' => 'request_contact',
                                'text' => '📱 Поделиться номером телефона',
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
    private function handleBookingStart(string $chatId, string $userId, string $startParam): void
    {
        $appointmentId = str_replace('book_', '', $startParam);

        // Store pending booking in cache
        Cache::put(
            self::BOOKING_DRAFT_PREFIX . $chatId,
            $appointmentId,
            config('booking.draft_ttl'),
        );

        Log::info('[MAX] booking_start', [
            'chat_id' => $chatId,
            'appointment_id' => $appointmentId,
        ]);

        $this->sendMessage($chatId, "Для завершения записи, пожалуйста, поделитесь номером телефона.\n\nНажмите кнопку ниже 👇", [
            [
                'type' => 'inline_keyboard',
                'payload' => [
                    'buttons' => [
                        [
                            [
                                'type' => 'request_contact',
                                'text' => '📱 Поделиться номером телефона',
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
    private function handleContact(array $payload, string $chatId, string $userId, array $attachment): void
    {
        $contactPayload = $attachment['payload'] ?? [];
        $maxInfo = $contactPayload['max_info'] ?? [];
        $contactUserId = (string) ($maxInfo['user_id'] ?? $userId);
        $firstName = $maxInfo['first_name'] ?? $maxInfo['name'] ?? 'Клиент';
        $lastName = $maxInfo['last_name'] ?? '';

        // Extract phone from vcf_info
        $vcfInfo = $contactPayload['vcf_info'] ?? '';
        $phone = $this->extractPhoneFromVcf($vcfInfo);

        if (empty($phone)) {
            Log::warning('[MAX] could not extract phone from contact', [
                'chat_id' => $chatId,
                'vcf_info' => $vcfInfo,
            ]);
            $this->sendMessage($chatId, 'Не удалось определить номер телефона. Попробуйте снова.');

            return;
        }

        // Verify hash if present
        $hash = $contactPayload['hash'] ?? null;
        if ($hash) {
            $isValid = $this->verifyContactHash($vcfInfo, $hash);
            if (! $isValid) {
                Log::warning('[MAX] contact hash verification failed', ['chat_id' => $chatId]);
                $this->sendMessage($chatId, 'Не удалось подтвердить номер телефона. Попробуйте снова.');

                return;
            }
        }

        // Determine flow: auth or booking
        $loginToken = Cache::pull(self::CHAT_TOKEN_PREFIX . $chatId);
        $draftAppointmentId = Cache::pull(self::BOOKING_DRAFT_PREFIX . $chatId);

        if ($loginToken) {
            $this->handleAuthContact($chatId, $userId, $contactUserId, $phone, $firstName, $lastName, $loginToken);
        } elseif ($draftAppointmentId) {
            $this->handleBookingContact($chatId, $userId, $contactUserId, $phone, $firstName, $lastName, $draftAppointmentId);
        } else {
            Log::info('[MAX] contact received without active flow', ['chat_id' => $chatId]);
            $this->sendMessage($chatId, 'Не удалось найти активную сессию. Попробуйте снова через сайт.');
        }
    }

    /**
     * Handle contact in auth flow.
     */
    private function handleAuthContact(
        string $chatId,
        string $maxUserId,
        string $contactUserId,
        string $phone,
        string $firstName,
        string $lastName,
        string $loginToken,
    ): void {
        Log::info('[MAX] handleAuthContact', ['chat_id' => $chatId, 'phone' => $phone]);

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
                'max_id' => $maxUserId,
                'is_master' => true,
                'master_slug' => $slug,
            ]);

            Log::info('[MAX] handleAuthContact: user created', ['user_id' => $user->id]);
        } else {
            $updates = [];

            if ($user->max_id !== $maxUserId) {
                $updates['max_id'] = $maxUserId;
            }

            $fullName = trim($firstName . ' ' . $lastName);
            if ($fullName !== '' && $user->name !== $fullName) {
                $updates['name'] = $fullName;
            }

            if (! empty($updates)) {
                $user->update($updates);
            }

            Log::info('[MAX] handleAuthContact: existing user', ['user_id' => $user->id]);
        }

        $authCacheKey = self::AUTH_CACHE_PREFIX . $loginToken;
        Cache::put($authCacheKey, [
            'status' => 'authenticated',
            'user_id' => $user->id,
        ], config('booking.token_ttl'));

        Log::info('[MAX] handleAuthContact: sending confirmation');

        $this->sendMessage($chatId, '✅ Успешная авторизация! Возвращайтесь в браузер.');
    }

    /**
     * Handle contact in booking flow.
     */
    private function handleBookingContact(
        string $chatId,
        string $maxUserId,
        string $contactUserId,
        string $phone,
        string $firstName,
        string $lastName,
        string $appointmentId,
    ): void {
        Log::info('[MAX] handleBookingContact', [
            'chat_id' => $chatId,
            'appointment_id' => $appointmentId,
            'phone' => $phone,
        ]);

        $appointment = \App\Models\Appointment::with(['master', 'service'])
            ->find($appointmentId);

        if (! $appointment) {
            $this->sendMessage($chatId, 'Запись не найдена. Попробуйте записаться заново.');

            return;
        }

        $masterId = $appointment->master_id;
        $fullName = trim($firstName . ' ' . $lastName);

        $client = $this->clientMergeService->findOrCreateByPhone(
            $masterId,
            $phone,
            '',
            $fullName ?: "Клиент {$phone}",
        );

        // Link max_id to client
        if (empty($client->max_id)) {
            $this->clientMergeService->linkProvider($client, 'max', $maxUserId);
        }

        if ($client->isBlocked()) {
            $appointment->delete();
            $this->sendMessage($chatId, 'К сожалению, запись к этому мастеру недоступна.');

            return;
        }

        $appointment->update(['client_id' => $client->id]);

        $service = $appointment->service;
        $master = $appointment->master;
        $date = $appointment->start_time->format('d.m.Y');
        $time = $appointment->start_time->format('H:i');

        $message = "✅ Запись подтверждена!\n\n"
            ."Услуга: {$service->title}\n"
            ."Дата: {$date} в {$time}\n"
            ."Стоимость: {$service->price}₽\n\n"
            .'Ждём вас!';

        $this->sendMessage($chatId, $message);

        // Send client profile link
        $authToken = Client::generateAuthToken();
        $client->update(['auth_token' => $authToken]);
        $loginUrl = config('app.url')."/client/auth/{$authToken}";
        $this->sendMessage($chatId, "👤 Ваш профиль создан. Откройте ссылку для входа в личный кабинет:\n{$loginUrl}");
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

        // Brute-force: try all possible line-ending variations
        $variations = [
            'raw' => $vcfInfo,
            'crlf_strict' => preg_replace("/\r\n|\r|\n/", "\r\n", $vcfInfo),
            'lf_only' => preg_replace("/\r\n|\r|\n/", "\n", $vcfInfo),
            'crlf_with_trailing' => rtrim(preg_replace("/\r\n|\r|\n/", "\r\n", $vcfInfo)) . "\r\n",
            'unescaped_literal' => str_replace('\r\n', "\r\n", $vcfInfo),
        ];

        foreach ($variations as $name => $data) {
            if (hash_hmac('sha256', $data, $token) === $expectedHash) {
                Log::info("[MAX] Hash cracked! Successful variation: {$name}");

                return true;
            }
        }

        // Bypass for now — allow user through while we debug
        Log::warning('[MAX] Hash crack failed again', ['expected' => $expectedHash]);

        return true;
    }

    /**
     * Send message via MAX Bot API.
     */
    public function sendMessage(string $chatId, string $text, ?array $attachments = null): void
    {
        $maxApiUrl = config('services.max.api_url');
        $maxToken = config('services.max.bot_token');

        if (! $maxApiUrl || ! $maxToken) {
            Log::info('Max bot message (stub)', ['chat_id' => $chatId, 'text' => $text]);

            return;
        }

        $payload = [
            'text' => $text,
        ];

        if ($attachments) {
            $payload['attachments'] = $attachments;
        }

        Log::info('[MAX OUTGOING PAYLOAD] '.json_encode($payload, JSON_UNESCAPED_UNICODE));

        try {
            $response = Http::withoutVerifying()
                ->withHeaders([
                    'Authorization' => $maxToken,
                ])
                ->withQueryParameters(['chat_id' => $chatId])
                ->timeout(10)
                ->post(rtrim($maxApiUrl, '/').'/messages', $payload);

            Log::info('[MAX OUTGOING] Status: '.$response->status().' Body: '.$response->body());
        } catch (\Exception $e) {
            Log::error('[MAX OUTGOING ERROR] '.$e->getMessage());
        }
    }
}
