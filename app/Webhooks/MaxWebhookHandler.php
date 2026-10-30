<?php

namespace App\Webhooks;

use App\Constants\CacheKeys;
use App\Enums\AppointmentNotificationEventType;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Enums\SlotOfferStatus;
use App\Events\AppointmentCreated;
use App\Events\UserChannelsUpdated;
use App\Exceptions\AmbiguousClientMatchException;
use App\Exceptions\DuplicateClientPhoneException;
use App\Exceptions\ProviderLinkConflictException;
use App\Exceptions\StaleClientConfirmationException;
use App\Jobs\MatchSlotOpportunityJob;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\SlotOffer;
use App\Models\User;
use App\Services\AppointmentStatusService;
use App\Services\AppointmentVisitConfirmationService;
use App\Services\Client\ClientMergeService;
use App\Services\MaxApiClient;
use App\Services\Notification\AppointmentNotificationRecorder;
use App\Services\Notification\MasterNotificationService;
use App\Services\SlotOfferAcceptanceService;
use App\Services\SlotOfferService;
use App\Services\SlugService;
use App\Services\WorkspaceService;
use App\Support\Phone;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        } elseif ($updateType === 'message_callback') {
            $chatId = (string) ($payload['message']['recipient']['chat_id'] ?? '');
            $userId = (string) ($payload['callback']['user']['user_id'] ?? '');
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

        // Дедупликация входящих вебхуков: MAX может прислать одно событие повторно.
        // Гасим повторную обработку, чтобы не плодить исходящие запросы (лимит 30 rps → 429).
        $eventId = match ($updateType) {
            'message_created' => $payload['message']['body']['mid'] ?? null,
            'message_callback' => $payload['callback']['callback_id'] ?? null,
            'bot_started' => isset($payload['timestamp'])
                ? 'bot_started_'.$userId.'_'.($payload['payload']
                    ?? $payload['start_param']
                    ?? $payload['data']['start_param']
                    ?? '').'_'.$payload['timestamp']
                : null,
            default => null,
        };

        if ($eventId !== null) {
            $dedupKey = CacheKeys::MAX_UPDATE_DEDUP.$eventId;
            if (! Cache::add($dedupKey, true, 60)) {
                Log::info('[MAX] duplicate webhook skipped', [
                    'update_type' => $updateType,
                    'event_id' => $eventId,
                    'user_id' => $userId,
                ]);

                return;
            }
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
        Log::info('[MAX] callback FULL payload', ['payload' => $payload]);

        $callback = $payload['callback'] ?? [];
        $callbackId = (string) ($callback['callback_id'] ?? '');
        $data = (string) ($callback['payload'] ?? '');

        Log::info('[MAX] callback received', [
            'user_id' => $userId,
            'callback_id' => $callbackId,
            'data' => $data,
        ]);

        if (str_starts_with($data, 'pdn_')) {
            $flow = substr($data, 4);
            $this->acceptMaxConsent($userId, $callbackId, $flow);

            return;
        }

        if (str_starts_with($data, 'cv_')) {
            $appointmentId = substr($data, 3);
            $this->confirmMaxVisit($userId, $callbackId, $appointmentId);

            return;
        }

        if (str_starts_with($data, 'af_accept_')) {
            $offerUuid = substr($data, 10);
            $messageId = (string) ($callback['message']['body']['mid'] ?? '');
            $this->handleAutofillAccept($userId, $callbackId, $offerUuid, $messageId);

            return;
        }

        if (str_starts_with($data, 'af_decline_')) {
            $offerUuid = substr($data, 11);
            $messageId = (string) ($callback['message']['body']['mid'] ?? '');
            $this->handleAutofillDecline($userId, $callbackId, $offerUuid, $messageId);

            return;
        }

        if (str_starts_with($data, 'max_confirm_')) {
            $appointmentId = substr($data, 12);
            $this->confirmMaxBooking($userId, $callbackId, $appointmentId);

            return;
        }

        if (str_starts_with($data, 'max_cancel_')) {
            $appointmentId = substr($data, 11);
            $this->cancelMaxBooking($userId, $callbackId, $appointmentId);

            return;
        }

        if ($callbackId !== '') {
            $this->maxApi->answerCallback($callbackId);
        }
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

        $subject = User::where('max_id', $userId)->first();
        if ($this->needsMaxPdnConsent($subject)) {
            $this->sendMaxConsentBarrier($userId, 'auth');

            return;
        }

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

        $appointment = Appointment::with(['master'])->find($appointmentId);
        if (! $appointment) {
            $this->sendMessage($userId, __('bot.errors.appointment_not_found_retry'));

            return;
        }

        // Устаревшая/чужая/закрытая ссылка не открывает оформление и не
        // создаёт/не продлевает черновик: проверка — до Cache::put.
        if (! $this->isConfirmableDraft($appointment)) {
            $this->clearDraftIfMatches($userId, $appointmentId);
            $this->sendUnopenableBookingMessage($userId, $appointment);

            Log::info('[MAX] booking_start refused', [
                'user_id' => $userId,
                'appointment_id' => $appointmentId,
                'status' => $appointment->status->value,
                'start_time' => $appointment->start_time?->toIso8601String(),
                'client_id' => $appointment->client_id,
            ]);

            return;
        }

        $this->rememberBookingDraft($userId, $appointmentId);

        Log::info('[MAX] booking_start', [
            'user_id' => $userId,
            'appointment_id' => $appointmentId,
        ]);

        $hasGlobalConsent = Client::where('max_id', $userId)
            ->whereNotNull('pdn_consent_at')
            ->where('pdn_consent_version', config('legal.version'))
            ->exists();

        if (! $hasGlobalConsent) {
            $this->sendMaxConsentBarrier($userId, 'book');

            return;
        }

        $client = Client::byMaxId($userId)
            ->where('user_id', $appointment->master_id)
            ->first();

        if ($client) {
            $this->sendBookingConfirmationPrompt($userId, $appointment);

            return;
        }

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

        // Determine flow: auth or booking
        $loginToken = Cache::get(CacheKeys::MAX_CHAT_TOKEN.$userId);
        $draftAppointmentId = Cache::get(CacheKeys::MAX_BOOKING_DRAFT.$userId);

        // Телефон берётся только из контакта самого отправителя:
        // чужой контакт не даёт права привязать свой аккаунт к карточке.
        if ((string) $contactUserId !== (string) $userId) {
            Log::warning('[MAX] contact does not belong to sender', [
                'user_id' => $userId,
                'appointment_id' => $draftAppointmentId,
            ]);
            $this->sendMessage($userId, __('bot.errors.phone_detection_failed'));

            return;
        }

        if ($loginToken) {
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
                    'pdn_consent_at' => now(),
                    'pdn_consent_version' => Cache::pull(CacheKeys::MAX_CONSENT_PENDING.$userId) ?? config('legal.version'),
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

            $pendingConsent = Cache::pull(CacheKeys::MAX_CONSENT_PENDING.$userId);
            if ($pendingConsent && $user->pdn_consent_version !== $pendingConsent) {
                $updates['pdn_consent_at'] = now();
                $updates['pdn_consent_version'] = $pendingConsent;
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

        $appointment = Appointment::with(['master'])
            ->find($appointmentId);

        if (! $appointment) {
            $this->sendMessage($userId, __('bot.errors.appointment_not_found_retry'));

            return;
        }

        $masterId = $appointment->master_id;
        $fullName = trim($firstName.' '.$lastName);
        $pendingConsent = Cache::get(CacheKeys::MAX_CONSENT_PENDING.$userId);
        $expectedPhone = Phone::normalize($phone);
        $workspaceId = $appointment->master?->workspace_id;

        // Поиск/создание, привязка и подтверждение записи атомарны:
        // конфликт не оставляет ни частично подтверждённой записи,
        // ни изменённой карточки/согласия/привязки. HTTP к мессенджерам —
        // только после завершения транзакции.
        //
        // Единый порядок блокировок MAX-путей: телефонный advisory →
        // строка записи → advisory привязки → карточка (confirmMaxBooking
        // участвует подмножеством «запись → карточка»). Строка записи
        // занимается раньше карточки, поэтому contact и callback не
        // пересекаются в обратном порядке client → appointment.
        $client = null;

        DB::beginTransaction();

        try {
            $this->clientMergeService->acquirePhoneLock(
                $masterId,
                $phone,
                $workspaceId,
            );

            $fresh = Appointment::query()
                ->whereKey($appointment->id)
                ->lockForUpdate()
                ->first();

            if ($fresh === null) {
                DB::rollBack();
                $this->clearDraftIfMatches($userId, (string) $appointment->id);

                $this->sendMessage($userId, __('bot.errors.appointment_not_found'));

                return;
            }

            // Состояние перечитывается под блокировкой: между показом
            // кнопок и отправкой контакта запись могла устареть,
            // отмениться или быть оформлена другим клиентом.
            if (! $this->isConfirmableDraft($fresh)) {
                DB::rollBack();
                $this->clearDraftIfMatches($userId, (string) $appointment->id);
                $this->sendUnopenableBookingMessage($userId, $fresh);

                return;
            }

            $client = $this->clientMergeService->findOrCreateByPhone(
                $masterId,
                $phone,
                '',
                $fullName ?: null,
                $workspaceId,
            );

            // Привязываем max_id только из проверенного MAX-контакта; чужую
            // существующую привязку не перезаписываем. После ожидания
            // блокировок карточка должна всё ещё соответствовать
            // подтверждённому номеру и разрешённой области.
            $this->clientMergeService->linkProvider($client, 'max', $userId, $expectedPhone);

            if ($pendingConsent && $client->pdn_consent_version !== $pendingConsent) {
                $client->pdn_consent_at = now();
                $client->pdn_consent_version = $pendingConsent;
                $client->save();
            }

            if ($client->isBlocked()) {
                DB::rollBack();

                // Запись удаляется только пока она не подтверждена другим
                // способом; запоздалое подтверждение её не трогает.
                Appointment::whereKey($appointment->id)->whereNull('client_id')->delete();
                $this->clearDraftIfMatches($userId, (string) $appointment->id);
                $this->sendMessage($userId, __('bot.errors.booking_unavailable'));

                return;
            }

            // Подтверждается только ещё не привязанная запись в допустимом
            // статусе: отменённая или уже занятая запись не перепривязывается,
            // а отказ откатывает карточку/согласие/привязку целиком.
            $claimed = Appointment::whereKey($appointment->id)
                ->whereNull('client_id')
                ->whereIn('status', [
                    AppointmentStatus::Booked,
                    AppointmentStatus::PendingPayment,
                ])
                ->update([
                    'client_id' => $client->id,
                    'source' => AppointmentSource::Max,
                ]);

            if ($claimed === 0) {
                DB::rollBack();

                Log::warning('[MAX] handleBookingContact: appointment claim refused', [
                    'appointment_id' => $appointmentId,
                    'master_id' => $masterId,
                ]);
                $this->clearDraftIfMatches($userId, (string) $appointment->id);
                $this->sendMessage($userId, __('bot.errors.appointment_not_found'));

                return;
            }

            // Outbox: подтверждение брони фиксируется в той же транзакции,
            // что и привязка. Синхронной отправки после commit нет — job
            // доставит одно сообщение через общую дедупликацию.
            $appointment->refresh();
            $appointment->loadMissing(['client', 'master', 'masterService.catalog']);
            $recorder = app(AppointmentNotificationRecorder::class);
            $version = $recorder->bumpVersion($appointment);
            $recorder->record(
                $appointment,
                AppointmentNotificationEventType::BookingConfirmed,
                [],
                $version,
            );
        } catch (AmbiguousClientMatchException) {
            DB::rollBack();

            Log::warning('[MAX] handleBookingContact: ambiguous client match', [
                'appointment_id' => $appointmentId,
                'master_id' => $masterId,
            ]);
            $this->sendMessage($userId, __('bot.errors.booking_unavailable'));

            return;
        } catch (StaleClientConfirmationException) {
            DB::rollBack();

            Log::warning('[MAX] handleBookingContact: card changed while confirming', [
                'appointment_id' => $appointmentId,
                'master_id' => $masterId,
            ]);
            $this->sendMessage($userId, __('bot.errors.booking_unavailable'));

            return;
        } catch (DuplicateClientPhoneException) {
            DB::rollBack();

            Log::warning('[MAX] handleBookingContact: phone occupied outside scope', [
                'appointment_id' => $appointmentId,
                'master_id' => $masterId,
            ]);
            $this->sendMessage($userId, __('bot.errors.booking_unavailable'));

            return;
        } catch (ProviderLinkConflictException) {
            DB::rollBack();

            Log::warning('[MAX] handleBookingContact: max_id already linked elsewhere', [
                'appointment_id' => $appointmentId,
            ]);
            $this->sendMessage($userId, '❌ Этот аккаунт MAX уже привязан к другому профилю.');

            return;
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        DB::commit();

        if ($pendingConsent) {
            Cache::forget(CacheKeys::MAX_CONSENT_PENDING.$userId);
        }

        $appointment->refresh();

        broadcast(new AppointmentCreated(
            $appointment->load(['client'])
        ));

        // Подтверждение клиенту доставляет outbox-job
        // (AppointmentNotificationEventType::BookingConfirmed) — без
        // параллельной синхронной отправки, чтобы не было дублей.
        $master = $appointment->master;
        $tz = $master->getTimezone();
        $date = $appointment->start_time->timezone($tz)->format('d.m.Y');
        $time = $appointment->start_time->timezone($tz)->format('H:i');

        // Атомарная блокировка: защищаем мастера от дублей (retry вебхука)
        $lockKey = 'master_notified_'.$appointment->id;

        if (! Cache::add($lockKey, true, now()->addMinutes(10))) {
            Log::info('[MAX] Повторный вебхук пойман в handleBookingContact, глушим отправку.');

            return;
        }

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

        $this->clearDraftIfMatches($userId, (string) $appointment->id);
    }

    /**
     * Extract phone number from vCard string.
     */
    private function extractPhoneFromVcf(string $vcfInfo): string
    {
        if (preg_match('/TEL[^:]*:(.+)/', $vcfInfo, $matches)) {
            return Phone::normalize($matches[1]) ?? '';
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

    private function needsMaxPdnConsent(?Model $subject): bool
    {
        return $subject === null
            || empty($subject->pdn_consent_at)
            || $subject->pdn_consent_version !== config('legal.version');
    }

    private function sendMaxConsentBarrier(string $userId, string $flow): void
    {
        $text = $flow === 'auth'
            ? __('bot.consent.master_text')
            : __('bot.consent.client_text');

        $attachments = [[
            'type' => 'inline_keyboard',
            'payload' => [
                'buttons' => [
                    [
                        [
                            'type' => 'callback',
                            'text' => __('bot.consent.button_accept'),
                            'payload' => 'pdn_'.$flow,
                        ],
                    ],
                    [
                        [
                            'type' => 'link',
                            'text' => __('bot.consent.button_offer'),
                            'url' => config('legal.offer_url'),
                        ],
                        [
                            'type' => 'link',
                            'text' => __('bot.consent.button_privacy'),
                            'url' => config('legal.privacy_url'),
                        ],
                    ],
                ],
            ],
        ]];

        try {
            $this->sendMessage($userId, $text, $attachments);

            Log::info('[MAX] consent barrier sent', ['flow' => $flow, 'user_id' => $userId]);
        } catch (\Throwable $e) {
            Log::error('[MAX] consent barrier FAILED', ['error' => $e->getMessage(), 'flow' => $flow]);
        }
    }

    private function acceptMaxConsent(string $userId, string $callbackId, string $flow): void
    {
        Cache::put(
            CacheKeys::MAX_CONSENT_PENDING.$userId,
            config('legal.version'),
            config('booking.draft_ttl'),
        );

        Log::info('[MAX] consent accepted', ['flow' => $flow, 'user_id' => $userId]);

        if ($flow === 'book') {
            $appointmentId = Cache::get(CacheKeys::MAX_BOOKING_DRAFT.$userId);
            $appointment = $appointmentId ? Appointment::find($appointmentId) : null;

            if ($appointment) {
                // Черновик мог устареть/отмениться/быть оформлен, пока
                // пользователь принимал согласие. Перед мутацией карточки
                // запись перечитывается под lockForUpdate в транзакции:
                // проверка актуальности черновика и сохранение согласия
                // атомарны относительно состояния записи (порядок
                // блокировок «запись → карточка», как в confirmMaxBooking).
                $state = DB::transaction(function () use ($userId, $appointment): array {
                    $fresh = Appointment::query()
                        ->whereKey($appointment->id)
                        ->lockForUpdate()
                        ->first();

                    if ($fresh === null || ! $this->isConfirmableDraft($fresh)) {
                        return ['result' => 'unopenable', 'appointment' => $fresh ?? $appointment];
                    }

                    $client = Client::byMaxId($userId)
                        ->where('user_id', $fresh->master_id)
                        ->lockForUpdate()
                        ->first();

                    if (! $client) {
                        return ['result' => 'no_client'];
                    }

                    $client->pdn_consent_at = now();
                    $client->pdn_consent_version = config('legal.version');
                    $client->save();

                    return ['result' => 'ok', 'appointment' => $fresh];
                });

                if ($state['result'] === 'unopenable') {
                    /** @var Appointment $stale */
                    $stale = $state['appointment'];
                    $this->clearDraftIfMatches($userId, (string) $stale->id);
                    $this->maxApi->answerCallback($callbackId);
                    $this->sendUnopenableBookingMessage($userId, $stale);

                    return;
                }

                if ($state['result'] === 'ok') {
                    /** @var Appointment $confirmed */
                    $confirmed = $state['appointment'];

                    $this->maxApi->answerCallbackWithMessage(
                        $callbackId,
                        __('bot.consent.after_accept_client_returning'),
                    );
                    $this->sendBookingConfirmationPrompt($userId, $confirmed->load('master'));

                    return;
                }
            }
        }

        $text = $flow === 'auth'
            ? __('bot.contact_request.auth')
            : __('bot.contact_request.booking');

        $attachments = [[
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
        ]];

        try {
            $ok = $this->maxApi->answerCallbackWithMessage($callbackId, $text, ['attachments' => $attachments]);
            if (! $ok) {
                $this->maxApi->answerCallback($callbackId);
                $this->sendMessage($userId, $text, $attachments);
            }
        } catch (\Throwable $e) {
            Log::error('[MAX] acceptMaxConsent FAILED', ['error' => $e->getMessage(), 'flow' => $flow]);
            $this->maxApi->answerCallback($callbackId);
            $this->sendMessage($userId, $text, $attachments);
        }
    }

    private function sendBookingConfirmationPrompt(string $userId, Appointment $appointment): void
    {
        $master = $appointment->master;
        $tz = $master->getTimezone();
        $date = $appointment->start_time->timezone($tz)->format('d.m.Y');
        $time = $appointment->start_time->timezone($tz)->format('H:i');

        $message = __('bot.booking_summary', [
            'service' => $appointment->display_name,
            'date' => $date,
            'time' => $time,
            'price' => $appointment->display_price,
        ]);

        if ($master->address) {
            $message .= __('bot.booking_confirmed_address', ['address' => $master->address]);
        }

        $attachments = [[
            'type' => 'inline_keyboard',
            'payload' => [
                'buttons' => [
                    [
                        [
                            'type' => 'callback',
                            'text' => __('bot.buttons.confirm_booking'),
                            'payload' => 'max_confirm_'.$appointment->id,
                        ],
                        [
                            'type' => 'callback',
                            'text' => __('bot.buttons.cancel'),
                            'payload' => 'max_cancel_'.$appointment->id,
                        ],
                    ],
                ],
            ],
        ]];

        $this->sendMessage($userId, $message, $attachments);
    }

    private function confirmMaxBooking(string $userId, string $callbackId, string $appointmentId): void
    {
        $appointment = Appointment::with(['master'])->find($appointmentId);

        if (! $appointment) {
            $this->refuseBookingCallback($userId, $callbackId, __('bot.errors.appointment_not_found'));

            return;
        }

        $draftId = Cache::get(CacheKeys::MAX_BOOKING_DRAFT.$userId);
        if ((string) $draftId !== (string) $appointmentId) {
            $this->refuseBookingCallback($userId, $callbackId, __('bot.errors.appointment_not_found'));

            return;
        }

        // Проверки и захват записи — в одной транзакции под блокировкой
        // строки: подтверждается только ещё не привязанная запись в
        // допустимом статусе, отказ не меняет карточку/согласие/привязку,
        // а HTTP-ответы выполняются после завершения транзакции.
        $phone = '';
        $clientName = '';

        /** @var array{result: string} $state */
        $state = DB::transaction(function () use ($appointmentId, $userId, &$phone, &$clientName) {
            $fresh = Appointment::query()
                ->whereKey($appointmentId)
                ->lockForUpdate()
                ->first();

            if ($fresh === null) {
                return ['result' => 'not_found'];
            }

            // Прошедшая запись не подтверждается по старой кнопке —
            // до каких-либо мутаций.
            if ($fresh->start_time->isPast()) {
                return ['result' => 'expired'];
            }

            $client = Client::byMaxId($userId)
                ->where('user_id', $fresh->master_id)
                ->lockForUpdate()
                ->first();

            if (! $client) {
                return ['result' => 'not_found'];
            }

            $hasGlobalConsent = Client::where('max_id', $userId)
                ->whereNotNull('pdn_consent_at')
                ->where('pdn_consent_version', config('legal.version'))
                ->exists();

            if (! $hasGlobalConsent) {
                return ['result' => 'not_found'];
            }

            $statusAllowed = in_array($fresh->status, [
                AppointmentStatus::Booked,
                AppointmentStatus::PendingPayment,
            ], true);

            // Повтор того же подтверждения (действующая запись уже
            // закреплена за этой карточкой): отвечаем успехом без
            // повторных эффектов — без повторного broadcast и уведомления
            // мастера. Отменённая/недопустимая запись не получает
            // ответа об успешном подтверждении.
            if ($fresh->client_id !== null) {
                return ($statusAllowed && (string) $fresh->client_id === (string) $client->id)
                    ? ['result' => 'already']
                    : ['result' => 'not_found'];
            }

            if (! $statusAllowed) {
                return ['result' => 'unopenable'];
            }

            // Просроченный черновик (created_at старше cleanup-порога)
            // не подтверждается, даже если cron ещё не отменил запись.
            if ($this->isDraftPastCleanupThreshold($fresh)) {
                return ['result' => 'expired'];
            }

            if ($client->isBlocked()) {
                // Удаляется только пока не подтверждённая запись:
                // чужое/запоздалое подтверждение не трогает занятую запись.
                $fresh->delete();

                return ['result' => 'blocked'];
            }

            // Захват только будущей незанятой записи в допустимом статусе
            // (строка уже под блокировкой, повторная сверка — страховка).
            $affected = Appointment::query()
                ->whereKey($appointmentId)
                ->whereNull('client_id')
                ->whereIn('status', [
                    AppointmentStatus::Booked,
                    AppointmentStatus::PendingPayment,
                ])
                ->where('start_time', '>', now())
                ->update([
                    'client_id' => $client->id,
                    'source' => AppointmentSource::Max,
                ]);

            if ($affected === 0) {
                return ['result' => 'not_found'];
            }

            // Outbox: подтверждение брони — в той же транзакции, что и
            // захват. answerCallback ниже только гасит callback; текст
            // доставит job через общую дедупликацию.
            $fresh->refresh();
            $fresh->loadMissing(['client', 'master', 'masterService.catalog']);
            $recorder = app(AppointmentNotificationRecorder::class);
            $version = $recorder->bumpVersion($fresh);
            $recorder->record(
                $fresh,
                AppointmentNotificationEventType::BookingConfirmed,
                [],
                $version,
            );

            $phone = $client->phone ?? __('bot.fallback.phone');
            $clientName = $client->name ?? __('bot.fallback.client_name');

            return ['result' => 'ok'];
        });

        if ($state['result'] === 'not_found') {
            $this->clearDraftIfMatches($userId, (string) $appointmentId);
            $this->refuseBookingCallback($userId, $callbackId, __('bot.errors.appointment_not_found'));

            return;
        }

        if ($state['result'] === 'unopenable') {
            $this->clearDraftIfMatches($userId, (string) $appointmentId);
            $this->refuseBookingCallback($userId, $callbackId, __('bot.errors.appointment_not_found'));

            return;
        }

        if ($state['result'] === 'expired') {
            $this->clearDraftIfMatches($userId, (string) $appointmentId);
            $this->refuseBookingCallback($userId, $callbackId, __('bot.errors.booking_expired'));

            return;
        }

        if ($state['result'] === 'blocked') {
            $this->clearDraftIfMatches($userId, (string) $appointmentId);
            $this->refuseBookingCallback($userId, $callbackId, __('bot.errors.booking_unavailable'));

            return;
        }

        $master = $appointment->master;
        $tz = $master->getTimezone();
        $date = $appointment->start_time->timezone($tz)->format('d.m.Y');
        $time = $appointment->start_time->timezone($tz)->format('H:i');

        // HTTP к мессенджерам и broadcast — только после коммита.
        if ($state['result'] === 'ok') {
            $appointment->refresh();

            broadcast(new AppointmentCreated($appointment->load(['client'])));

            $lockKey = 'master_notified_'.$appointment->id;

            if (Cache::add($lockKey, true, now()->addMinutes(10))) {
                app(MasterNotificationService::class)
                    ->sendToMaster($master, __('bot.master.new_booking', [
                        'client' => $clientName,
                        'phone' => $phone,
                        'service' => $appointment->display_name,
                        'date' => $date,
                        'time' => $time,
                    ]));
            }
        }

        $this->clearDraftIfMatches($userId, (string) $appointmentId);

        // Текст подтверждения доставляет outbox-job (BookingConfirmed).
        // Здесь только гасим callback, чтобы не было второй отправки.
        $this->maxApi->answerCallback($callbackId);

        Log::info('[MAX] booking confirmed', [
            'user_id' => $userId,
            'appointment_id' => $appointmentId,
            'result' => $state['result'],
        ]);
    }

    private function cancelMaxBooking(string $userId, string $callbackId, string $appointmentId): void
    {
        $appointment = Appointment::with(['master'])->find($appointmentId);

        if (! $appointment) {
            $this->refuseBookingCallback($userId, $callbackId, __('bot.errors.appointment_not_found'));

            return;
        }

        $draftId = Cache::get(CacheKeys::MAX_BOOKING_DRAFT.$userId);
        if ((string) $draftId !== (string) $appointmentId) {
            $this->refuseBookingCallback($userId, $callbackId, __('bot.errors.appointment_not_found'));

            return;
        }

        $client = Client::byMaxId($userId)
            ->where('user_id', $appointment->master_id)
            ->first();

        if (! $client) {
            $this->refuseBookingCallback($userId, $callbackId, __('bot.errors.appointment_not_found'));

            return;
        }

        // Состояние перечитывается под блокировкой перед мутацией:
        // запись могла устареть/отмениться/быть оформлена, пока
        // сообщение с кнопками уже было показано.
        $state = DB::transaction(function () use ($appointment) {
            $fresh = Appointment::query()
                ->whereKey($appointment->id)
                ->lockForUpdate()
                ->first();

            if ($fresh === null) {
                return 'not_found';
            }

            if ($fresh->status === AppointmentStatus::Cancelled) {
                return 'already_cancelled';
            }

            // Занятая/недопустимая запись — generic-отказ, даже если
            // срок уже истёк: booking_expired только для допустимого
            // незанятого черновика.
            if ($fresh->client_id !== null
                || ! in_array($fresh->status, [AppointmentStatus::Booked, AppointmentStatus::PendingPayment], true)
            ) {
                return 'not_found';
            }

            if ($fresh->start_time->isPast()
                || $this->isDraftPastCleanupThreshold($fresh)
            ) {
                return 'expired';
            }

            app(AppointmentStatusService::class)->transition(
                $fresh,
                AppointmentStatus::Cancelled,
            );

            return 'cancelled';
        });

        if ($state === 'not_found') {
            $this->clearDraftIfMatches($userId, (string) $appointmentId);
            $this->refuseBookingCallback($userId, $callbackId, __('bot.errors.appointment_not_found'));

            return;
        }

        if ($state === 'expired') {
            $this->clearDraftIfMatches($userId, (string) $appointmentId);
            $this->refuseBookingCallback($userId, $callbackId, __('bot.errors.booking_expired'));

            return;
        }

        if ($state === 'already_cancelled') {
            $this->clearDraftIfMatches($userId, (string) $appointmentId);
            $this->maxApi->answerCallback($callbackId, __('bot.booking_cancelled.reply'));

            return;
        }

        $this->clearDraftIfMatches($userId, (string) $appointmentId);

        $ok = $this->maxApi->answerCallbackWithMessage(
            $callbackId,
            __('bot.booking_cancelled.reply'),
        );
        if (! $ok) {
            $this->maxApi->answerCallback($callbackId);
            $this->sendMessage($userId, __('bot.booking_cancelled.reply'));
        }

        Log::info('[MAX] booking cancelled', [
            'user_id' => $userId,
            'appointment_id' => $appointmentId,
        ]);
    }

    private function confirmMaxVisit(string $userId, string $callbackId, string $appointmentId): void
    {
        $appointment = Appointment::with(['master', 'client'])->find($appointmentId);

        if (! $appointment) {
            $this->maxApi->answerCallback($callbackId, __('bot.errors.appointment_not_found'));

            return;
        }

        $client = Client::byMaxId($userId)
            ->where('user_id', $appointment->master_id)
            ->first();

        if (! $client || $appointment->client_id !== $client->id) {
            Log::warning('[MAX] confirmMaxVisit: ownership violation', [
                'user_id' => $userId,
                'appointment_id' => $appointmentId,
            ]);
            $this->maxApi->answerCallback($callbackId, __('bot.errors.appointment_not_found'));

            return;
        }

        $result = app(AppointmentVisitConfirmationService::class)
            ->confirm($appointment, $client);

        match ($result['result']) {
            'not_available' => $this->maxApi->answerCallback($callbackId, __('bot.visit_confirm.not_available')),
            'already' => $this->maxApi->answerCallback($callbackId, __('bot.visit_confirm.already')),
            'ok' => $this->handleConfirmSuccess($userId, $callbackId, $appointmentId),
            default => null,
        };
    }

    private function handleConfirmSuccess(string $userId, string $callbackId, string $appointmentId): void
    {
        Log::info('[MAX] confirmMaxVisit: success', [
            'user_id' => $userId,
            'appointment_id' => $appointmentId,
        ]);

        $ok = $this->maxApi->answerCallbackWithMessage(
            $callbackId,
            __('bot.visit_confirm.client_thanks')
        );
        if (! $ok) {
            $this->maxApi->answerCallback($callbackId);
            $this->sendMessage($userId, __('bot.visit_confirm.client_thanks'));
        }
    }

    private function handleAutofillAccept(string $userId, string $callbackId, string $offerUuid, string $messageId): void
    {
        $offer = SlotOffer::with(['request.client'])->find($offerUuid);

        if ($offer === null) {
            $this->maxApi->answerCallback($callbackId, 'Предложение недоступно');

            return;
        }

        $client = $offer->request?->client;
        if ($client === null || $client->max_id !== $userId) {
            Log::warning('[MAX] autofill accept: ownership violation', [
                'user_id' => $userId,
                'offer_id' => $offerUuid,
            ]);
            $this->maxApi->answerCallback($callbackId, 'Предложение недоступно');

            return;
        }

        $result = app(SlotOfferAcceptanceService::class)->acceptEarlier($offer);

        if ($result['success']) {
            $ok = $this->maxApi->answerCallbackWithMessage($callbackId, 'Готово, запись перенесена.');
            if (! $ok) {
                $this->maxApi->answerCallback($callbackId);
                $this->sendMessage($userId, 'Готово, запись перенесена.');
            }
            $this->removeKeyboardBestEffort($messageId);

            return;
        }

        $error = $result['error'] ?? 'unknown';

        $message = match ($error) {
            'slot_taken', 'slot_unavailable' => 'Это время уже заняли. Продолжим искать подходящее время.',
            'request_not_active', 'not_earlier_type', 'source_appointment_missing',
            'source_not_booked', 'client_mismatch', 'workspace_mismatch',
            'master_mismatch', 'master_service_mismatch', 'snapshot_missing',
            'snapshot_drift', 'invalid_duration', 'duration_mismatch',
            'not_earlier', 'service_inactive', 'client_conflict',
            'opportunity_not_open', 'opportunity_past', 'missing_relations' => 'Поиск по этой записи уже неактуален.',
            'expired', 'not_pending' => 'Предложение уже недоступно.',
            default => 'Предложение уже недоступно.',
        };

        $ok = $this->maxApi->answerCallbackWithMessage($callbackId, $message);
        if (! $ok) {
            $this->maxApi->answerCallback($callbackId);
            $this->sendMessage($userId, $message);
        }
        $this->removeKeyboardBestEffort($messageId);
    }

    private function handleAutofillDecline(string $userId, string $callbackId, string $offerUuid, string $messageId): void
    {
        $offer = SlotOffer::with(['request.client'])->find($offerUuid);

        if ($offer === null) {
            $this->maxApi->answerCallback($callbackId, 'Предложение недоступно');

            return;
        }

        $client = $offer->request?->client;
        if ($client === null || $client->max_id !== $userId) {
            Log::warning('[MAX] autofill decline: ownership violation', [
                'user_id' => $userId,
                'offer_id' => $offerUuid,
            ]);
            $this->maxApi->answerCallback($callbackId, 'Предложение недоступно');

            return;
        }

        $freshOffer = SlotOffer::find($offerUuid);

        if ($freshOffer === null || $freshOffer->status !== SlotOfferStatus::Pending) {
            $status = $freshOffer?->status?->value ?? 'missing';
            $message = $status === 'accepted'
                ? 'Запись уже перенесена.'
                : 'Предложение уже недоступно.';

            $ok = $this->maxApi->answerCallbackWithMessage($callbackId, $message);
            if (! $ok) {
                $this->maxApi->answerCallback($callbackId);
                $this->sendMessage($userId, $message);
            }

            return;
        }

        try {
            app(SlotOfferService::class)->decline($freshOffer);
        } catch (\Throwable $e) {
            Log::warning('[MAX] autofill decline: service error', [
                'offer_id' => $offerUuid,
                'error' => $e->getMessage(),
            ]);
            $this->maxApi->answerCallback($callbackId, 'Предложение уже недоступно');

            return;
        }

        $opportunityId = $freshOffer->slot_opportunity_id;
        DB::afterCommit(
            fn () => MatchSlotOpportunityJob::dispatch($opportunityId),
        );

        $ok = $this->maxApi->answerCallbackWithMessage($callbackId, 'Хорошо, это время не подойдёт. Продолжим искать.');
        if (! $ok) {
            $this->maxApi->answerCallback($callbackId);
            $this->sendMessage($userId, 'Хорошо, это время не подойдёт. Продолжим искать.');
        }
        $this->removeKeyboardBestEffort($messageId);
    }

    private function removeKeyboardBestEffort(string $messageId): void
    {
        if ($messageId === '') {
            return;
        }

        try {
            $this->maxApi->deleteKeyboard($messageId);
        } catch (\Throwable $e) {
            Log::debug('[MAX] removeKeyboardBestEffort failed', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Будущая незанятая запись в статусе Booked/PendingPayment и не
     * старше cleanup_draft_threshold — единственный допустимый
     * кандидат для открытия/продолжения сценария подтверждения
     * брони через MAX.
     */
    private function isConfirmableDraft(Appointment $appointment): bool
    {
        return $appointment->client_id === null
            && in_array($appointment->status, [
                AppointmentStatus::Booked,
                AppointmentStatus::PendingPayment,
            ], true)
            && $appointment->start_time->isFuture()
            && ! $this->isDraftPastCleanupThreshold($appointment);
    }

    /**
     * Просроченный черновик: created_at строго старше порога очистки —
     * та же строгая граница (created_at < now - threshold), что у
     * CleanupDraftAppointments. Ровно на пороге черновик ещё допустим;
     * кэш MAX_BOOKING_DRAFT при этом не является доменным сроком.
     */
    private function isDraftPastCleanupThreshold(Appointment $appointment): bool
    {
        $createdAt = $appointment->created_at;

        if ($createdAt === null) {
            return false;
        }

        return $createdAt->lt(now()->subMinutes(config('booking.cleanup_draft_threshold')));
    }

    /**
     * Черновик запоминается только для допустимой записи. Повторное
     * открытие той же ссылки не продлевает исходный TTL: если черновик
     * уже указывает на эту запись — не трогаем кэш. Вся пара
     * get→put выполняется под общим per-user локом: параллельная
     * замена/очистка не пересекается с условной записью.
     */
    private function rememberBookingDraft(string $userId, string $appointmentId): void
    {
        $this->bookingDraftLock($userId)->block(5, function () use ($userId, $appointmentId): void {
            $existing = Cache::get(CacheKeys::MAX_BOOKING_DRAFT.$userId);
            if ($existing !== null && (string) $existing === $appointmentId) {
                return;
            }

            Cache::put(
                CacheKeys::MAX_BOOKING_DRAFT.$userId,
                $appointmentId,
                config('booking.draft_ttl'),
            );
        });
    }

    /**
     * Очищает только черновик текущей записи: устаревшая ссылка/кнопка A
     * не должна затронуть актуальный черновик B того же пользователя.
     * Пара get→forget атомарна под общим per-user локом: запоздалое
     * условное удаление не стирает черновик, заменённый параллельным
     * запросом между чтением и удалением.
     */
    private function clearDraftIfMatches(string $userId, string $appointmentId): void
    {
        $this->bookingDraftLock($userId)->block(5, function () use ($userId, $appointmentId): void {
            $draftId = Cache::get(CacheKeys::MAX_BOOKING_DRAFT.$userId);
            if ($draftId !== null && (string) $draftId === (string) $appointmentId) {
                Cache::forget(CacheKeys::MAX_BOOKING_DRAFT.$userId);
            }
        });
    }

    /**
     * Общий per-user лок для всех условных мутаций черновика
     * MAX_BOOKING_DRAFT: и условное удаление, и условная запись
     * берут одну и ту же блокировку — лок только удаления недостаточен.
     */
    private function bookingDraftLock(string $userId): Lock
    {
        return Cache::lock(CacheKeys::MAX_BOOKING_DRAFT_LOCK.$userId, 10);
    }

    /**
     * Завершает callback и всегда отправляет явное сообщение в чат:
     * доставка сообщения не зависит от успеха answerCallback, а
     * notification в /answers не гарантирует видимое сообщение.
     */
    private function refuseBookingCallback(string $userId, string $callbackId, string $text): void
    {
        $this->maxApi->answerCallback($callbackId);
        $this->sendMessage($userId, $text);
    }

    /**
     * Ответ на недопустимую ссылку/кнопку без кнопок подтверждения и
     * без мутаций. Уже оформленная будущая запись при доказанной
     * принадлежности получает отдельное сообщение; чужие данные не
     * раскрываются.
     */
    private function sendUnopenableBookingMessage(string $userId, Appointment $appointment): void
    {
        $isActiveStatus = in_array($appointment->status, [
            AppointmentStatus::Booked,
            AppointmentStatus::PendingPayment,
            AppointmentStatus::Prepaid,
            AppointmentStatus::Paid,
        ], true);

        // Уже оформленная будущая запись (не отменённая): при
        // доказанной принадлежности — отдельное сообщение; чужие
        // данные не раскрываются.
        if ($appointment->client_id !== null
            && $appointment->start_time->isFuture()
            && $isActiveStatus
        ) {
            $client = Client::byMaxId($userId)
                ->where('user_id', $appointment->master_id)
                ->first();

            if ($client !== null && (string) $client->id === (string) $appointment->client_id) {
                $this->sendMessage($userId, __('bot.errors.booking_already_confirmed'));

                return;
            }

            $this->sendMessage($userId, __('bot.errors.appointment_not_found'));

            return;
        }

        $this->sendMessage($userId, __('bot.errors.booking_expired'));
    }
}
