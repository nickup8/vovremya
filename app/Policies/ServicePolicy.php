<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\User;

class ServicePolicy
{
    public function before(User $user, string $ability, mixed $model = null): ?bool
    {
        if (in_array($user->role, ['owner', 'admin'])) {
            // Для update/delete — проверяем workspace через владельца услуги
            if ($model instanceof Service && $model->user_id) {
                $owner = User::find($model->user_id);
                if ($owner && $owner->workspace_id !== $user->workspace_id) {
                    return false;
                }
            }

            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Service $service): bool
    {
        return $service->user_id === $user->id;
    }

    public function delete(User $user, Service $service): bool
    {
        return $service->user_id === $user->id;
    }
}
