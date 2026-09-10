<?php

namespace App\Services;

use App\Constants\CacheKeys;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class VkLinkTokenService
{
    /**
     * Создаёт opaque one-time token, связанный с appointment.
     */
    public function create(string $appointmentId): string
    {
        $token = 'link_vk_' . Str::random(32);
        $ttl = (int) config('booking.draft_ttl', 900);

        Cache::put(CacheKeys::VK_LINK_TOKEN . $token, $appointmentId, $ttl);

        return $token;
    }

    /**
     * Извлекает appointment ID из token (one-time consume).
     * Повторный вызов или expired/unknown → null.
     */
    public function consume(string $token): ?string
    {
        if ($token === '' || ! str_starts_with($token, 'link_vk_')) {
            return null;
        }

        return Cache::pull(CacheKeys::VK_LINK_TOKEN . $token) ?: null;
    }
}
