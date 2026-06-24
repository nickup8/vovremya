<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    private string $telegramBotToken;

    public function __construct()
    {
        $this->telegramBotToken = config('services.telegram.bot_token', '');
    }

    /**
     * Обработка входящих обновлений от Telegram-бота.
     *
     * Сценарии:
     * 1. /start book_X — бот запрашивает контакт
     * 2. message.contact — привязка клиента к записи + предоплата/подтверждение
     * 3. callback_query — обработка нажатия инлайн-кнопок
     */
    public function handleTelegram(Request $request): JsonResponse
    {
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
     * Обработка входящих обновлений от Max-бота.
     */
    public function handleMax(Request $request): JsonResponse
    {
        $payload = $request->all();

        $event = $payload['event'] ?? null;
        $data = $payload['data'] ?? [];

        if ($event === 'message_created') {
            $text = $data['body'] ?? '';
            $chatId = $data['chat']['id'] ?? null;

            if (str_starts_with($text, '/start book_')) {
                return $this->handleStartBook($chatId, $text, 'max');
            }

            $contact = $data['contact'] ?? null;
            if ($contact) {
                return $this->handleContact($chatId, $contact, 'max');
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Обработка команды /start book_X — отправка клавиатуры request_contact.
     */
    private function handleStartBook(?int $chatId, string $text, string $provider): JsonResponse
    {
        $appointmentId = (int) str_replace('/start book_', '', $text);

        $appointment = Appointment::with(['master', 'service'])
            ->where('id', $appointmentId)
            ->where('status', 'pending_client')
            ->first();

        if (! $appointment) {
            return response()->json(['ok' => true]);
        }

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

    /**
     * Обработка полученного контакта — UPSERT клиента + привязка к записи + логика предоплаты.
     */
    private function handleContact(?int $chatId, array $contact, string $provider): JsonResponse
    {
        $rawPhone = $contact['phone_number'] ?? $contact['phone'] ?? '';
        $phone = $this->cleanPhone($rawPhone);
        $telegramId = (string) ($contact['user_id'] ?? $contact['from']['id'] ?? '');
        $firstName = $contact['first_name'] ?? $contact['first_name'] ?? 'Клиент';

        $pendingId = session('pending_telegram_appointment_id');
        if (! $pendingId) {
            $this->sendMessage($chatId, 'Не удалось найти активную запись. Попробуйте записаться заново.', $provider);

            return response()->json(['ok' => true]);
        }

        $appointment = Appointment::with(['master', 'service'])
            ->where('id', $pendingId)
            ->where('status', 'pending_client')
            ->first();

        if (! $appointment) {
            session()->forget('pending_telegram_appointment_id');
            $this->sendMessage($chatId, 'Запись уже обработана или аннулирована.', $provider);

            return response()->json(['ok' => true]);
        }

        $masterId = $appointment->master_id;

        $client = Client::updateOrCreate(
            ['user_id' => $masterId, 'phone' => $phone],
            [
                'telegram_id' => $telegramId,
                'name' => $firstName,
            ]
        );

        $appointment->update(['client_id' => $client->id]);
        session()->forget('pending_telegram_appointment_id');

        $master = $appointment->master;
        $service = $appointment->service;
        $date = $appointment->start_time->format('d.m.Y');
        $time = $appointment->start_time->format('H:i');

        if ($master->soft_deposit) {
            $depositAmount = round($service->price * $master->deposit_percent / 100);
            $timeout = $master->deposit_timeout;

            $message = "Для завершения записи к {$master->name} необходимо внести предоплату {$master->deposit_percent}% ({$depositAmount}₽) в течение {$timeout} мин.\n\n"
                ."Услуга: {$service->title}\n"
                ."Дата: {$date} в {$time}\n"
                ."Сумма к оплате: {$depositAmount}₽\n\n"
                ."Реквизиты для перевода будут отправлены следующим сообщением.";

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
                            'url' => config('app.url')."/my-bookings",
                        ],
                    ],
                ],
            ];

            $this->sendMessage($chatId, $message, $provider, $inlineKeyboard);
        } else {
            $appointment->update(['status' => 'confirmed']);

            $message = "Вы успешно записаны к {$master->name}!\n\n"
                ."Услуга: {$service->title}\n"
                ."Дата: {$date} в {$time}\n"
                ."Стоимость: {$service->price}₽\n\n"
                ."Ждём вас!}";

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

    /**
     * Обработка нажатия инлайн-кнопок.
     */
    private function handleCallback(?int $chatId, string $data, array $message): JsonResponse
    {
        return response()->json(['ok' => true]);
    }

    /**
     * Очистка телефона: убираем все нецифровые символы, приводим к формату без +.
     */
    private function cleanPhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }

    /**
     * Отправка сообщения через Telegram Bot API.
     */
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
            Log::info('Max bot message (stub)', ['chat_id' => $chatId, 'text' => $text]);
        }
    }
}
