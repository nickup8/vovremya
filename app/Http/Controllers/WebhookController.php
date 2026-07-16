<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Services\AppointmentStatusService;
use App\Services\Client\ClientMergeService;
use App\Services\MaxApiClient;
use App\Webhooks\MaxWebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use DefStudio\Telegraph\Models\TelegraphBot;

class WebhookController extends Controller
{
    private ?string $telegramBotToken;

    public function __construct(
        private ClientMergeService $clientMergeService,
        private AppointmentStatusService $statusService,
        private MaxApiClient $maxApi,
    ) {
        $this->telegramBotToken = config('services.telegram.bot_token');
    }

    public function handleTelegram(Request $request): JsonResponse
    {
        $this->verifySignature('telegram', $request);

        $payload = $request->all();

        $message = $payload['message'] ?? $payload['callback_query'] ?? null;
        if (! $message) {
            return response()->json(['ok' => true]);
        }

        $chatId = $message['chat']['id'] ?? $message['message']['chat']['id'] ?? null;
        $text = $message['text'] ?? '';
        $contact = $message['contact'] ?? null;

        if (str_starts_with($text, '/start book_')) {
            return $this->handleStartBook($chatId, $text, 'telegram');
        }

        if ($contact) {
            return $this->handleContact($chatId, $contact, 'telegram');
        }

        $callbackData = $message['data'] ?? null;
        if ($callbackData) {
            return $this->handleCallback($chatId, $callbackData, $message);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Кастомный эндпоинт для обхода Route Model Binding пакета Telegraph.
     * Ищет бота вручную и делегирует обработку TelegramWebhookHandler.
     */
    public function handleBypass(Request $request)
    {
        $secret = config('services.telegram.secret_token');

        if (! empty($secret)) {
            $provided = $request->header('X-Telegram-Bot-Api-Secret-Token');

            if ($provided === null || ! hash_equals($secret, $provided)) {
                Log::warning('Bypass webhook: invalid secret token', [
                    'ip' => $request->ip(),
                ]);
                abort(403, 'Invalid secret token');
            }
        }

        Log::info('Bypass Webhook:', $request->all());

        $bot = TelegraphBot::first();

        if (! $bot) {
            return response('Bot not found', 404);
        }

        app(\App\Webhooks\TelegramWebhookHandler::class)->handle($request, $bot);

        return response('OK', 200);
    }

    public function handleMax(Request $request): JsonResponse
    {
        $this->verifySignature('max', $request);

        $payload = $request->all();

        Log::info('[MAX] webhook payload', [
            'update_type' => $payload['update_type'] ?? null,
            'chat_id' => $payload['chat_id'] ?? null,
        ]);

        app(MaxWebhookHandler::class)->handle($payload);

        return response()->json(['ok' => true]);
    }

    public function handleVk(Request $request): mixed
    {
        $payload = $request->all();
        $type = $payload['type'] ?? '';
        $secret = $payload['secret'] ?? null;

        if ($type === 'confirmation') {
            Log::info('[VK] confirmation request received');

            return response(config('services.vk.confirmation_token'), 200)
                ->header('Content-Type', 'text/plain');
        }

        $expectedSecret = config('services.vk.secret');
        if ($expectedSecret && $secret !== $expectedSecret) {
            Log::warning('[VK] invalid secret', [
                'ip' => $request->ip(),
            ]);

            return response('Forbidden', 403);
        }

        Log::info('[VK] webhook received', [
            'type' => $type,
            'group_id' => $payload['group_id'] ?? null,
        ]);

        return response('ok', 200);
    }

    private function verifySignature(string $provider, Request $request): void
    {
        $secretKey = match ($provider) {
            'telegram' => 'services.telegram.secret_token',
            'max' => 'services.max.secret_token',
        };

        $secret = config($secretKey);

        if (empty($secret)) {
            if ($provider === 'max') {
                Log::info("[MAX] webhook secret not configured, skipping signature verification");

                return;
            }

            Log::critical("Webhook secret not configured for {$provider}");
            abort(500, 'Webhook secret not configured');
        }

        $headerName = match ($provider) {
            'telegram' => 'X-Telegram-Bot-Api-Secret-Token',
            'max' => 'X-Max-Signature',
        };

        $provided = $request->header($headerName);

        if ($provided === null || $provided === '') {
            Log::warning("Webhook signature missing for {$provider}", [
                'ip' => $request->ip(),
            ]);
            abort(403, 'Webhook signature required');
        }

        if (! hash_equals((string) $secret, $provided)) {
            Log::warning("Webhook signature mismatch for {$provider}", [
                'ip' => $request->ip(),
            ]);
            abort(403, 'Invalid webhook signature');
        }
    }

    private function handleStartBook(?int $chatId, string $text, string $provider): JsonResponse
    {
        $appointmentId = str_replace('/start book_', '', $text);

        if ($appointmentId === '' || $appointmentId === $text) {
            return response()->json(['ok' => true]);
        }

        $appointment = Appointment::with(['master', 'service'])
            ->where('id', $appointmentId)
            ->where('status', AppointmentStatus::Booked)
            ->first();

        if (! $appointment) {
            return response()->json(['ok' => true]);
        }

        $cacheKey = "bot_pending:{$provider}:{$chatId}";
        Cache::put($cacheKey, $appointment->id, now()->addMinutes(15));

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

        $this->sendMessage(
            $chatId,
            "Запись к {$appointment->master->name} на ".$appointment->start_time->format('d.m.Y H:i').".\n\nПоделитесь номером телефона для подтверждения.",
            $provider,
            $keyboard
        );

        return response()->json(['ok' => true]);
    }

    private function handleContact(?int $chatId, array $contact, string $provider): JsonResponse
    {
        $rawPhone = $contact['phone_number'] ?? $contact['phone'] ?? '';
        $phone = $this->cleanPhone($rawPhone);
        $telegramId = (string) ($contact['user_id'] ?? $contact['from']['id'] ?? '');
        $firstName = $contact['first_name'] ?? 'Клиент';

        $cacheKey = "bot_pending:{$provider}:{$chatId}";
        $pendingId = Cache::get($cacheKey);
        if (! $pendingId) {
            $this->sendMessage($chatId, 'Не удалось найти активную запись. Попробуйте записаться заново.', $provider);

            return response()->json(['ok' => true]);
        }

        $appointment = Appointment::with(['master', 'service'])
            ->where('id', $pendingId)
            ->where('status', AppointmentStatus::Booked)
            ->first();

        if (! $appointment) {
            Cache::forget($cacheKey);
            $this->sendMessage($chatId, 'Запись уже обработана или аннулирована.', $provider);

            return response()->json(['ok' => true]);
        }

        $masterId = $appointment->master_id;

        $client = $this->clientMergeService->findOrCreateByPhone(
            $masterId,
            $phone,
            $telegramId,
            $firstName,
        );

        if ($client->isBlocked()) {
            $appointment->delete();
            Cache::forget($cacheKey);
            $this->sendMessage($chatId, 'К сожалению, запись к этому мастеру недоступна.', $provider);

            return response()->json(['ok' => true]);
        }

        $appointment->update(['client_id' => $client->id]);
        Cache::forget($cacheKey);

        $master = $appointment->master;
        $service = $appointment->service;
        $date = $appointment->start_time->format('d.m.Y');
        $time = $appointment->start_time->format('H:i');

        if ($master->getBookingFlowType() === 'prepayment_custom') {
            $depositAmount = round($service->price * $master->deposit_percent / 100);
            $timeout = $master->deposit_timeout;

            $customMessage = $master->getCustomPrepaymentMessage();

            if ($customMessage) {
                $message = $customMessage."\n\n"
                    ."Сумма предоплаты: {$depositAmount}₽ ({$master->deposit_percent}% от {$service->price}₽)\n"
                    ."Время на оплату: {$timeout} мин.";
            } else {
                $message = "Для завершения записи к {$master->name} необходимо внести предоплату {$master->deposit_percent}% ({$depositAmount}₽) в течение {$timeout} мин.\n\n"
                    ."Услуга: {$service->title}\n"
                    ."Дата: {$date} в {$time}\n"
                    ."Сумма к оплате: {$depositAmount}₽\n\n"
                    .'Реквизиты для перевода будут отправлены следующим сообщением.';
            }

            $inlineKeyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '💰 Перейти к оплате',
                            'url' => config('app.url')."/book/status/{$appointment->id}",
                        ],
                    ],
                    [
                        [
                            'text' => '📅 Мои записи',
                            'url' => config('app.url').'/my-bookings',
                        ],
                    ],
                ],
            ];

