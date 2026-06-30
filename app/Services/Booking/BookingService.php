<?php

namespace App\Services\Booking;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use App\Services\AppointmentStatusService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BookingService
{
    public function __construct(
        private AvailabilityService $availabilityService,
        private AppointmentStatusService $statusService,
    ) {}

    public function createAppointment(
        User $master,
        Service $service,
        string $date,
        string $time,
        string $provider,
        ?int $clientId = null,
    ): Appointment {
        $startDateTime = Carbon::parse($date.' '.$time, $master->getTimezone())->utc();
        $endDateTime = $startDateTime->copy()->addMinutes($service->duration_minutes);

        return DB::transaction(function () use ($master, $service, $startDateTime, $endDateTime, $provider, $clientId) {
            $conflict = Appointment::with('service')
                ->where('master_id', $master->id)
                ->whereIn('status', [
                    AppointmentStatus::Booked,
                ])
                ->whereDate('start_time', $startDateTime->toDateString())
                ->lockForUpdate()
                ->get()
                ->contains(function (Appointment $existing) use ($startDateTime, $endDateTime) {
                    $existingEnd = $existing->start_time->copy()->addMinutes(
                        $existing->service?->duration_minutes ?? 60
                    );

                    return $startDateTime->lt($existingEnd) && $existing->start_time->lt($endDateTime);
                });

            if ($conflict) {
                abort(422, 'Это время уже занято, выберите другой слот.');
            }

            return Appointment::create([
                'master_id' => $master->id,
                'client_id' => $clientId,
                'service_id' => $service->id,
                'start_time' => $startDateTime,
                'status' => AppointmentStatus::Booked,
                'provider' => $provider,
            ]);
        });
    }

    public function createManualAppointment(
        User $master,
        Service $service,
        string $date,
        string $time,
        bool $ignoreWarnings = false,
        ?int $clientId = null,
    ): array {
        $startDateTime = Carbon::parse($date.' '.$time, $master->getTimezone());

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
            $clientId,
        );

        return [
            'success' => true,
            'appointment' => $appointment,
        ];
    }

    public function updateStatus(Appointment $appointment, AppointmentStatus $status): Appointment
    {
        return $this->statusService->transition($appointment, $status);
    }

    public function confirm(Appointment $appointment): Appointment
    {
        return $this->statusService->transition($appointment, AppointmentStatus::Booked);
    }

    public function complete(Appointment $appointment): Appointment
    {
        return $this->statusService->transition($appointment, AppointmentStatus::Paid);
    }

    public function cancel(Appointment $appointment): Appointment
    {
        return $this->statusService->transition($appointment, AppointmentStatus::Cancelled);
    }

    public function markNoShow(Appointment $appointment): Appointment
    {
        return $this->statusService->transition($appointment, AppointmentStatus::NoShow);
    }

    public function validateSlot(
        User $master,
        Service $service,
        string $date,
        string $time,
    ): bool {
        $startDateTime = Carbon::parse($date.' '.$time, $master->getTimezone());

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

        $dateObj = Carbon::parse($date, $master->getTimezone());

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
        $startDateTime = Carbon::parse($newDate.' '.$newTime, $master->getTimezone())->utc();

        $isAvailable = $this->availabilityService->isSlotAvailable(
            $master,
            $startDateTime,
            $service->duration_minutes,
            $appointment->id,
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

        if ($appointment->status === AppointmentStatus::NoShow) {
            $this->statusService->transition($appointment, AppointmentStatus::Booked);
        }

        return [
            'success' => true,
            'appointment' => $appointment,
        ];
    }
}
