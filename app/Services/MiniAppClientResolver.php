<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class MiniAppClientResolver
{
    /**
     * Все Client IDs, принадлежащие пользователю (MAX или VK).
     *
     * @return Collection<int, string>
     */
    public function resolveClientIds(Request $request): Collection
    {
        $maxInit = $request->attributes->get('max_init');

        if ($maxInit !== null) {
            return Client::byMaxId($maxInit->userId)->pluck('id');
        }

        $vkLaunch = $request->attributes->get('vk_launch');

        if ($vkLaunch !== null) {
            return Client::byVkId($vkLaunch->userId)->pluck('id');
        }

        return collect();
    }

    /**
     * Первый matching Client (для profile).
     */
    public function resolveFirstClient(Request $request): ?Client
    {
        $maxInit = $request->attributes->get('max_init');

        if ($maxInit !== null) {
            return Client::byMaxId($maxInit->userId)->first();
        }

        $vkLaunch = $request->attributes->get('vk_launch');

        if ($vkLaunch !== null) {
            return Client::byVkId($vkLaunch->userId)->first();
        }

        return null;
    }
}
