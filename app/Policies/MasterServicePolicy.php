<?php

namespace App\Policies;

use App\Models\MasterService;
use App\Models\User;

class MasterServicePolicy
{
    public function view(User $user, MasterService $ms): bool
    {
        return $ms->catalog->workspace_id === $user->workspace_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, MasterService $ms): bool
    {
        if ($user->role->canManageTeam()) {
            return $ms->catalog->workspace_id === $user->workspace_id;
        }

        return $ms->master_id === $user->id;
    }

    public function delete(User $user, MasterService $ms): bool
    {
        if ($user->role->canManageTeam()) {
            return $ms->catalog->workspace_id === $user->workspace_id;
        }

        return $ms->master_id === $user->id;
    }

    public function updatePrice(User $user, MasterService $ms): bool
    {
        if ($user->role->canManageTeam()) {
            return $ms->catalog->workspace_id === $user->workspace_id;
        }

        return $ms->master_id === $user->id
            && $user->workspace?->allow_masters_edit_prices === true;
    }
}
