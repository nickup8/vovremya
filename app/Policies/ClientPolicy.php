<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function before(User $user, string $ability, mixed $model = null): ?bool
    {
        if ($user->role->canManageTeam()) {
            if ($model instanceof Client) {
                if ($model->workspace_id !== null) {
                    return $model->workspace_id === $user->workspace_id;
                }

                return $model->user_id === $user->id;
            }

            return true;
        }

        return null;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Client $client): bool
    {
        if ($client->workspace_id !== null) {
            return $client->workspace_id === $user->workspace_id;
        }

        return $client->user_id === $user->id;
    }

    public function update(User $user, Client $client): bool
    {
        if ($client->workspace_id !== null) {
            return $client->workspace_id === $user->workspace_id;
        }

        return $client->user_id === $user->id;
    }

    public function delete(User $user, Client $client): bool
    {
        if ($client->workspace_id !== null) {
            return $client->workspace_id === $user->workspace_id;
        }

        return $client->user_id === $user->id;
    }
}
