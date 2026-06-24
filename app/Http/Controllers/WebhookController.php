<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    /**
     * Обработка входящих обновлений от Telegram-бота.
     *
     * Алгоритм:
     * 1. Бот получает команду /start book_X (где X — ID записи).
     * 2. Бот отправляет клавиатуру ReplyKeyboardMarkup с кнопкой
     *    request_contact = true и текстом "Поделиться номером телефона".
     * 3. При получении контакта бот вытягивает телефон, делает UPSERT
     *    в таблицу users по phone, связывает с appointment_id
     *    (обновляет client_id) и присылает клиенту сообщение с
     *    инлайн-кнопкой "Подтвердить запись" со ссылкой на
     *    /my-bookings или экран предоплаты.
     */
    public function handleTelegram(Request $request): JsonResponse
    {
        $payload = $request->all();

        $message = $payload['message'] ?? $payload['callback_query'] ?? null;
        if (! $message) {
            return response()->json(['ok' => true]);
        }

        $chatId = $message['chat']['id'] ?? null;
        $text = $message['text'] ?? '';
        $contact = $message['contact'] ?? null;

        if (str_starts_with($text, '/start book_')) {
            $appointmentId = (int) str_replace('/start book_', '', $text);
            $appointment = Appointment::find($appointmentId);

            if (! $appointment || $appointment->status !== 'pending_client') {
                return response()->json(['ok' => true]);
            }

            // TODO: Отправить клавиатуру с request_contact
            // BotApi::sendMessage($chatId, 'Поделитесь номером телефона для подтверждения записи.', [
            //     'reply_markup' => json_encode([
            //         'keyboard' => [[['text' => '📱 Поделиться номером', 'request_contact' => true]]],
            //         'resize_keyboard' => true,
            //         'one_time_keyboard' => true,
            //     ]),
            // ]);

            return response()->json(['ok' => true]);
        }

        if ($contact) {
            $phone = $contact['phone_number'];
            $phoneE164 = str_starts_with($phone, '+') ? $phone : '+'.$phone;

            $client = User::firstOrCreate(
                ['phone' => $phoneE164],
                ['name' => $contact['first_name'] ?? 'Клиент '.$phoneE164]
            );

            // Привязать клиента к записи из сессии
            $appointmentId = session('pending_telegram_appointment_id');
            if ($appointmentId) {
                Appointment::where('id', $appointmentId)
                    ->whereNull('client_id')
                    ->update(['client_id' => $client->id]);

                session()->forget('pending_telegram_appointment_id');
            }

            // TODO: Отправить инлайн-кнопку "Подтвердить запись"
            // BotApi::sendMessage($chatId, 'Запись подтверждена! Ждём вас.', [
            //     'reply_markup' => json_encode([
            //         'inline_keyboard' => [[['text' => '📅 Мои записи', 'url' => '...']]],
            //     ]),
            // ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Обработка входящих обновлений от Max-бота.
     *
     * Алгоритм аналогичен Telegram:
     * 1. Бот получает команду /start book_X (где X — ID записи).
     * 2. Бот отправляет клавиатуру с кнопкой request_contact и
     *    текстом "Поделиться номером телефона".
     * 3. При получении контакта бот вытягивает телефон, делает UPSERT
     *    в таблицу users по phone, связывает с appointment_id
     *    (обновляет client_id) и присылает клиенту сообщение с
     *    инлайн-кнопкой "Подтвердить запись" со ссылкой на
     *    /my-bookings или экран предоплаты.
     */
    public function handleMax(Request $request): JsonResponse
    {
        $payload = $request->all();

        $message = $payload['event'] ?? null;
        if (! $message) {
            return response()->json(['ok' => true]);
        }

        // TODO: Реализовать парсинг payload от Max API
        // Логика идентична handleTelegram:
        // 1. Если команда /start book_X — найти запись, попросить контакт
        // 2. Если получен контакт — привязать клиента к записи
        // 3. Отправить подтверждение

        return response()->json(['ok' => true]);
    }
}
