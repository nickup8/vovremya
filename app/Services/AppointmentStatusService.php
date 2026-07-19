<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Appointment;
use Illuminate\Support\Facades\Log;

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

        Log::info('Appointment status transitioned', [
            'appointment_id' => $appointment->id,
            'from' => $from->value,
            'to' => $to->value,
            'master_id' => $appointment->master_id,
        ]);

        return $appointment;
    }
}
