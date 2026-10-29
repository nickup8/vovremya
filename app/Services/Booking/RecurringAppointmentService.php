<?php

namespace App\Services\Booking;

use App\DTOs\AppointmentWindowFreed;
use App\Enums\AppointmentNotificationEventType;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Enums\RecurrenceType;
use App\Enums\RecurringSeriesStatus;
use App\Enums\SlotOpportunitySourceType;
use App\Models\Appointment;
use App\Models\AppointmentNotificationDelivery;
use App\Models\AppointmentNotificationEvent;
use App\Models\MasterService;
use App\Models\RecurringAppointmentSeries;
use App\Models\User;
use App\Services\FreedWindowDispatcher;
use App\Services\Notification\AppointmentNotificationRecorder;
use App\Services\Recurrence\RecurrenceRule;
use App\Services\Recurrence\RecurrenceService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecurringAppointmentService
{
    public function __construct(
        private RecurrenceService $recurrenceService = new RecurrenceService,
        private AvailabilityService $availabilityService = new AvailabilityService,
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

        // Batch preload: load all data once for the entire range instead of per-date N+1 queries
        $context = $this->availabilityService->buildPreviewContext(
            $master,
            $dates[0] ?? $startDate,
            $dates[array_key_last($dates)] ?? $startDate,
        );
        $checkSlot = $context['check'];

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

            $dateKey = $date->format('Y-m-d');
            $reason = $checkSlot(
                $dateKey,
                $startTime,
                $durationMinutes,
                $excludeAppointmentId,
                $excludeAppointmentIds,
            );

            if ($reason === null) {
                $available[] = $dateKey;
            } else {
                $conflicts[] = [
                    'date' => $dateKey,
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

            // Одно согласованное сообщение о серии — не N per-appointment.
            // Не уведомляем о создании «старых» визитов и о технической
            // генерации очередного экземпляра уже объявленной серии.
            $firstFuture = Appointment::with(['client', 'master', 'masterService.catalog'])
                ->where('recurring_series_id', $series->id)
                ->where('client_id', $clientId)
                ->where('start_time', '>', now())
                ->orderBy('start_time')
                ->first();

            if ($firstFuture !== null && $firstFuture->client !== null) {
                $affected = Appointment::where('recurring_series_id', $series->id)
                    ->where('client_id', $clientId)
                    ->where('start_time', '>', now())
                    ->count();

                $recorder = app(AppointmentNotificationRecorder::class);
                $version = $recorder->bumpVersion($firstFuture);
                $recorder->record(
                    $firstFuture,
                    AppointmentNotificationEventType::SeriesCreated,
                    [
                        'service_name' => $firstFuture->display_name,
                        'affected_count' => $affected,
                        'from_date' => $firstFuture->start_time->timezone($tz)->format('d.m.Y'),
                        // Снимок первого визита для сверки актуальности
                        // при доставке (перенос/отмена гасят устаревший
                        // снимок; правка цены — нет).
                        'first_start_time' => $firstFuture->start_time->toIso8601String(),
                    ],
                    $version,
                    $series->id,
                );
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
        ?string $newMasterId = null,
    ): array {
        return app(BookingService::class)->rescheduleAppointment(
            appointment: $appointment,
            newDate: $newDate,
            newTime: $newTime,
            ignoreWarnings: true,
            confirmOutsideHours: true,
            newMasterId: $newMasterId,
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
     * Split series at a point with hybrid rebind matching.
     * Preserves existing appointment IDs where possible via exact-date + positional matching.
     * No false cancellations — existing appointments are updated in place.
     *
     * @param  array{allowed_dates: string[]}  $previewResult
     *
     * @throws ValidationException if Prepaid/Paid appointments are in the affected range
     */
    public function splitSeries(
        RecurringAppointmentSeries $series,
        Appointment $splitAppointment,
        array $newParams,
        array $previewResult,
    ): RecurringAppointmentSeries {
        $tz = $series->timezone;
        $splitDate = $splitAppointment->recurring_occurrence_date;
        $newDates = $previewResult['dates'] ?? [];
        $newMasterServiceId = $newParams['master_service_id'] ?? $series->master_service_id;
        $newStartTime = $newParams['start_time'] ?? $series->start_time;
        $newService = MasterService::query()
            ->whereKey($newMasterServiceId)
            ->firstOrFail();

        // Pre-check: ABORT if Paid/Prepaid in affected range
        $affectedAll = Appointment::where('recurring_series_id', $series->id)
            ->where('recurring_occurrence_date', '>=', $splitDate)
            ->get();

        $paidPrepaid = $affectedAll->filter(fn ($a) => in_array($a->status, [
            AppointmentStatus::Paid,
            AppointmentStatus::Prepaid,
        ]));

        if ($paidPrepaid->isNotEmpty()) {
            throw ValidationException::withMessages([
                'message' => 'В серии есть оплаченная запись, которую нельзя изменить автоматически.',
            ]);
        }

        $freedWindows = [];

        $newSeries = DB::transaction(function () use (
            $series, $splitDate, $newParams, $newDates,
            $newMasterServiceId, $newStartTime, $newService, $tz, &$freedWindows,
        ) {
            $master = $series->master;
            $service = $newService;

            // 1. Lock series
            $lockedSeries = RecurringAppointmentSeries::where('id', $series->id)->lockForUpdate()->first();

            // 2. Truncate old series
            $prevAppointment = Appointment::where('recurring_series_id', $series->id)
                ->where('recurring_occurrence_date', '<', $splitDate)
                ->orderBy('recurring_occurrence_date', 'desc')
                ->first();

            if ($prevAppointment) {
                $lockedSeries->update(['ends_at' => $prevAppointment->recurring_occurrence_date]);
            } else {
                $lockedSeries->update([
                    'ends_at' => $splitDate,
                    'status' => RecurringSeriesStatus::Cancelled,
                ]);
            }

            // 3. Compute remaining count for new series
            $newOccurrencesCount = $newParams['occurrences_count'] ?? null;
            $newEndsAt = $newParams['ends_at'] ?? null;
            if ($newOccurrencesCount === null && $newEndsAt === null) {
                // Auto-compute remaining from original series rule
                $newOccurrencesCount = $this->getRemainingOccurrencesCount($series, $splitDate);
                if ($newOccurrencesCount <= 1) {
                    throw ValidationException::withMessages([
                        'message' => 'Это последняя запись серии. Измените только эту запись.',
                    ]);
                }
            }

            // 4. Create new series record
            $newSeries = RecurringAppointmentSeries::create([
                'workspace_id' => $series->workspace_id,
                'master_id' => $series->master_id,
                'client_id' => $series->client_id,
                'master_service_id' => $newMasterServiceId,
                'start_date' => $splitDate,
                'start_time' => $newStartTime,
                'recurrence_type' => RecurrenceType::from($newParams['recurrence_type']),
                'interval' => $newParams['interval'],
                'weekdays' => $newParams['weekdays'] ?? null,
                'ends_at' => $newEndsAt,
                'occurrences_count' => $newOccurrencesCount,
                'timezone' => $tz,
                'status' => RecurringSeriesStatus::Active,
            ]);

            // 4. Lock future appointments
            $futureAppts = Appointment::where('recurring_series_id', $series->id)
                ->where('recurring_occurrence_date', '>=', $splitDate)
                ->whereNotIn('status', [
                    AppointmentStatus::Cancelled,
                    AppointmentStatus::Paid,
                    AppointmentStatus::NoShow,
                ])
                ->orderBy('recurring_occurrence_date')
                ->lockForUpdate()
                ->get();

            $oldByDate = [];
            foreach ($futureAppts as $appt) {
                $oldByDate[$appt->recurring_occurrence_date] = $appt;
            }

            // 5. Hybrid matching
            // A. Exact date matches
            $matchedOld = [];
            $matchedNew = [];
            foreach ($newDates as $newDate) {
                if (isset($oldByDate[$newDate])) {
                    $matchedOld[] = $oldByDate[$newDate];
                    $matchedNew[] = $newDate;
                    unset($oldByDate[$newDate]);
                }
            }

            // B. Remaining old/new — positional fallback
            $remainingOld = array_values($oldByDate);
            $remainingNew = array_values(array_diff($newDates, $matchedNew));

            $rebindPairs = [];
            $count = min(count($remainingOld), count($remainingNew));
            for ($i = 0; $i < $count; $i++) {
                $rebindPairs[] = ['old' => $remainingOld[$i], 'new_date' => $remainingNew[$i]];
            }

            // Old overflow (indexes >= count)
            $oldOverflow = array_slice($remainingOld, $count);

            // New overflow (indexes >= count)
            $newOverflow = array_slice($remainingNew, $count);

            // 6. Rebind exact matches (update series_id + date)
            foreach ($matchedOld as $i => $appt) {
                $this->rebindAppointment($appt, $newSeries->id, $matchedNew[$i], $newMasterServiceId, $service, $newStartTime, $tz, $freedWindows);
            }

            // 7. Rebind positional matches
            foreach ($rebindPairs as $pair) {
                $this->rebindAppointment($pair['old'], $newSeries->id, $pair['new_date'], $newMasterServiceId, $service, $newStartTime, $tz, $freedWindows);
            }

            // 8. Create new overflow appointments
            foreach ($newOverflow as $newDate) {
                $startDateTime = Carbon::parse($newDate.' '.$newStartTime, $tz)->utc();

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
                    Log::warning('[recurring] skipping conflict at rebind materialization', [
                        'date' => $newDate,
                        'master_id' => $master->id,
                    ]);

                    continue;
                }

                Appointment::create([
                    'master_id' => $master->id,
                    'client_id' => $series->client_id,
                    'master_service_id' => $newMasterServiceId,
                    'price' => $service->effective_price,
                    'duration' => $service->effective_duration,
                    'service_name' => $service->catalog?->title ?? '',
                    'start_time' => $startDateTime,
                    'status' => AppointmentStatus::Booked,
                    'source' => AppointmentSource::Admin,
                    'recurring_series_id' => $newSeries->id,
                    'recurring_occurrence_date' => $newDate,
                ]);
            }

            // 9. Delete old overflow
            foreach ($oldOverflow as $appt) {
                if (in_array($appt->status, [
                    AppointmentStatus::Booked,
                    AppointmentStatus::PendingPayment,
                ], true)) {
                    // Capture freed window before deletion
                    if ($appt->status === AppointmentStatus::Booked && $master->isAutoFillEnabled()) {
                        $freedWindows[] = new AppointmentWindowFreed(
                            originEventId: (string) Str::uuid(),
                            chainId: null,
                            workspaceId: $master->workspace_id,
                            masterId: $master->id,
                            masterServiceId: $newMasterServiceId,
                            sourceAppointmentId: null,
                            sourceType: SlotOpportunitySourceType::Cancellation,
                            startTime: $appt->start_time,
                            duration: $appt->duration,
                        );
                    }
                    $appt->forceDelete();
                }
            }

            // Одно согласованное сообщение об изменении серии — ВНУТРИ
            // транзакции: между коммитом split и записью события не должно
            // быть окна, в котором уведомление теряется. rebindAppointment
            // сбрасывает reminder-*_sent_at, но не шлёт per-appointment
            // уведомлений. Группировка по получателям: визиты с разными
            // клиентами получают свои сообщения без чужих данных.
            $futureInNewSeries = Appointment::with(['client', 'master', 'masterService.catalog'])
                ->where('recurring_series_id', $newSeries->id)
                ->where('start_time', '>', now())
                ->get();

            $this->recordSeriesUpdatedNotification($series, $newSeries, $futureInNewSeries);

            return $newSeries;
        });

        // 10. Dispatch freed windows after commit
        foreach ($freedWindows as $window) {
            app(FreedWindowDispatcher::class)->dispatchAfterCommit($window);
        }

        return $newSeries;
    }

    /**
     * Rebind an existing appointment to a new series with updated params.
     */
    private function rebindAppointment(
        Appointment $appt,
        string $newSeriesId,
        string $newDate,
        string $newMasterServiceId,
        MasterService $service,
        string $newStartTime,
        string $tz,
        array &$freedWindows,
    ): void {
        $oldStartTime = $appt->start_time;
        $newStartDateTime = Carbon::parse($newDate.' '.$newStartTime, $tz)->utc();
        $timeChanged = $oldStartTime->ne($newStartDateTime);
        $master = $appt->master;

        // Capture freed window if time actually changed
        if ($timeChanged && $appt->status === AppointmentStatus::Booked && $master?->isAutoFillEnabled()) {
            $freedWindows[] = new AppointmentWindowFreed(
                originEventId: (string) Str::uuid(),
                chainId: null,
                workspaceId: $master->workspace_id,
                masterId: $master->id,
                masterServiceId: $appt->master_service_id,
                sourceAppointmentId: $appt->id,
                sourceType: SlotOpportunitySourceType::Reschedule,
                startTime: $oldStartTime,
                duration: $appt->duration,
            );
        }

        $appt->update([
            'recurring_series_id' => $newSeriesId,
            'recurring_occurrence_date' => $newDate,
            'start_time' => $newStartDateTime,
            'master_service_id' => $newMasterServiceId,
            'service_name' => $service->catalog?->title ?? '',
            'duration' => $service->effective_duration,
            'price' => $service->effective_price,
            'client_confirmed_at' => null,
            'reminder_24h_sent' => false,
            'reminder_final_sent' => false,
            'reminder_24h_sent_at' => null,
            'reminder_24h_dispatched_at' => null,
            'reminder_24h_failed_at' => null,
            'reminder_final_sent_at' => null,
            'reminder_final_dispatched_at' => null,
            'reminder_final_failed_at' => null,
        ]);

        // Каждый перебинденный экземпляр получает новую версию: иначе
        // его старая job (created/rescheduled по прежнему расписанию)
        // остаётся актуальной и отправляет устаревшее подтверждение.
        // schedule_version при этом растёт авто-хуком из-за смены
        // start_time — старые напоминания тоже инвалидируются.
        app(AppointmentNotificationRecorder::class)->bumpVersion($appt);
    }

    /**
     * Одно series-сообщение на группу получателей об изменении серии —
     * с новым временем расписания и счётчиком только своих визитов.
     *
     * @param  Collection<int, Appointment>  $affected
     */
    private function recordSeriesUpdatedNotification(
        RecurringAppointmentSeries $oldSeries,
        RecurringAppointmentSeries $newSeries,
        $affected,
    ): void {
        $recorder = app(AppointmentNotificationRecorder::class);

        /** @var Collection<string, Collection<int, Appointment>> $groups */
        $groups = $affected
            ->filter(fn (Appointment $appt) => $appt->client_id !== null)
            ->groupBy('client_id');

        foreach ($groups as $group) {
            $first = $group->sortBy('start_time')->first();

            if ($first === null) {
                continue;
            }

            $anchor = Appointment::query()
                ->whereKey($first->getKey())
                ->with(['client', 'master', 'masterService.catalog'])
                ->firstOrFail();

            if ($anchor->client === null) {
                continue;
            }

            $tz = $oldSeries->timezone ?? $anchor->master?->getTimezone() ?? 'UTC';

            $version = $recorder->bumpVersion($anchor);
            $recorder->record(
                $anchor,
                AppointmentNotificationEventType::SeriesUpdated,
                [
                    'service_name' => $anchor->display_name,
                    'affected_count' => $group->count(),
                    'from_date' => $first->start_time->timezone($tz)->format('d.m.Y'),
                    'time' => $anchor->start_time->timezone($tz)->format('H:i'),
                    'first_start_time' => $first->start_time->toIso8601String(),
                ],
                $version,
                $newSeries->id,
            );
        }
    }

    /**
     * Cancel this and all future occurrences.
     */
    public function cancelThisAndFuture(
        RecurringAppointmentSeries $series,
        Appointment $splitAppointment,
        ?User $actor = null,
    ): int {
        $splitDate = $splitAppointment->recurring_occurrence_date;

        return DB::transaction(function () use ($series, $splitDate, $actor) {
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
                app(BookingService::class)->cancel($appt, $actor, notifyClient: false);
            }

            $lockedSeries->update(['status' => RecurringSeriesStatus::Cancelled]);

            // Одно согласованное сообщение о серии вместо N per-appointment —
            // по группам получателей: визиты с разными клиентами получают
            // свои сообщения без чужих данных.
            $this->recordSeriesCancelledNotification($series, $futureAppointments);

            // Частичная отмена: оставшиеся активные визиты серии не
            // должны теряться — если создание/обновление ещё не
            // доставлено, фиксируем SeriesUpdated по остатку.
            $remaining = Appointment::where('recurring_series_id', $series->id)
                ->whereIn('status', [
                    AppointmentStatus::Booked,
                    AppointmentStatus::PendingPayment,
                    AppointmentStatus::Prepaid,
                ])
                ->where('start_time', '>', now())
                ->get();

            if ($remaining->isNotEmpty()) {
                $hasUndeliveredSeriesEvent = AppointmentNotificationEvent::query()
                    ->where('recurring_series_id', $series->id)
                    ->whereIn('event_type', [
                        AppointmentNotificationEventType::SeriesCreated,
                        AppointmentNotificationEventType::SeriesUpdated,
                    ])
                    ->whereDoesntHave('deliveries', function ($query) {
                        $query->where('status', AppointmentNotificationDelivery::STATUS_DELIVERED);
                    })
                    ->exists();

                if ($hasUndeliveredSeriesEvent) {
                    $this->recordSeriesUpdatedNotification($series, $series, $remaining);
                }
            }

            return $futureAppointments->count();
        });
    }

    /**
     * Cancel ALL active/cancellable appointments in the series.
     */
    public function cancelWholeSeries(
        RecurringAppointmentSeries $series,
        ?User $actor = null,
    ): int {
        return DB::transaction(function () use ($series, $actor) {
            $lockedSeries = RecurringAppointmentSeries::where('id', $series->id)->lockForUpdate()->first();

            $activeAppointments = Appointment::where('recurring_series_id', $series->id)
                ->whereIn('status', [
                    AppointmentStatus::Booked,
                    AppointmentStatus::PendingPayment,
                    AppointmentStatus::Prepaid,
                ])
                ->get();

            foreach ($activeAppointments as $appt) {
                app(BookingService::class)->cancel($appt, $actor, notifyClient: false);
            }

            $lockedSeries->update(['status' => RecurringSeriesStatus::Cancelled]);

            $this->recordSeriesCancelledNotification($series, $activeAppointments);

            return $activeAppointments->count();
        });
    }

    /**
     * Одно series-сообщение на группу получателей: визиты с разными
     * клиентами получают отдельные сообщения со счётчиком и датой
     * только своих визитов — без чужих данных.
     *
     * @param  Collection<int, Appointment>  $cancelledAppointments
     */
    private function recordSeriesCancelledNotification(
        RecurringAppointmentSeries $series,
        $cancelledAppointments,
    ): void {
        $recorder = app(AppointmentNotificationRecorder::class);

        /** @var Collection<string, Collection<int, Appointment>> $groups */
        $groups = $cancelledAppointments
            ->filter(fn (Appointment $appt) => $appt->client_id !== null)
            ->groupBy('client_id');

        foreach ($groups as $group) {
            $first = $group->sortBy('start_time')->first();

            if ($first === null) {
                continue;
            }

            $anchor = Appointment::query()
                ->whereKey($first->getKey())
                ->with(['client', 'master', 'masterService.catalog'])
                ->firstOrFail();

            if ($anchor->client === null) {
                continue;
            }

            $tz = $series->timezone ?? $anchor->master?->getTimezone() ?? 'UTC';
            $fromDate = $first->recurring_occurrence_date
                ?? $anchor->recurring_occurrence_date
                ?? $anchor->start_time->timezone($tz)->format('Y-m-d');

            $version = $recorder->bumpVersion($anchor);
            $recorder->record(
                $anchor,
                AppointmentNotificationEventType::SeriesCancelled,
                [
                    'service_name' => $anchor->display_name,
                    'affected_count' => $group->count(),
                    'from_date' => \Illuminate\Support\Carbon::parse($fromDate, $tz)->format('d.m.Y'),
                ],
                $version,
                $series->id,
            );
        }
    }

    /**
     * Preview for split: generate dates from split_point, exclude old future IDs.
     * Also checks for Paid/Prepaid appointments that would block the operation.
     */
    public function previewSplit(
        Appointment $splitAppointment,
        RecurrenceType $recurrenceType,
        int $interval,
        ?array $weekdays,
        ?Carbon $endsAt,
        ?int $occurrencesCount,
        ?string $startTime = null,
        ?string $serviceId = null,
    ): array {
        $master = $splitAppointment->master;
        $service = $serviceId ? MasterService::findOrFail($serviceId) : $splitAppointment->masterService;
        $tz = $master->getTimezone();
        $splitDate = Carbon::parse($splitAppointment->recurring_occurrence_date, $tz);
        $startTime = $startTime ?? $splitAppointment->start_time->timezone($tz)->format('H:i');
        $durationMinutes = $service?->effective_duration ?? 60;

        $remainingCount = null;
        if ($splitAppointment->recurring_series_id) {
            $series = $splitAppointment->recurringSeries;
            if ($series) {
                $remainingCount = $this->getRemainingOccurrencesCount($series, $splitAppointment->recurring_occurrence_date);

                // For this-and-future: auto-fill occurrencesCount from remaining
                if ($occurrencesCount === null) {
                    if ($remainingCount <= 1) {
                        return [
                            'total' => 0,
                            'available' => 0,
                            'conflicts' => [],
                            'dates' => [],
                            'current_date' => null,
                            'has_paid_conflict' => false,
                            'remaining_count' => $remainingCount,
                            'error' => 'Это последняя запись серии. Измените только эту запись.',
                        ];
                    }
                    $occurrencesCount = $remainingCount;
                }
            }
        }

        // Check for Paid/Prepaid in affected range
        $hasPaidConflict = false;
        if ($splitAppointment->recurring_series_id) {
            $hasPaidConflict = Appointment::where('recurring_series_id', $splitAppointment->recurring_series_id)
                ->where('recurring_occurrence_date', '>=', $splitAppointment->recurring_occurrence_date)
                ->whereIn('status', [AppointmentStatus::Paid, AppointmentStatus::Prepaid])
                ->exists();
        }

        // Collect all future appointment IDs in this series (they will be rebinding)
        $excludeIds = [];
        if ($splitAppointment->recurring_series_id) {
            $excludeIds = Appointment::where('recurring_series_id', $splitAppointment->recurring_series_id)
                ->where('recurring_occurrence_date', '>=', $splitAppointment->recurring_occurrence_date)
                ->pluck('id')
                ->all();
        }

        $result = $this->preview(
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

        $result['has_paid_conflict'] = $hasPaidConflict;
        $result['remaining_count'] = $remainingCount ?? $occurrencesCount;

        return $result;
    }

    /**
     * Compute the number of remaining occurrences from $splitDate onward,
     * using the series' own recurrence rule (not DB row count).
     *
     * This is position-based: if the series has 10 occurrences and we split at the 5th,
     * remaining = 6 (including the split date itself).
     *
     * Cancelled/NoShow occurrences still count as planned positions.
     * Force-deleted rows do NOT break the index because we regenerate from the rule.
     */
    public function getRemainingOccurrencesCount(
        RecurringAppointmentSeries $series,
        string $splitDate,
    ): int {
        $tz = $series->timezone;
        $ruleStart = Carbon::parse($series->start_date, $tz)->startOfDay();
        $splitCarbon = Carbon::parse($splitDate, $tz)->startOfDay();

        $endsAt = $series->ends_at
            ? Carbon::parse($series->ends_at, $tz)->startOfDay()
            : null;

        $rule = new RecurrenceRule(
            recurrenceType: $series->recurrence_type,
            interval: $series->interval,
            weekdays: $series->weekdays,
            startDate: $ruleStart,
            endsAt: $endsAt,
            timezone: $tz,
        );

        // Generate full range from series start to either ends_at or a generous horizon
        $rangeEnd = $endsAt
            ? $endsAt->copy()
            : $this->estimateEndDate($ruleStart, $series->recurrence_type, $series->interval, $series->occurrences_count ?? 52);

        $allOccurrences = $this->recurrenceService->generateOccurrences($rule, $ruleStart, $rangeEnd);

        // If count-bounded, trim to exactly occurrences_count
        if ($series->occurrences_count !== null && count($allOccurrences) > $series->occurrences_count) {
            $allOccurrences = array_slice($allOccurrences, 0, $series->occurrences_count);
        }

        // Find position of splitDate in the generated occurrences
        $splitPosition = null;
        foreach ($allOccurrences as $i => $date) {
            if ($date->format('Y-m-d') === $splitCarbon->format('Y-m-d')) {
                $splitPosition = $i;
                break;
            }
        }

        // If split date not found (edge case: gap in rule), count from first date >= splitDate
        if ($splitPosition === null) {
            foreach ($allOccurrences as $i => $date) {
                if ($date->gte($splitCarbon)) {
                    $splitPosition = $i;
                    break;
                }
            }
        }

        // Still not found — all occurrences are before split, or split is beyond series range
        if ($splitPosition === null) {
            return 0;
        }

        return count($allOccurrences) - $splitPosition;
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
