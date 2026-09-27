<?php

namespace App\Services\Booking;

use App\Enums\AppointmentStatus;
use App\Enums\RecurringSeriesStatus;
use App\Models\Appointment;
use App\Models\BlockedTime;
use App\Models\MasterService;
use App\Models\RecurringBlockedTimeSeries;
use App\Models\User;
use App\Models\WorkingHour;
use App\Services\Recurrence\RecurrenceRule;
use App\Services\Recurrence\RecurrenceService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class FreeWindowsService
{
    public function __construct(
        private readonly RecurrenceService $recurrenceService = new RecurrenceService(),
    ) {}

    /**
     * Get free intervals for a master over a date range.
     *
     * When serviceId is provided: returns discrete start times (service mode).
     * When serviceId is null: returns continuous free intervals (all-services mode).
     *
     * @return array{mode: string, timezone: string, date_from: string, date_to: string, booking_url: string, days: array}
     */
    public function getFreeWindows(
        User $master,
        string $dateFrom,
        string $dateTo,
        ?string $serviceId = null,
    ): array {
        $tz = $master->getTimezone();

        $rangeStart = Carbon::parse($dateFrom, $tz)->startOfDay();
        $rangeEnd = Carbon::parse($dateTo, $tz)->endOfDay();

        // Load common data
        $workingHours = $this->loadWorkingHours($master);
        $bookedByDate = $this->loadBookedPeriods($master, $rangeStart, $rangeEnd, $tz);
        $blockedByDate = $this->loadBlockedPeriods($master, $rangeStart, $rangeEnd, $tz);

        $days = [];
        $current = $rangeStart->copy()->startOfDay();

        while ($current->lte($rangeEnd)) {
            $dateKey = $current->format('Y-m-d');

            if ($serviceId) {
                // Service mode: get individual start times
                $slotInterval = $master->slot_interval ?? 30;
                $masterService = MasterService::where('id', $serviceId)
                    ->where('master_id', $master->id)
                    ->where('is_active', true)
                    ->first();

                if ($masterService) {
                    $duration = (int) $masterService->effective_duration;
                    $slots = $this->getAvailableStartsForDay(
                        $master, $current, $duration, $workingHours,
                        $bookedByDate[$dateKey] ?? [], $blockedByDate[$dateKey] ?? [],
                        $slotInterval, $tz,
                    );

                    if (! empty($slots)) {
                        $days[] = [
                            'date' => $dateKey,
                            'starts' => $slots,
                        ];
                    }
                }
            } else {
                // All-services mode: get continuous free intervals
                $ranges = $this->getFreeIntervalsForDay(
                    $master, $current, $workingHours,
                    $bookedByDate[$dateKey] ?? [], $blockedByDate[$dateKey] ?? [],
                    $tz,
                );

                if (! empty($ranges)) {
                    $days[] = [
                        'date' => $dateKey,
                        'ranges' => $ranges,
                    ];
                }
            }

            $current->addDay();
        }

        $bookingUrl = $this->buildBookingUrl($master, $serviceId);

        return [
            'mode' => $serviceId ? 'service' : 'all',
            'timezone' => $tz,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'booking_url' => $bookingUrl,
            'days' => $days,
        ];
    }

    /**
     * Get available start times for a single day (service mode).
     */
    private function getAvailableStartsForDay(
        User $master,
        Carbon $date,
        int $serviceDuration,
        Collection $workingHours,
        array $booked,
        array $blocked,
        int $slotInterval,
        string $tz,
    ): array {
        $localDate = $date->copy()->timezone($tz)->startOfDay();
        $dayOfWeek = $localDate->dayOfWeek;

        $workingHour = $workingHours->get($dayOfWeek);
        if (! $workingHour || ! $workingHour->is_working) {
            return [];
        }

        $dayStart = $localDate->copy()->setTimeFromTimeString($workingHour->start_time);
        $dayEnd = $localDate->copy()->setTimeFromTimeString($workingHour->end_time);

        $breakPeriods = $this->getBreakPeriods($workingHour, $localDate);
        $allUnavailable = $breakPeriods
            ->concat(collect($booked))
            ->concat(collect($blocked));

        $slots = [];
        $slotStart = $dayStart->copy();

        while (true) {
            $slotEnd = $slotStart->copy()->addMinutes($serviceDuration);
            if ($slotEnd->gt($dayEnd)) {
                break;
            }

            // Skip past slots for today
            if ($localDate->isToday() && $slotStart->lt(Carbon::now($tz))) {
                $slotStart->addMinutes($slotInterval);
                continue;
            }

            $fits = true;
            foreach ($allUnavailable as $period) {
                if ($slotStart->lt($period['end']) && $slotEnd->gt($period['start'])) {
                    $fits = false;
                    break;
                }
            }

            if ($fits) {
                $slots[] = $slotStart->format('H:i');
            }

            $slotStart->addMinutes($slotInterval);
        }

        return $slots;
    }

    /**
     * Get continuous free intervals for a single day (all-services mode).
     * No duration constraint — just working hours minus busy periods.
     */
    private function getFreeIntervalsForDay(
        User $master,
        Carbon $date,
        Collection $workingHours,
        array $booked,
        array $blocked,
        string $tz,
    ): array {
        $localDate = $date->copy()->timezone($tz)->startOfDay();
        $dayOfWeek = $localDate->dayOfWeek;

        $workingHour = $workingHours->get($dayOfWeek);
        if (! $workingHour || ! $workingHour->is_working) {
            return [];
        }

        $dayStart = $localDate->copy()->setTimeFromTimeString($workingHour->start_time);
        $dayEnd = $localDate->copy()->setTimeFromTimeString($workingHour->end_time);

        // Collect all busy periods for this day
        $busyPeriods = [];

        // Break periods
        $breakPeriods = $this->getBreakPeriods($workingHour, $localDate);
        foreach ($breakPeriods as $bp) {
            $busyPeriods[] = $bp;
        }

        // Booked periods
        foreach ($booked as $b) {
            $busyPeriods[] = $b;
        }

        // Blocked periods
        foreach ($blocked as $b) {
            $busyPeriods[] = $b;
        }

        if (empty($busyPeriods)) {
            // Entire working day is free
            return [
                [
                    'start' => $dayStart->format('H:i'),
                    'end' => $dayEnd->format('H:i'),
                ],
            ];
        }

        // Sort by start time
        usort($busyPeriods, fn ($a, $b) => $a['start']->lt($b['start']) ? -1 : ($a['start']->gt($b['start']) ? 1 : 0));

        // Merge overlapping busy periods
        $merged = [$busyPeriods[0]];
        for ($i = 1; $i < count($busyPeriods); $i++) {
            $last = end($merged);
            $current = $busyPeriods[$i];

            if ($current['start']->lte($last['end'])) {
                // Overlap: extend the end
                $merged[count($merged) - 1] = [
                    'start' => $last['start'],
                    'end' => $last['end']->gt($current['end']) ? $last['end'] : $current['end'],
                ];
            } else {
                $merged[] = $current;
            }
        }

        // Compute free intervals between busy periods
        $free = [];
        $cursor = $dayStart->copy();

        foreach ($merged as $busy) {
            if ($cursor->lt($busy['start'])) {
                $free[] = [
                    'start' => $cursor->format('H:i'),
                    'end' => $busy['start']->format('H:i'),
                ];
            }
            if ($busy['end']->gt($cursor)) {
                $cursor = $busy['end']->copy();
            }
        }

        // Remaining time after last busy period
        if ($cursor->lt($dayEnd)) {
            $free[] = [
                'start' => $cursor->format('H:i'),
                'end' => $dayEnd->format('H:i'),
            ];
        }

        // Filter out zero-length or negative ranges
        return array_values(array_filter($free, fn ($r) => $r['start'] < $r['end']));
    }

    /**
     * Build booking URL for the master.
     */
    private function buildBookingUrl(User $master, ?string $serviceId): string
    {
        $base = '/book/' . $master->master_slug;

        if ($serviceId) {
            $masterService = MasterService::find($serviceId);
            if ($masterService) {
                return $base . '?service_id=' . $masterService->id;
            }
        }

        return $base;
    }

    private function loadWorkingHours(User $master): Collection
    {
        return WorkingHour::where('user_id', $master->id)
            ->get()
            ->keyBy('day_of_week');
    }

    private function loadBookedPeriods(
        User $master,
        CarbonInterface $rangeStart,
        CarbonInterface $rangeEnd,
        string $tz,
    ): array {
        $utcStart = $rangeStart->copy()->startOfDay()->timezone('UTC');
        $utcEnd = $rangeEnd->copy()->endOfDay()->timezone('UTC');

        $blockingStatuses = [
            AppointmentStatus::Booked,
            AppointmentStatus::PendingPayment,
            AppointmentStatus::Prepaid,
            AppointmentStatus::Paid,
        ];

        $appointments = Appointment::where('master_id', $master->id)
            ->whereIn('status', $blockingStatuses)
            ->where('start_time', '<', $utcEnd)
            ->whereRaw(
                "start_time + (COALESCE(duration, 60) * INTERVAL '1 minute') > ?",
                [$utcStart],
            )
            ->get();

        $grouped = [];
        foreach ($appointments as $a) {
            $start = Carbon::parse($a->start_time)->timezone($tz);
            $duration = $a->display_duration ?: 60;
            $dateKey = $start->format('Y-m-d');

            $grouped[$dateKey][] = [
                'start' => $start,
                'end' => $start->copy()->addMinutes($duration),
            ];
        }

        return $grouped;
    }

    private function loadBlockedPeriods(
        User $master,
        CarbonInterface $rangeStart,
        CarbonInterface $rangeEnd,
        string $tz,
    ): array {
        $utcStart = $rangeStart->copy()->startOfDay()->timezone('UTC');
        $utcEnd = $rangeEnd->copy()->endOfDay()->timezone('UTC');

        // Regular blocked times
        $blockedTimes = BlockedTime::where('user_id', $master->id)
            ->where('start_datetime', '<=', $utcEnd)
            ->where('end_datetime', '>=', $utcStart)
            ->get();

        $grouped = [];
        foreach ($blockedTimes as $b) {
            $start = $b->start_datetime->copy()->timezone($tz);
            $end = $b->end_datetime->copy()->timezone($tz);
            $entry = ['start' => $start, 'end' => $end];

            $day = $start->copy()->startOfDay();
            $rangeEndDay = $rangeEnd->copy()->startOfDay();
            $guard = 0;
            while ($day->lte($rangeEndDay) && $day->lte($end)) {
                if (++$guard > 370) {
                    break;
                }
                $grouped[$day->format('Y-m-d')][] = $entry;
                $day = $day->addDay();
            }
        }

        // Recurring blocked time series
        $this->loadRecurringBlockedPeriods($master, $rangeStart, $rangeEnd, $tz, $grouped);

        return $grouped;
    }

    private function loadRecurringBlockedPeriods(
        User $master,
        CarbonInterface $rangeStart,
        CarbonInterface $rangeEnd,
        string $tz,
        array &$grouped,
    ): void {
        $series = RecurringBlockedTimeSeries::where('user_id', $master->id)
            ->where('status', RecurringSeriesStatus::Active)
            ->with('exceptions')
            ->get();

        if ($series->isEmpty()) {
            return;
        }

        $rangeStartDay = $rangeStart->copy()->startOfDay();
        $rangeEndDay = $rangeEnd->copy()->startOfDay();

        foreach ($series as $s) {
            $rule = RecurrenceRule::fromArray([
                'recurrence_type' => $s->recurrence_type->value,
                'interval' => $s->interval,
                'weekdays' => $s->weekdays,
                'start_date' => $s->start_date->format('Y-m-d'),
                'ends_at' => $s->ends_at?->format('Y-m-d'),
                'timezone' => $s->timezone,
            ]);

            $occurrences = $this->recurrenceService->generateOccurrences($rule, $rangeStartDay, $rangeEndDay);

            $exceptionsByDate = [];
            foreach ($s->exceptions as $ex) {
                $exceptionsByDate[$ex->occurrence_date->format('Y-m-d')] = $ex;
            }

            foreach ($occurrences as $date) {
                $dateKey = $date->format('Y-m-d');

                if (isset($exceptionsByDate[$dateKey])) {
                    $ex = $exceptionsByDate[$dateKey];
                    if ($ex->type->value === 'skip') {
                        continue;
                    }
                    $startTime = $ex->override_start_time ?? $s->start_time;
                    $endTime = $ex->override_end_time ?? $s->end_time;
                } else {
                    $startTime = $s->start_time;
                    $endTime = $s->end_time;
                }

                $start = $date->copy()->setTimeFromTimeString($startTime);
                $end = $date->copy()->setTimeFromTimeString($endTime);

                $grouped[$dateKey][] = ['start' => $start, 'end' => $end];
            }
        }
    }

    private function getBreakPeriods(WorkingHour $workingHour, Carbon $date): Collection
    {
        if (! $workingHour->hasBreak()) {
            return collect();
        }

        $breakStart = $date->copy()->setTimeFromTimeString($workingHour->break_start_time);
        $breakEnd = $date->copy()->setTimeFromTimeString($workingHour->break_end_time);

        return collect([
            ['start' => $breakStart, 'end' => $breakEnd],
        ]);
    }
}
