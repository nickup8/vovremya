<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VkApiClient
{
    private string $token;

    private string $apiVersion;

    private bool $configured;

    public function __construct()
    {
        $this->token = (string) config('services.vk.bot_token');
        $this->apiVersion = (string) config('services.vk.api_version', '5.199');
        $this->configured = $this->token !== '';

        if (! $this->configured) {
            Log::warning('[VK] API config missing: bot_token is empty');
        }
    }

    public function sendMessage(string $peerId, string $text): ?string
    {
        if (! $this->configured) {
            return null;
        }

        try {
            $response = Http::asForm()
                ->connectTimeout(3)
                ->timeout(10)
                ->post('https://api.vk.com/method/messages.send', [
                    'access_token' => $this->token,
                    'v' => $this->apiVersion,
                    'peer_id' => $peerId,
                    'message' => $text,
                    'random_id' => random_int(1, 2147483647),
                ]);

            if ($response->failed()) {
                Log::error('[VK] messages.send HTTP error', [
                    'status' => $response->status(),
                    'peer_id' => $peerId,
                ]);

                return null;
            }

            $body = $response->json();

            if (isset($body['error'])) {
                Log::error('[VK] messages.send failed', [
                    'error_code' => $body['error']['error_code'] ?? null,
                    'error_msg' => $body['error']['error_msg'] ?? null,
                    'peer_id' => $peerId,
                ]);

                return null;
            }

            return isset($body['response']) ? (string) $body['response'] : null;
        } catch (\Throwable $e) {
            Log::error('[VK] messages.send exception', [
                'peer_id' => $peerId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
