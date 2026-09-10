<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class MiniAppClientResolver
{
    /**
     * Все Client IDs, принадлежащие MAX-пользователю.
     *
     * @return Collection<int, string>
     */
    public function resolveClientIds(Request $request): Collection
    {
        $maxInit = $request->attributes->get('max_init');

        if ($maxInit === null) {
            return collect();
        }

        return Client::byMaxId($maxInit->userId)->pluck('id');
    }

    /**
     * Первый matching Client (для profile).
     */
    public function resolveFirstClient(Request $request): ?Client
    {
        $maxInit = $request->attributes->get('max_init');

        if ($maxInit === null) {
            return null;
        }

        return Client::byMaxId($maxInit->userId)->first();
    }
}
