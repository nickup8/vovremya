<?php

namespace App\Services\Booking;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Carbon;

class BookingService
{
    public function __construct(
        private AvailabilityService $availabilityService,
    ) {}

    public function createAppointment(
        User $master,
        Service $service,
        string $date,
        string $time,
        string $provider,
    ): Appointment {
        $startDateTime = Carbon::parse($date.' '.$time);

        return Appointment::create([
            'master_id' => $master->id,
            'client_id' => null,
            'service_id' => $service->id,
            'start_time' => $startDateTime,
            'status' => AppointmentStatus::PendingClient,
            'provider' => $provider,
        ]);
    }

    public function createManualAppointment(
        User $master,
        Service $service,
        string $date,
        string $time,
        bool $ignoreWarnings = false,
    ): array {
        $startDateTime = Carbon::parse($date.' '.$time);

        $isAvailable = $this->availabilityService->isSlotAvailable(
            $master,
            $startDateTime,
            $service->duration_minutes,
        );

        if (! $isAvailable) {
            return [
                'success' => false,
                'error' => 'slot_unavailable',
                'message' => 'Этот слот уже занят или находится за пределами рабочего времени.',
            ];
        }

        $breakIntersection = $this->availabilityService->checkBreakIntersection(
            $master,
            $startDateTime,
            $service->duration_minutes,
        );

        if ($breakIntersection && ! $ignoreWarnings) {
            return [
                'success' => false,
                'error' => 'break_intersection',
                'message' => "Запись пересекается с обеденным перерывом ({$breakIntersection['break_start']}–{$breakIntersection['break_end']}).",
                'break_info' => $breakIntersection,
            ];
        }

        $appointment = $this->createAppointment(
            $master,
            $service,
            $date,
            $time,
            'crm',
        );

        return [
            'success' => true,
            'appointment' => $appointment,
        ];
    }

    public function updateStatus(Appointment $appointment, AppointmentStatus $status): Appointment
    {
        $appointment->update(['status' => $status]);

        return $appointment;
    }

    public function confirm(Appointment $appointment): Appointment
    {
        return $this->updateStatus($appointment, AppointmentStatus::Confirmed);
    }

    public function complete(Appointment $appointment): Appointment
    {
        return $this->updateStatus($appointment, AppointmentStatus::Completed);
    }

    public function cancel(Appointment $appointment): Appointment
    {
        return $this->updateStatus($appointment, AppointmentStatus::Cancelled);
    }

    public function markNoShow(Appointment $appointment): Appointment
    {
        return $this->updateStatus($appointment, AppointmentStatus::NoShow);
    }

    public function validateSlot(
        User $master,
        Service $service,
        string $date,
        string $time,
    ): bool {
        $startDateTime = Carbon::parse($date.' '.$time);

        return $this->availabilityService->isSlotAvailable(
            $master,
            $startDateTime,
            $service->duration_minutes,
        );
    }

    public function getAvailableSlots(
        User $master,
        ?Service $service,
        string $date,
    ): array {
        if (! $service) {
            return [];
        }

        $dateObj = Carbon::parse($date);

        return $this->availabilityService->getAvailableSlots(
            $master,
            $dateObj,
            $service->duration_minutes,
        );
    }

    public function rescheduleAppointment(
        Appointment $appointment,
        string $newDate,
        string $newTime,
        bool $ignoreWarnings = false,
    ): array {
        $master = $appointment->master;
        $service = $appointment->service;
        $startDateTime = Carbon::parse($newDate.' '.$newTime);

        $isAvailable = $this->availabilityService->isSlotAvailable(
            $master,
            $startDateTime,
            $service->duration_minutes,
        );

        if (! $isAvailable) {
            return [
                'success' => false,
                'error' => 'slot_unavailable',
                'message' => 'Этот слот уже занят или находится за пределами рабочего времени.',
            ];
        }

        $breakIntersection = $this->availabilityService->checkBreakIntersection(
            $master,
            $startDateTime,
            $service->duration_minutes,
        );

        if ($breakIntersection && ! $ignoreWarnings) {
            return [
                'success' => false,
                'error' => 'break_intersection',
                'message' => "Запись пересекается с обеденным перерывом ({$breakIntersection['break_start']}–{$breakIntersection['break_end']}).",
                'break_info' => $breakIntersection,
            ];
        }

        $appointment->update(['start_time' => $startDateTime]);

        return [
            'success' => true,
            'appointment' => $appointment,
        ];
    }
}
