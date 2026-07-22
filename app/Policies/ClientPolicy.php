<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if (in_array($user->role, ['owner', 'admin'])) {
            return true;
        }

        return null;
    }

    public function view(User $user, Client $client): bool
    {
        return $client->user_id === $user->id;
    }

    public function update(User $user, Client $client): bool
    {
        return $client->user_id === $user->id;
    }

    public function delete(User $user, Client $client): bool
    {
        return $client->user_id === $user->id;
    }
}
