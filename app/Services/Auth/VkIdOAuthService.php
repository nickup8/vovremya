<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;

class VkIdOAuthService
{
    private const AUTHORIZE_URL = 'https://id.vk.com/authorize';
    private const TOKEN_URL = 'https://id.vk.com/oauth2/auth';
    private const USER_INFO_URL = 'https://id.vk.com/oauth2/user_info';

    private string $appId;
    private string $redirectUri;

    public function __construct()
    {
        $this->appId = (string) config('services.vk_id.app_id');
        $this->redirectUri = (string) config('services.vk_id.redirect_uri');
    }

    public function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id' => $this->appId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'state' => $state,
            'scope' => 'phone',
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token?: string, id_token?: string}
     */
    public function exchangeCode(string $code, string $deviceId, string $state, string $codeVerifier): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'client_id' => $this->appId,
            'redirect_uri' => $this->redirectUri,
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'state' => $state,
            'device_id' => $deviceId,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('VK ID token exchange failed: HTTP ' . $response->status());
        }

        $data = $response->json();

        if (empty($data['access_token'])) {
            throw new \RuntimeException('VK ID token exchange failed: no access_token in response');
        }

        return $data;
    }

    /**
     * @return array{user_id: string, first_name?: string, last_name?: string, phone?: string, ...}
     */
    public function userInfo(string $accessToken): array
    {
        $response = Http::asForm()->post(self::USER_INFO_URL, [
            'client_id' => $this->appId,
            'access_token' => $accessToken,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('VK ID user info failed: HTTP ' . $response->status());
        }

        $data = $response->json();

        if (empty($data['user_id'])) {
            throw new \RuntimeException('VK ID user info failed: no user_id in response');
        }

        return $data;
    }
}
