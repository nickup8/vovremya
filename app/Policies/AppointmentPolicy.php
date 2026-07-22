<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;

class AppointmentPolicy
{
    public function before(User $user, string $ability, mixed $model = null): ?bool
    {
        if (in_array($user->role, ['owner', 'admin'])) {
            if ($model instanceof Appointment && $model->master_id) {
                $master = User::find($model->master_id);
                if ($master && $master->workspace_id !== $user->workspace_id) {
                    return false;
                }
            }

            return true;
        }

        return null;
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return $appointment->master_id === $user->id;
    }

    public function update(User $user, Appointment $appointment): bool
    {
        return $appointment->master_id === $user->id;
    }

    public function delete(User $user, Appointment $appointment): bool
    {
        return $appointment->master_id === $user->id;
    }
}
