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

    public function checkSlot(
        User $master,
        Carbon $startDateTime,
        int $durationMinutes,
        string $role = 'client',
        bool $confirmOutsideHours = false,
        ?int $excludeAppointmentId = null,
    ): array {
        $tz = $master->getTimezone();
        $localSlot = $startDateTime->copy()->timezone($tz);

        if ($localSlot->lt(Carbon::now($tz))) {
            return [
                'status' => 'error',
                'error' => 'past_time',
                'message' => 'Нельзя создать запись на прошедшее время.',
            ];
        }

        if (! $this->availabilityService->isWithinWorkingHours($master, $startDateTime, $durationMinutes)) {
            if ($role === 'client') {
                return [
                    'status' => 'error',
                    'error' => 'outside_working_hours',
                    'message' => 'Выбранное время не попадает в рабочий график мастера.',
                ];
            }

            if (! $confirmOutsideHours) {
                return [
                    'status' => 'warning',
                    'error' => 'outside_working_hours',
                    'message' => 'Выбранное время не попадает в рабочий график. Всё равно создать?',
                ];
            }
        }

        if ($this->availabilityService->isSlotBookedOrBlocked($master, $startDateTime, $durationMinutes, $excludeAppointmentId)) {
            return [
                'status' => 'error',
                'error' => 'slot_taken',
                'message' => 'Этот слот уже занят.',
            ];
        }

        $breakIntersection = $this->availabilityService->checkBreakIntersection(
            $master,
            $startDateTime,
            $durationMinutes,
        );

        if ($breakIntersection) {
            return [
                'status' => 'error',
                'error' => 'break_intersection',
                'message' => "Запись пересекается с обеденным перерывом ({$breakIntersection['break_start']}–{$breakIntersection['break_end']}).",
                'break_info' => $breakIntersection,
            ];
        }

        return ['status' => 'ok'];
    }

    public function createAppointment(
        User $master,
        Service $service,
        string $date,
        string $time,
        string $provider,
        ?int $clientId = null,
        ?AppointmentStatus $status = null,
    ): Appointment {
        $startDateTime = Carbon::parse($date.' '.$time, $master->getTimezone())->utc();
        $endDateTime = $startDateTime->copy()->addMinutes($service->duration_minutes);

        return DB::transaction(function () use ($master, $service, $startDateTime, $endDateTime, $provider, $clientId, $status) {
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

            $appointmentStatus = $status ?? (
                $master->getBookingFlowType() === 'prepayment_custom'
                    ? AppointmentStatus::PendingPayment
                    : AppointmentStatus::Booked
            );

            $allowedInitialStatuses = [AppointmentStatus::Booked, AppointmentStatus::PendingPayment];

            if (! in_array($appointmentStatus, $allowedInitialStatuses, true)) {
                throw new \InvalidArgumentException(
                    "Cannot create appointment with status [{$appointmentStatus->value}]. "
                    ."Allowed initial statuses: booked, pending_payment."
                );
            }

            return Appointment::create([
                'master_id' => $master->id,
                'client_id' => $clientId,
                'service_id' => $service->id,
                'start_time' => $startDateTime,
                'status' => $appointmentStatus,
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
        bool $confirmOutsideHours = false,
        ?int $clientId = null,
    ): array {
        $startDateTime = Carbon::parse($date.' '.$time, $master->getTimezone());

        $check = $this->checkSlot(
            $master,
            $startDateTime,
            $service->duration_minutes,
            'master',
            $confirmOutsideHours,
        );

        if ($check['status'] === 'warning') {
            return [
                'success' => false,
                'error' => $check['error'],
                'message' => $check['message'],
            ];
        }

        if ($check['status'] === 'error') {
            return [
                'success' => false,
                'error' => $check['error'],
                'message' => $check['message'],
                'break_info' => $check['break_info'] ?? null,
            ];
        }

        $appointment = $this->createAppointment(
            $master,
            $service,
            $date,
            $time,
            'crm',
            $clientId,
            AppointmentStatus::Booked,
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

        $check = $this->checkSlot(
            $master,
            $startDateTime,
            $service->duration_minutes,
            'client',
        );

        return $check['status'] === 'ok';
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
        bool $confirmOutsideHours = false,
    ): array {
        $master = $appointment->master;
        $service = $appointment->service;
        $startDateTime = Carbon::parse($newDate.' '.$newTime, $master->getTimezone())->utc();

        $check = $this->checkSlot(
            $master,
            $startDateTime,
            $service->duration_minutes,
            'master',
            $confirmOutsideHours,
            $appointment->id,
        );

        if ($check['status'] === 'warning') {
            return [
                'success' => false,
                'error' => $check['error'],
                'message' => $check['message'],
            ];
        }

        if ($check['status'] === 'error') {
            return [
                'success' => false,
                'error' => $check['error'],
                'message' => $check['message'],
                'break_info' => $check['break_info'] ?? null,
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
