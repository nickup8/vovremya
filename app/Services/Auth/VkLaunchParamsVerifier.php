<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Log;

class VkLaunchParamsVerifier
{
    /**
     * Верифицирует VK Mini App launch parameters.
     * Возвращает DTO при валидной подписи, иначе null.
     */
    public function verify(string $queryString): ?VkLaunchParamsResult
    {
        $queryString = ltrim(trim($queryString), '?');

        if ($queryString === '') {
            return null;
        }

        $appSecret = trim((string) config('services.vk.app_secret'));
        $configuredAppId = trim((string) config('services.vk.app_id'));

        if ($appSecret === '') {
            Log::warning('[VK] launch params verify: app_secret не настроен');

            return null;
        }

        // 1. Parse query parameters
        parse_str($queryString, $allParams);

        if (empty($allParams)) {
            return null;
        }

        // 2. Extract sign
        if (! isset($allParams['sign']) || $allParams['sign'] === '') {
            Log::warning('[VK] launch params verify: отсутствует sign');

            return null;
        }

        $sign = $allParams['sign'];

        // 3. Collect vk_* parameters for signing (exclude sign itself)
        $vkParams = [];

        foreach ($allParams as $key => $value) {
            if (str_starts_with($key, 'vk_')) {
                $vkParams[$key] = $value;
            }
        }

        if (empty($vkParams)) {
            Log::warning('[VK] launch params verify: нет vk_* параметров');

            return null;
        }

        // 4. Validate required fields
        if (! isset($vkParams['vk_user_id']) || $vkParams['vk_user_id'] === '') {
            Log::warning('[VK] launch params verify: отсутствует vk_user_id');

            return null;
        }

        if (! isset($vkParams['vk_app_id']) || $vkParams['vk_app_id'] === '') {
            Log::warning('[VK] launch params verify: отсутствует vk_app_id');

            return null;
        }

        // 5. Verify vk_app_id matches configured app ID
        if ($configuredAppId !== '' && $vkParams['vk_app_id'] !== $configuredAppId) {
            Log::warning('[VK] launch params verify: vk_app_id не совпадает', [
                'expected' => $configuredAppId,
                'got' => $vkParams['vk_app_id'],
            ]);

            return null;
        }

        // 6. Sort vk_* params by key ascending
        ksort($vkParams);

        // 7. Build canonical query string
        $canonicalParts = [];

        foreach ($vkParams as $key => $value) {
            $canonicalParts[] = $key . '=' . $value;
        }

        $canonicalQuery = implode('&', $canonicalParts);

        // 8. HMAC-SHA256 with raw binary output
        $hmac = hash_hmac('sha256', $canonicalQuery, $appSecret, true);

        // 9. Base64URL encode
        $expectedSign = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($hmac));

        // 10. Compare
        if (! hash_equals($expectedSign, $sign)) {
            Log::warning('[VK] launch params verify: подпись не совпадает');

            return null;
        }

        // 11. Extract optional timestamp
        $timestamp = null;

        if (isset($vkParams['vk_ts']) && $vkParams['vk_ts'] !== '') {
            $ts = (int) $vkParams['vk_ts'];

            if ($ts > 0) {
                $timestamp = $ts;
            }
        }

        return new VkLaunchParamsResult(
            userId: (string) $vkParams['vk_user_id'],
            appId: (string) $vkParams['vk_app_id'],
            timestamp: $timestamp,
            raw: $vkParams,
        );
    }
}
