<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;

class AppointmentPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if (in_array($user->role, ['owner', 'admin'])) {
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
