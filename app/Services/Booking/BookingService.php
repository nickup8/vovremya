<?php

namespace App\Services\Booking;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Events\AppointmentCreated;
use App\Events\AppointmentRescheduled;
use App\Models\Appointment;
use App\Models\MasterService;
use App\Models\User;
use App\Services\AppointmentStatusService;
use App\Services\Billing\TariffLimitService;
use App\Services\Notification\ClientNotificationService;
use App\Services\Notification\MasterNotificationService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(
        private AvailabilityService $availabilityService,
        private AppointmentStatusService $statusService,
        private TariffLimitService $tariffLimitService,
    ) {}

    public function checkSlot(
        User $master,
        Carbon $startDateTime,
        int $durationMinutes,
        string $role = 'client',
        bool $confirmOutsideHours = false,
        ?string $excludeAppointmentId = null,
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
        MasterService $service,
        string $date,
        string $time,
        string $provider,
        ?string $clientId = null,
        ?AppointmentStatus $status = null,
        ?AppointmentSource $source = null,
    ): Appointment {
        $workspace = $master->workspace;

        $startDateTime = Carbon::parse($date.' '.$time, $master->getTimezone())->utc();
        $endDateTime = $startDateTime->copy()->addMinutes($service->effective_duration);

        if ($workspace && ! $this->tariffLimitService->canCreateAppointment($workspace, null, $startDateTime)) {
            throw ValidationException::withMessages([
                'limit' => __('booking.limit_reached'),
            ]);
        }

        return DB::transaction(function () use ($master, $service, $startDateTime, $endDateTime, $provider, $clientId, $status, $source) {
            $conflict = Appointment::where('master_id', $master->id)
                ->whereIn('status', [
                    AppointmentStatus::Booked,
                    AppointmentStatus::PendingPayment,
                    AppointmentStatus::Prepaid,
                    AppointmentStatus::Paid,
                ])
                ->whereDate('start_time', $startDateTime->toDateString())
                ->lockForUpdate()
                ->get()
                ->contains(function (Appointment $existing) use ($startDateTime, $endDateTime) {
                    $existingEnd = $existing->start_time->copy()->addMinutes(
                        $existing->display_duration ?: 60
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
                    .'Allowed initial statuses: booked, pending_payment.'
                );
            }

            $appointment = Appointment::create([
                'master_id' => $master->id,
                'client_id' => $clientId,
                'master_service_id' => $service->id,
                'price' => $service->effective_price,
                'duration' => $service->effective_duration,
                'service_name' => $service->catalog?->title ?? '',
                'start_time' => $startDateTime,
                'status' => $appointmentStatus,
                'provider' => $provider,
                'source' => $source,
            ]);

            if ($clientId !== null) {
                broadcast(new AppointmentCreated(
                    $appointment->load(['client'])
                ));
            }

            return $appointment;
        });
    }

    public function createManualAppointment(
        User $master,
        MasterService $service,
        string $date,
        string $time,
        bool $ignoreWarnings = false,
        bool $confirmOutsideHours = false,
        ?string $clientId = null,
    ): array {
        $startDateTime = Carbon::parse($date.' '.$time, $master->getTimezone());

        $check = $this->checkSlot(
            $master,
            $startDateTime,
            $service->effective_duration,
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
            'admin',
            $clientId,
            AppointmentStatus::Booked,
            AppointmentSource::Admin,
        );

        return [
            'success' => true,
            'appointment' => $appointment,
        ];
    }

    public function updateStatus(Appointment $appointment, AppointmentStatus $status, ?Authenticatable $actor = null): Appointment
    {
        return $this->statusService->transition($appointment, $status, $actor);
    }

    public function confirm(Appointment $appointment): Appointment
    {
        $master = $appointment->master;
        $startDateTime = Carbon::parse($appointment->start_time);
        $durationMinutes = $appointment->display_duration ?: 60;

        $breakIntersection = $this->availabilityService->checkBreakIntersection(
            $master,
            $startDateTime,
            $durationMinutes,
        );

        if ($breakIntersection) {
            throw new ValidationException(
                Validator::make([], []),
                'Невозможно подтвердить запись: пересечение с обеденным временем.',
            );
        }

        return $this->statusService->transition($appointment, AppointmentStatus::Booked);
    }

    public function complete(Appointment $appointment): Appointment
    {
        return $this->statusService->transition($appointment, AppointmentStatus::Paid);
    }

    public function cancel(Appointment $appointment, ?Authenticatable $actor = null): Appointment
    {
        return $this->statusService->transition($appointment, AppointmentStatus::Cancelled, $actor);
    }

    public function markNoShow(Appointment $appointment): Appointment
    {
        return $this->statusService->transition($appointment, AppointmentStatus::NoShow);
    }

    public function validateSlot(
        User $master,
        MasterService $service,
        string $date,
        string $time,
    ): bool {
        $startDateTime = Carbon::parse($date.' '.$time, $master->getTimezone());

        $check = $this->checkSlot(
            $master,
            $startDateTime,
            $service->effective_duration,
            'client',
        );

        return $check['status'] === 'ok';
    }

    public function getAvailableSlots(
        User $master,
        ?MasterService $service,
        string $date,
    ): array {
        if (! $service) {
            return [];
        }

        $dateObj = Carbon::parse($date, $master->getTimezone());

        return $this->availabilityService->getAvailableSlots(
            $master,
            $dateObj,
            $service->effective_duration,
        );
    }

    public function rescheduleAppointment(
        Appointment $appointment,
        string $newDate,
        string $newTime,
        bool $ignoreWarnings = false,
        bool $confirmOutsideHours = false,
        ?string $newMasterId = null,
    ): array {
        $result = DB::transaction(function () use ($appointment, $newDate, $newTime, $ignoreWarnings, $confirmOutsideHours, $newMasterId) {
            $locked = Appointment::where('id', $appointment->id)->lockForUpdate()->first();

            $originalMaster = $locked->master;

            if ($newMasterId) {
                $newMaster = User::findOrFail($newMasterId);

                if ($newMaster->workspace_id !== $originalMaster->workspace_id) {
                    abort(403, 'Мастер из другого воркспейса');
                }

                $master = $newMaster;
            } else {
                $master = $originalMaster;
            }

            $durationMinutes = $locked->display_duration ?: 60;
            $startDateTime = Carbon::parse($newDate.' '.$newTime, $master->getTimezone())->utc();

            $check = $this->checkSlot(
                $master,
                $startDateTime,
                $durationMinutes,
                'master',
                $confirmOutsideHours,
                $locked->id,
            );

            if ($check['status'] === 'warning') {
                return [
                    'success' => false,
                    'error' => $check['error'],
                    'message' => $check['message'],
                ];
            }

            if ($check['status'] === 'error') {
                if ($ignoreWarnings && $check['error'] === 'break_intersection') {
                    // пользователь подтвердил обед — пропускаем, сохраняем с новым временем
                } else {
                    return [
                        'success' => false,
                        'error' => $check['error'],
                        'message' => $check['message'],
                        'break_info' => $check['break_info'] ?? null,
                    ];
                }
            }

            $oldStartTime = $locked->start_time->toIso8601String();

            $updateData = [
                'start_time' => $startDateTime,
                'client_confirmed_at' => null,
                'reminder_24h_sent_at' => null,
                'reminder_final_sent_at' => null,
                'reminder_24h_sent' => false,
                'reminder_final_sent' => false,
            ];
            if ($newMasterId) {
                $updateData['master_id'] = $newMasterId;
            }
            $locked->update($updateData);

            if ($locked->status === AppointmentStatus::NoShow) {
                $this->statusService->transition($locked, AppointmentStatus::Booked);
            }

            $locked->load(['client', 'master']);
            broadcast(new AppointmentRescheduled($locked, $oldStartTime));

            return [
                'success' => true,
                'appointment' => $locked,
            ];
        });

        // Уведомления о переносе — ПОСЛЕ коммита транзакции.
        // Падение мессенджера не должно откатывать сам перенос.
        if (($result['success'] ?? false) === true) {
            $this->sendRescheduleNotifications($result['appointment']);
        }

        return $result;
    }

    /**
     * Уведомить клиента и мастера о переносе записи (Telegram/MAX).
     * Ошибки отправки логируются, но не пробрасываются.
     */
    private function sendRescheduleNotifications(Appointment $appointment): void
    {
        $master = $appointment->master;
        $tz = $master->getTimezone();
        $date = $appointment->start_time->timezone($tz)->format('d.m.Y');
        $time = $appointment->start_time->timezone($tz)->format('H:i');

        // Клиенту
        $client = $appointment->client;
        if ($client && ! empty($client->telegram_id)) {
            try {
                app(ClientNotificationService::class)->sendToClient(
                    $client,
                    __('bot.client.rescheduled', [
                        'service' => $appointment->display_name,
                        'date' => $date,
                        'time' => $time,
                    ]),
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('[reschedule] client notify failed', [
                    'appointment_id' => $appointment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Мастеру
        try {
            app(MasterNotificationService::class)->sendToMaster(
                $master,
                __('bot.master.rescheduled', [
                    'client' => $client?->name ?? __('bot.fallback.client_name'),
                    'date' => $date,
                    'time' => $time,
                ]),
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[reschedule] master notify failed', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
