<?php

namespace App\Services\Auth;

class VkPhoneNumberVerifier
{
    public function verify(string $userId, string $phone, string $sign): ?VkPhoneNumberResult
    {
        $appId = (string) config('services.vk.app_id');
        $appSecret = (string) config('services.vk.app_secret');

        if ($appId === '' || $appSecret === '' || $userId === '' || $phone === '' || $sign === '') {
            return null;
        }

        $expected = rtrim(strtr(base64_encode(hash('sha256', $appId . $appSecret . $userId . 'phone_number' . $phone, true)), '+/', '-_'), '=');

        if (! hash_equals($expected, $sign)) {
            return null;
        }

        return new VkPhoneNumberResult($userId, $phone);
    }
}
