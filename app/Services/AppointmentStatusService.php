<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Events\AppointmentStatusChanged;
use App\Exceptions\InvalidStatusTransitionException;
use App\Exceptions\PastAppointmentException;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;

class AppointmentStatusService
{
    public function transition(
        Appointment $appointment,
        AppointmentStatus $to,
        ?Authenticatable $actor = null,
    ): Appointment {
        $from = $appointment->status;

        if ($from === $to) {
            return $appointment;
        }

        // === ADR-9: временно́й гейт переходов ===
        $isGatedTransition =
            $to === AppointmentStatus::Cancelled                          // п.1: любой → cancelled
            || ($from === AppointmentStatus::Cancelled
                && $to === AppointmentStatus::Booked);                     // п.2: воскрешение

        if ($isGatedTransition && $appointment->start_time->isPast()) {
            $isSystem     = $actor === null;                              // крон/artisan
            $isSuperAdmin = $actor instanceof User
                            && $actor->isSuperAdmin();

            if (! $isSystem && ! $isSuperAdmin) {
                throw new PastAppointmentException();
            }
        }
        // === /ADR-9 ===

        if (! $from->canTransitionTo($to)) {
            throw new InvalidStatusTransitionException($from, $to);
        }

        $updateData = ['status' => $to];
        if ($to === AppointmentStatus::Cancelled) {
            $updateData['cancelled_at'] = now();
            $updateData['cancelled_by'] = $actor instanceof User
                ? $actor->id
                : null;
        }
        $appointment->update($updateData);

        broadcast(new AppointmentStatusChanged(
            $appointment->load(['client', 'service']),
            $from,
            $to,
        ));

        Log::info('Appointment status transitioned', [
            'appointment_id' => $appointment->id,
            'from' => $from->value,
            'to' => $to->value,
            'master_id' => $appointment->master_id,
        ]);

        return $appointment;
    }
}