            $this->sendMessage($chatId, $message, $provider, $inlineKeyboard);
        } else {
            if ($appointment->status !== AppointmentStatus::Booked) {
                $this->statusService->transition($appointment, AppointmentStatus::Booked);
            }

            $message = "Вы успешно записаны к {$master->name}!\n\n"
                ."Услуга: {$service->title}\n"
                ."Дата: {$date} в {$time}\n"
                ."Стоимость: {$service->price}₽\n\n"
                .'Ждём вас!';

            $this->sendMessage($chatId, $message, $provider);
        }

        $authToken = Client::generateAuthToken();
        $client->update(['auth_token' => $authToken]);

        $loginUrl = config('app.url')."/client/auth/{$authToken}";

        $this->sendMessage(
            $chatId,
            "👤 Ваш профиль создан. Откройте ссылку для входа в личный кабинет:\n{$loginUrl}",
            $provider
        );

        return response()->json(['ok' => true]);
    }

    private function handleCallback(?int $chatId, string $data, array $message): JsonResponse
    {
        if (str_starts_with($data, 'confirm_')) {
            $appointmentId = (int) str_replace('confirm_', '', $data);

            $appointment = Appointment::with(['master', 'service'])
                ->where('id', $appointmentId)
                ->first();

            if (! $appointment) {
                return response()->json(['ok' => true]);
            }

            if ($appointment->status->canTransitionTo(AppointmentStatus::Booked)) {
                $this->statusService->transition($appointment, AppointmentStatus::Booked);

                $service = $appointment->service;
                $master = $appointment->master;
                $date = $appointment->start_time->format('d.m.Y');
                $time = $appointment->start_time->format('H:i');

                $this->sendMessage(
                    $chatId,
                    "✅ Запись подтверждена!\n\n"
                    ."Услуга: {$service->title}\n"
                    ."Дата: {$date} в {$time}\n"
                    ."Мастер: {$master->name}\n\n"
                    .'Ждём вас!',
                    $this->detectProvider($message)
                );
            }
        }

        if (str_starts_with($data, 'cancel_')) {
            $appointmentId = (int) str_replace('cancel_', '', $data);

            $appointment = Appointment::find($appointmentId);

            if ($appointment && $appointment->status->canTransitionTo(AppointmentStatus::Cancelled)) {
                $this->statusService->transition($appointment, AppointmentStatus::Cancelled);

                $this->sendMessage(
                    $chatId,
                    '❌ Запись отменена.',
                    $this->detectProvider($message)
                );
            }
        }

        return response()->json(['ok' => true]);
    }

    private function detectProvider(array $message): string
    {
        $chatType = $message['chat']['type'] ?? '';

        if (str_contains($chatType, 'max')) {
            return 'max';
        }

        return 'telegram';
    }

    private function cleanPhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }

    private function sendMessage(?int $chatId, string $text, string $provider, ?array $extra = null): void
    {
        if (! $chatId) {
            return;
        }

        if ($provider === 'telegram' && $this->telegramBotToken) {
            $payload = [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ];

            if ($extra) {
                $payload = array_merge($payload, $extra);
            }

            try {
                Http::timeout(10)
                    ->post("https://api.telegram.org/bot{$this->telegramBotToken}/sendMessage", $payload);
            } catch (\Exception $e) {
                Log::error('Telegram send failed', ['error' => $e->getMessage(), 'chat_id' => $chatId]);
            }
        }

        if ($provider === 'max') {
            $extra = $extra ?? [];
            $this->maxApi->sendMessage((string) $chatId, $text, $extra);
        }
    }
}
