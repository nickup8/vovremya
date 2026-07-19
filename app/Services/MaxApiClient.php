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

    public function sendMessage(string $chatId, string $text, array $extra = []): bool
    {
        if (! $this->configured) {
            return false;
        }

        $payload = array_merge(['text' => $text], $extra);

        try {
            $response = Http::withoutVerifying()
                ->withHeaders([
                    'Authorization' => $this->token,
                ])
                ->withQueryParameters(['chat_id' => $chatId])
                ->timeout(10)
                ->post(rtrim($this->apiUrl, '/').'/messages', $payload);

            Log::info('[MAX] Raw Response', ['chat_id' => $chatId, 'status' => $response->status(), 'body' => $response->body()]);

            if ($response->failed()) {
                Log::error('[MAX] sendMessage failed', [
                    'chat_id' => $chatId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('[MAX] sendMessage exception', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
