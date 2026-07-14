<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Appointment;

class AppointmentStatusService
{
    public function transition(Appointment $appointment, AppointmentStatus $to): Appointment
    {
        $from = $appointment->status;

        if ($from === $to) {
            return $appointment;
        }

        if (! $from->canTransitionTo($to)) {
            throw new InvalidStatusTransitionException($from, $to);
        }

        $appointment->update(['status' => $to]);

        return $appointment;
    }
}
