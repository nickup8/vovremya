<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;

class AppointmentStatusService
{
    public function transition(Appointment $appointment, AppointmentStatus $to): Appointment
    {
        $from = $appointment->status;

        if (! $from->canTransitionTo($to)) {
            throw new \InvalidArgumentException(
                "Недопустимый переход статуса: {$from->value} -> {$to->value}"
            );
        }

        $appointment->update(['status' => $to]);

        return $appointment;
    }
}
