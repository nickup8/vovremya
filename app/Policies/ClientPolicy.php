<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function before(User $user, string $ability, mixed $model = null): ?bool
    {
        if (in_array($user->role, ['owner', 'admin'])) {
            if ($model instanceof Client && $model->user_id) {
                $owner = User::find($model->user_id);
                if ($owner && $owner->workspace_id !== $user->workspace_id) {
                    return false;
                }
            }

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
