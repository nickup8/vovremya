<?php

namespace App\Policies;

use App\Models\ServiceCatalog;
use App\Models\User;

class ServiceCatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ServiceCatalog $catalog): bool
    {
        return $catalog->workspace_id === $user->workspace_id;
    }

    public function create(User $user): bool
    {
        return $user->role->canManageTeam();
    }

    public function update(User $user, ServiceCatalog $catalog): bool
    {
        return $user->role->canManageTeam()
            && $catalog->workspace_id === $user->workspace_id;
    }

    public function delete(User $user, ServiceCatalog $catalog): bool
    {
        return $user->role->canManageTeam()
            && $catalog->workspace_id === $user->workspace_id;
    }
}
