<?php

namespace App\Services\Booking;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Enums\RecurrenceType;
use App\Enums\RecurringSeriesStatus;
use App\Models\Appointment;
use App\Models\MasterService;
use App\Models\RecurringAppointmentSeries;
use App\Models\User;
use App\Services\Recurrence\RecurrenceRule;
use App\Services\Recurrence\RecurrenceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RecurringAppointmentService
{
    public function __construct(
        private RecurrenceService $recurrenceService = new RecurrenceService(),
        private AvailabilityService $availabilityService = new AvailabilityService(),
    ) {}

    /**
     * Preview: generate dates, check availability for each, return conflicts.
     *
     * When $excludeAppointmentId is set (from-existing flow), the current appointment's
     * date is excluded from conflict checking and returned as 'current_date'.
     *
     * @return array{total: int, available: int, conflicts: array, dates: array, current_date: string|null}
     */
    public function preview(
        User $master,
        Carbon $startDate,
        string $startTime,
        int $durationMinutes,
        RecurrenceType $recurrenceType,
        int $interval,
        ?array $weekdays,
        ?Carbon $endsAt,
        ?int $occurrencesCount,
        ?string $excludeAppointmentId = null,
        ?array $excludeAppointmentIds = null,
    ): array {
        $tz = $master->getTimezone();
        $isFromExisting = $excludeAppointmentId !== null;
        $currentDateStr = $startDate->format('Y-m-d');

        $rangeEnd = $endsAt ?? ($occurrencesCount
            ? $this->estimateEndDate($startDate, $recurrenceType, $interval, $occurrencesCount)
            : $startDate->copy()->addWeeks(12));

        $rule = new RecurrenceRule(
            recurrenceType: $recurrenceType,
            interval: $interval,
            weekdays: $weekdays,
            startDate: $startDate,
            endsAt: $endsAt,
            timezone: $tz,
        );

        $dates = $this->recurrenceService->generateOccurrences($rule, $startDate, $rangeEnd);

        // For from-existing: current appointment is first occurrence, don't count it as future
        $currentDate = null;
        if ($isFromExisting) {
            foreach ($dates as $i => $date) {
                if ($date->format('Y-m-d') === $currentDateStr) {
                    $currentDate = $date;
                    unset($dates[$i]);
                    $dates = array_values($dates);
                    break;
                }
            }
        }

        // If count is set and this is from-existing, we need count-1 future dates
        $futureCount = $occurrencesCount ? ($isFromExisting ? $occurrencesCount - 1 : $occurrencesCount) : null;
        if ($futureCount !== null && count($dates) > $futureCount) {
            $dates = array_slice($dates, 0, $futureCount);
        }

        $conflicts = [];
        $available = [];

        foreach ($dates as $date) {
            $startDateTime = \Illuminate\Support\Carbon::parse(
                $date->format('Y-m-d').' '.$startTime,
                $tz,
            );

            if ($startDateTime->lt(Carbon::now($tz))) {
                $conflicts[] = [
                    'date' => $date->format('Y-m-d'),
                    'reason' => 'past',
                ];
                continue;
            }

            $reason = $this->availabilityService->getSlotConflictReason(
                $master,
                $startDateTime,
                $durationMinutes,
                $excludeAppointmentId,
                $excludeAppointmentIds,
            );

            if ($reason === null) {
                $available[] = $date->format('Y-m-d');
            } else {
                $conflicts[] = [
                    'date' => $date->format('Y-m-d'),
                    'reason' => $reason,
                ];
            }
        }

        return [
            'total' => count($dates) + ($currentDate ? 1 : 0),
            'available' => count($available),
            'conflicts' => $conflicts,
            'dates' => $available,
            'current_date' => $currentDate?->format('Y-m-d'),
        ];
    }

    /**
     * Create series + materialize appointments in a transaction.
     *
     * @return RecurringAppointmentSeries
     */
    public function createSeries(
        User $master,
        string $clientId,
        string $masterServiceId,
        string $startDate,
        string $startTime,
        RecurrenceType $recurrenceType,
        int $interval,
        ?array $weekdays,
        ?string $endsAt,
        ?int $occurrencesCount,
        array $allowedDates,
        ?Appointment $existingAppointment = null,
    ): RecurringAppointmentSeries {
        $tz = $master->getTimezone();
        $workspace = $master->workspace;

        return DB::transaction(function () use (
            $master, $workspace, $clientId, $masterServiceId,
            $startDate, $startTime, $recurrenceType, $interval,
            $weekdays, $endsAt, $occurrencesCount, $allowedDates,
            $tz, $existingAppointment,
        ) {
            $series = RecurringAppointmentSeries::create([
                'workspace_id' => $workspace->id,
                'master_id' => $master->id,
                'client_id' => $clientId,
                'master_service_id' => $masterServiceId,
                'start_date' => $startDate,
                'start_time' => $startTime,
                'recurrence_type' => $recurrenceType,
                'interval' => $interval,
                'weekdays' => $weekdays,
                'ends_at' => $endsAt,
                'occurrences_count' => $occurrencesCount,
                'timezone' => $tz,
                'status' => RecurringSeriesStatus::Active,
            ]);

            $service = MasterService::findOrFail($masterServiceId);

            // Always link existing appointment as first occurrence of the series
            if ($existingAppointment) {
                $existingAppointment->update([
                    'recurring_series_id' => $series->id,
                    'recurring_occurrence_date' => $startDate,
                ]);
                // Remove from allowedDates if present to avoid duplicate
                $allowedDates = array_filter($allowedDates, fn ($d) => $d !== $startDate);
            }

            foreach ($allowedDates as $date) {
                $startDateTime = Carbon::parse($date.' '.$startTime, $tz)->utc();

                // Double-check no conflict at materialization time (race condition guard)
                $conflict = Appointment::where('master_id', $master->id)
                    ->whereIn('status', [
                        AppointmentStatus::Booked,
                        AppointmentStatus::PendingPayment,
                        AppointmentStatus::Prepaid,
                        AppointmentStatus::Paid,
                    ])
                    ->where('start_time', '<', $startDateTime->copy()->addMinutes($service->effective_duration))
                    ->whereRaw(
                        "start_time + (COALESCE(duration, 60) * INTERVAL '1 minute') > ?",
                        [$startDateTime],
                    )
                    ->lockForUpdate()
                    ->exists();

                if ($conflict) {
                    Log::warning('[recurring] skipping conflict at materialization', [
                        'date' => $date,
                        'master_id' => $master->id,
                    ]);
                    continue;
                }

                Appointment::create([
                    'master_id' => $master->id,
                    'client_id' => $clientId,
                    'master_service_id' => $masterServiceId,
                    'price' => $service->effective_price,
                    'duration' => $service->effective_duration,
                    'service_name' => $service->catalog?->title ?? '',
                    'start_time' => $startDateTime,
                    'status' => AppointmentStatus::Booked,
                    'source' => AppointmentSource::Admin,
                    'recurring_series_id' => $series->id,
                    'recurring_occurrence_date' => $date,
                ]);
            }

            return $series;
        });
    }

    /**
     * Edit only this occurrence: reschedule, preserve series fields.
     */
    public function editOnlyThis(
        Appointment $appointment,
        string $newDate,
        string $newTime,
    ): array {
        return app(BookingService::class)->rescheduleAppointment(
            appointment: $appointment,
            newDate: $newDate,
            newTime: $newTime,
            ignoreWarnings: true,
            confirmOutsideHours: true,
        );
    }

    /**
     * Cancel only this occurrence.
     */
    public function cancelOnlyThis(Appointment $appointment): Appointment
    {
        return app(BookingService::class)->cancel($appointment);
    }

    /**
     * Split series at a point, creating a new series from split_point onwards.
     * Old series ends at the previous occurrence.
     * Old future appointments (>= split_point) are cancelled.
     * New appointments are materialized from the new series.
     *
     * @param array{allowed_dates: string[]} $previewResult
     */
    public function splitSeries(
        RecurringAppointmentSeries $series,
        Appointment $splitAppointment,
        array $newParams,
        array $previewResult,
    ): RecurringAppointmentSeries {
        $tz = $series->timezone;
        $splitDate = $splitAppointment->recurring_occurrence_date;

        return DB::transaction(function () use ($series, $splitAppointment, $splitDate, $newParams, $previewResult, $tz) {
            // Lock series
            $lockedSeries = RecurringAppointmentSeries::where('id', $series->id)->lockForUpdate()->first();

            // Find previous occurrence
            $prevAppointment = Appointment::where('recurring_series_id', $series->id)
                ->where('recurring_occurrence_date', '<', $splitDate)
                ->orderBy('recurring_occurrence_date', 'desc')
                ->first();

            if ($prevAppointment) {
                $lockedSeries->update([
                    'ends_at' => $prevAppointment->recurring_occurrence_date,
                ]);
            } else {
                // split_point is the first occurrence — end old series entirely
                $lockedSeries->update([
                    'ends_at' => $splitDate,
                    'status' => RecurringSeriesStatus::Cancelled,
                ]);
            }

            // Get all future appointments to cancel
            $futureAppointments = Appointment::where('recurring_series_id', $series->id)
                ->where('recurring_occurrence_date', '>=', $splitDate)
                ->whereIn('status', [
                    AppointmentStatus::Booked,
                    AppointmentStatus::PendingPayment,
                    AppointmentStatus::Prepaid,
                ])
                ->get();

            foreach ($futureAppointments as $appt) {
                app(BookingService::class)->cancel($appt);
            }

            // Create new series
            $master = $series->master;
            $newSeries = $this->createSeries(
                master: $master,
                clientId: $series->client_id,
                masterServiceId: $newParams['master_service_id'] ?? $series->master_service_id,
                startDate: $splitDate,
                startTime: $newParams['start_time'] ?? $series->start_time,
                recurrenceType: RecurrenceType::from($newParams['recurrence_type']),
                interval: $newParams['interval'],
                weekdays: $newParams['weekdays'] ?? null,
                endsAt: $newParams['ends_at'] ?? null,
                occurrencesCount: $newParams['occurrences_count'] ?? null,
                allowedDates: $previewResult['dates'] ?? [],
            );

            return $newSeries;
        });
    }

    /**
     * Cancel this and all future occurrences.
     */
    public function cancelThisAndFuture(
        RecurringAppointmentSeries $series,
        Appointment $splitAppointment,
    ): int {
        $splitDate = $splitAppointment->recurring_occurrence_date;

        return DB::transaction(function () use ($series, $splitDate) {
            // Lock series
            $lockedSeries = RecurringAppointmentSeries::where('id', $series->id)->lockForUpdate()->first();

            // Find previous occurrence
            $prevAppointment = Appointment::where('recurring_series_id', $series->id)
                ->where('recurring_occurrence_date', '<', $splitDate)
                ->orderBy('recurring_occurrence_date', 'desc')
                ->first();

            if ($prevAppointment) {
                $lockedSeries->update([
                    'ends_at' => $prevAppointment->recurring_occurrence_date,
                ]);
            } else {
                $lockedSeries->update([
                    'ends_at' => $splitDate,
                ]);
            }

            // Cancel future appointments
            $futureAppointments = Appointment::where('recurring_series_id', $series->id)
                ->where('recurring_occurrence_date', '>=', $splitDate)
                ->whereIn('status', [
                    AppointmentStatus::Booked,
                    AppointmentStatus::PendingPayment,
                    AppointmentStatus::Prepaid,
                ])
                ->get();

            foreach ($futureAppointments as $appt) {
                app(BookingService::class)->cancel($appt);
            }

            $lockedSeries->update(['status' => RecurringSeriesStatus::Cancelled]);

            return $futureAppointments->count();
        });
    }

    /**
     * Preview for split: generate dates from split_point, exclude old future IDs.
     */
    public function previewSplit(
        Appointment $splitAppointment,
        RecurrenceType $recurrenceType,
        int $interval,
        ?array $weekdays,
        ?Carbon $endsAt,
        ?int $occurrencesCount,
    ): array {
        $master = $splitAppointment->master;
        $service = $splitAppointment->masterService;
        $tz = $master->getTimezone();
        $splitDate = \Illuminate\Support\Carbon::parse($splitAppointment->recurring_occurrence_date, $tz);
        $startTime = $splitAppointment->start_time->timezone($tz)->format('H:i');
        $durationMinutes = $service?->effective_duration ?? 60;

        // Collect all future appointment IDs in this series (they will be cancelled/replaced)
        $excludeIds = [];
        if ($splitAppointment->recurring_series_id) {
            $excludeIds = Appointment::where('recurring_series_id', $splitAppointment->recurring_series_id)
                ->where('recurring_occurrence_date', '>=', $splitAppointment->recurring_occurrence_date)
                ->pluck('id')
                ->all();
        }

        return $this->preview(
            master: $master,
            startDate: $splitDate,
            startTime: $startTime,
            durationMinutes: $durationMinutes,
            recurrenceType: $recurrenceType,
            interval: $interval,
            weekdays: $weekdays,
            endsAt: $endsAt,
            occurrencesCount: $occurrencesCount,
            excludeAppointmentId: null,
            excludeAppointmentIds: $excludeIds,
        );
    }

    private function estimateEndDate(
        Carbon $startDate,
        RecurrenceType $type,
        int $interval,
        int $count,
    ): Carbon {
        // We need `count` occurrences, each `interval` apart.
        // The last occurrence is at (count - 1) * interval from start.
        // Add 1 extra interval as safety margin for off-by-one in generator.
        $span = max(1, ($count - 1) * $interval + $interval);

        return match ($type) {
            RecurrenceType::Daily => $startDate->copy()->addDays($span),
            RecurrenceType::Weekly => $startDate->copy()->addWeeks($span),
        };
    }
}
