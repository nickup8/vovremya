<?php

namespace App\Observers;

use App\Models\User;

class UserObserver
{
    public function created(User $user): void
    {
        if ($user->is_master) {
            $user->createDefaultWorkingHours();
        }
    }

    public function updated(User $user): void
    {
        if ($user->wasChanged('is_master') && $user->is_master) {
            $user->createDefaultWorkingHours();
        }
    }
}
