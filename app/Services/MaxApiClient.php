<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MaxApiClient
{
    private string $apiUrl;

    private string $token;

    private bool $configured;

    public function __construct()
    {
        $this->apiUrl = config('services.max.api_url', '');
        $this->token = config('services.max.bot_token', '');
        $this->configured = ! empty($this->apiUrl) && ! empty($this->token);

        if (! $this->configured) {
            Log::warning('MAX API config missing', [
                'api_url' => $this->apiUrl ?: '(empty)',
                'token' => $this->token ? '***' : '(empty)',
            ]);
        }
    }

    public function sendCallbackTestButton(string $chatId): bool
    {
        if (! $this->configured) {
            return false;
        }

        $payload = [
            'text' => 'Тест callback-кнопки. Нажмите кнопку ниже.',
            'attachments' => [[
                'type' => 'inline_keyboard',
                'payload' => [
                    'buttons' => [[
                        [
                            'type' => 'callback',
                            'text' => 'ТЕСТ callback',
                            'payload' => 'diag_test_123',
                        ],
                    ]],
                ],
            ]],
        ];

        try {
            $response = Http::withoutVerifying()
                ->withHeaders([
                    'Authorization' => $this->token,
                ])
                ->withQueryParameters(['user_id' => $chatId])
                ->timeout(10)
                ->post(rtrim($this->apiUrl, '/').'/messages', $payload);

            Log::info('[MAX] callback-test raw response', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('[MAX] sendCallbackTestButton exception', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function answerCallback(string $callbackId, string $notification = ''): bool
    {
        if (! $this->configured) {
            return false;
        }

        // MAX не принимает пустое тело {}; поле notification обязательно.
        $body = ['notification' => $notification];

        try {
            $response = Http::withoutVerifying()
                ->withHeaders(['Authorization' => $this->token])
                ->withQueryParameters(['callback_id' => $callbackId])
                ->timeout(10)
                ->post(rtrim($this->apiUrl, '/').'/answers', $body);

            Log::info('[MAX] answerCallback', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('[MAX] answerCallback exception', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function sendMessage(string $chatId, string $text, array $extra = []): ?string
    {
        if (! $this->configured) {
            return null;
        }

        $payload = array_merge(['text' => $text], $extra);

        try {
            $response = Http::withoutVerifying()
                ->withHeaders([
                    'Authorization' => $this->token,
                ])
                ->withQueryParameters(['user_id' => $chatId])
                ->timeout(10)
                ->post(rtrim($this->apiUrl, '/').'/messages', $payload);

            Log::info('[MAX] Raw Response', ['chat_id' => $chatId, 'status' => $response->status(), 'body' => $response->body()]);

            if ($response->failed()) {
                Log::error('[MAX] sendMessage failed', [
                    'chat_id' => $chatId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $mid = $response->json('message.body.mid');

            return $mid ? (string) $mid : null;
        } catch (\Exception $e) {
            Log::error('[MAX] sendMessage exception', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return null;
        }
    }

    public function editMessage(string $messageId, string $text, array $extra = []): bool
    {
        if (! $this->configured) {
            return false;
        }

        $payload = array_merge(['text' => $text], $extra);

        try {
            $response = Http::withoutVerifying()
                ->withHeaders(['Authorization' => $this->token])
                ->withQueryParameters(['message_id' => $messageId])
                ->timeout(10)
                ->put(rtrim($this->apiUrl, '/').'/messages', $payload);

            Log::info('[MAX] editMessage', [
                'message_id' => $messageId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('[MAX] editMessage exception', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function deleteKeyboard(string $messageId): bool
    {
        if (! $this->configured) {
            return false;
        }

        try {
            $response = Http::withoutVerifying()
                ->withHeaders(['Authorization' => $this->token])
                ->withQueryParameters(['message_id' => $messageId])
                ->timeout(10)
                ->put(rtrim($this->apiUrl, '/').'/messages', ['attachments' => []]);

            Log::info('[MAX] deleteKeyboard', [
                'message_id' => $messageId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('[MAX] deleteKeyboard exception', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function answerCallbackWithMessage(string $callbackId, string $text, array $extra = []): bool
    {
        if (! $this->configured) {
            return false;
        }

        $message = array_merge(['text' => $text], $extra);
        $body = ['message' => $message];

        try {
            $response = Http::withoutVerifying()
                ->withHeaders(['Authorization' => $this->token])
                ->withQueryParameters(['callback_id' => $callbackId])
                ->timeout(10)
                ->post(rtrim($this->apiUrl, '/').'/answers', $body);

            Log::info('[MAX] answerCallbackWithMessage', [
                'callback_id' => $callbackId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('[MAX] answerCallbackWithMessage exception', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Register bot commands via PATCH /me/commands.
     * Authorization via Authorization header (same as other endpoints).
     */
    public function setCommands(array $commands): bool
    {
        if (! $this->configured) {
            return false;
        }

        $body = ['commands' => $commands];

        try {
            $response = Http::withoutVerifying()
                ->withHeaders(['Authorization' => $this->token])
                ->timeout(10)
                ->patch(rtrim($this->apiUrl, '/').'/me/commands', $body);

            Log::info('[MAX] setCommands', [
                'count' => count($commands),
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('[MAX] setCommands exception', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
