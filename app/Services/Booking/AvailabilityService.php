<?php

namespace App\Services\Booking;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\BlockedTime;
use App\Models\User;
use App\Models\WorkingHour;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class AvailabilityService
{
    /**
     * Возвращает массив дат (Y-m-d), в которые есть хотя бы один свободный слот.
     * Загружает данные за месяц 3 запросами вместо N*3 (N = дней в месяце).
     */
    public function getAvailableDates(
        User $master,
        int $year,
        int $month,
        int $serviceDuration,
    ): array {
        $cacheKey = "availability_dates:{$master->id}:{$year}:{$month}:{$serviceDuration}";
        $cacheTags = ["availability:{$master->id}"];

        try {
            return Cache::tags($cacheTags)->remember($cacheKey, 300, function () use ($master, $year, $month, $serviceDuration) {
                return $this->computeAvailableDates($master, $year, $month, $serviceDuration);
            });
        } catch (\Throwable) {
            // Fallback если Cache::tags() не поддерживается драйвером
            return $this->computeAvailableDates($master, $year, $month, $serviceDuration);
        }
    }

    /**
     * Возвращает слоты для конкретного дня. Оставляем как есть — одиночный вызов из виджета.
     */
    public function getAvailableSlots(
        User $master,
        Carbon $date,
        int $serviceDuration,
    ): array {
        $tz = $master->getTimezone();
        $localDate = $date->copy()->timezone($tz)->startOfDay();
        $dayOfWeek = $localDate->dayOfWeek;

        $workingHour = WorkingHour::where('user_id', $master->id)
            ->where('day_of_week', $dayOfWeek)
            ->first();

        if (! $workingHour || ! $workingHour->is_working) {
            return [];
        }

        $dayStart = $localDate->copy()->setTimeFromTimeString($workingHour->start_time);
        $dayEnd = $localDate->copy()->setTimeFromTimeString($workingHour->end_time);

        $breakPeriods = $this->getBreakPeriods($workingHour, $localDate);
        $bookedPeriods = $this->getBookedPeriods($master, $localDate);
        $blockedPeriods = $this->getBlockedPeriods($master, $localDate);

        $allUnavailable = $breakPeriods->concat($bookedPeriods)->concat($blockedPeriods);

        $slotInterval = $master->slot_interval ?? 30;

        return $this->generateSlots(
            $dayStart,
            $dayEnd,
            $slotInterval,
            $serviceDuration,
            $localDate,
            $allUnavailable,
            $tz,
        );
    }

    public function isSlotAvailable(
        User $master,
        Carbon $startDateTime,
        int $durationMinutes,
        ?string $excludeAppointmentId = null,
    ): bool {
        return $this->isWithinWorkingHours($master, $startDateTime, $durationMinutes)
            && $this->isSlotFree($master, $startDateTime, $durationMinutes, $excludeAppointmentId);
    }

    public function isWithinWorkingHours(
        User $master,
        Carbon $startDateTime,
        int $durationMinutes,
    ): bool {
        $tz = $master->getTimezone();
        $localSlot = $startDateTime->copy()->timezone($tz);

        if ($localSlot->lt(Carbon::now($tz))) {
            return false;
        }

        $endDateTime = $localSlot->copy()->addMinutes($durationMinutes);
        $dayOfWeek = $localSlot->dayOfWeek;
        $workingHour = WorkingHour::where('user_id', $master->id)
            ->where('day_of_week', $dayOfWeek)->first();

        if (! $workingHour || ! $workingHour->is_working) {
            return false;
        }

        $dayStart = $localSlot->copy()->setTimeFromTimeString($workingHour->start_time);
        $dayEnd = $localSlot->copy()->setTimeFromTimeString($workingHour->end_time);

        return ! ($localSlot->lt($dayStart) || $endDateTime->gt($dayEnd));
    }

    public function isSlotFree(
        User $master,
        Carbon $startDateTime,
        int $durationMinutes,
        ?string $excludeAppointmentId = null,
    ): bool {
        $tz = $master->getTimezone();
        $localSlot = $startDateTime->copy()->timezone($tz);
        $endDateTime = $localSlot->copy()->addMinutes($durationMinutes);

        $dayOfWeek = $localSlot->dayOfWeek;
        $workingHour = WorkingHour::where('user_id', $master->id)
            ->where('day_of_week', $dayOfWeek)->first();

        $breakPeriods = $workingHour ? $this->getBreakPeriods($workingHour, $localSlot) : collect();
        $bookedPeriods = $this->getBookedPeriods($master, $localSlot, $excludeAppointmentId);
        $blockedPeriods = $this->getBlockedPeriods($master, $localSlot);

        $allUnavailable = $breakPeriods->concat($bookedPeriods)->concat($blockedPeriods);

        return ! $allUnavailable->contains(
            fn (array $period) => $localSlot->lt($period['end']) && $endDateTime->gt($period['start'])
        );
    }

    public function isSlotBookedOrBlocked(
        User $master,
        Carbon $startDateTime,
        int $durationMinutes,
        ?string $excludeAppointmentId = null,
    ): bool {
        $tz = $master->getTimezone();
        $localSlot = $startDateTime->copy()->timezone($tz);
        $endDateTime = $localSlot->copy()->addMinutes($durationMinutes);

        $bookedPeriods = $this->getBookedPeriods($master, $localSlot, $excludeAppointmentId);
        $blockedPeriods = $this->getBlockedPeriods($master, $localSlot);

        $conflicts = $bookedPeriods->concat($blockedPeriods);

        return $conflicts->contains(
            fn (array $period) => $localSlot->lt($period['end']) && $endDateTime->gt($period['start'])
        );
    }

    public function checkBreakIntersection(
        User $master,
        Carbon $startDateTime,
        int $durationMinutes,
    ): ?array {
        $tz = $master->getTimezone();
        $localSlot = $startDateTime->copy()->timezone($tz);
        $dayOfWeek = $localSlot->dayOfWeek;
        $workingHour = WorkingHour::where('user_id', $master->id)
            ->where('day_of_week', $dayOfWeek)
            ->first();

        if (! $workingHour || ! $workingHour->hasBreak()) {
            return null;
        }

        $endDateTime = $localSlot->copy()->addMinutes($durationMinutes);
        $breakStart = $localSlot->copy()->setTimeFromTimeString($workingHour->break_start_time);
        $breakEnd = $localSlot->copy()->setTimeFromTimeString($workingHour->break_end_time);

        if ($localSlot->lt($breakEnd) && $endDateTime->gt($breakStart)) {
            return [
                'break_start' => $workingHour->break_start_time,
                'break_end' => $workingHour->break_end_time,
            ];
        }

        return null;
    }

    // ═══════════════════════════════════════════════════════════════
    //  Batch-loading methods (3 queries for entire month)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Рассчитывает доступные даты за месяц, загружая данные 3 запросами.
     */
    private function computeAvailableDates(
        User $master,
        int $year,
        int $month,
        int $serviceDuration,
    ): array {
        $tz = $master->getTimezone();
        $daysInMonth = Carbon::create($year, $month, 1, 0, 0, 0, $tz)->daysInMonth;
        $slotInterval = $master->slot_interval ?? 30;

        // 3 запроса на весь месяц
        $workingHours = $this->loadWorkingHoursForMonth($master);
        $bookedByDate = $this->loadBookedPeriodsForMonth($master, $year, $month, $tz);
        $blockedByDate = $this->loadBlockedPeriodsForMonth($master, $year, $month, $tz);

        $dates = [];

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = Carbon::create($year, $month, $day, 0, 0, 0, $tz);

            if ($date->copy()->timezone($tz)->lt(Carbon::now($tz)->startOfDay())) {
                continue;
            }

            $slots = $this->getAvailableSlotsFromData(
                $master,
                $date,
                $serviceDuration,
                $workingHours,
                $bookedByDate,
                $blockedByDate,
                $slotInterval,
            );

            if (! empty($slots)) {
                $dates[] = $date->format('Y-m-d');
            }
        }

        return $dates;
    }

    /**
     * Запрос 1: загружает рабочие часы мастера, индексирует по day_of_week.
     */
    private function loadWorkingHoursForMonth(User $master): Collection
    {
        return WorkingHour::where('user_id', $master->id)
            ->get()
            ->keyBy('day_of_week');
    }

    /**
     * Запрос 2: загружает занятые слоты за весь месяц, группирует по дате (Y-m-d в таймзоне мастера).
     */
    private function loadBookedPeriodsForMonth(
        User $master,
        int $year,
        int $month,
        string $tz,
    ): array {
        $utcStart = Carbon::create($year, $month, 1, 0, 0, 0, $tz)
            ->startOfDay()->timezone('UTC');
        $utcEnd = Carbon::create($year, $month, 1, 0, 0, 0, $tz)
            ->endOfMonth()->endOfDay()->timezone('UTC');

        $blockingStatuses = [
            AppointmentStatus::Booked,
            AppointmentStatus::PendingPayment,
            AppointmentStatus::Prepaid,
            AppointmentStatus::Paid,
        ];

        $appointments = Appointment::where('master_id', $master->id)
            ->whereIn('status', $blockingStatuses)
            ->whereBetween('start_time', [$utcStart, $utcEnd])
            ->with('service')
            ->get();

        $grouped = [];
        foreach ($appointments as $a) {
            $start = Carbon::parse($a->start_time)->timezone($tz);
            $duration = $a->service->duration_minutes ?? 60;
            $dateKey = $start->format('Y-m-d');

            $grouped[$dateKey][] = [
                'start' => $start,
                'end' => $start->copy()->addMinutes($duration),
            ];
        }

        return $grouped;
    }

    /**
     * Запрос 3: загружает блокировки за весь месяц, группирует по дате (Y-m-d в таймзоне мастера).
     */
    private function loadBlockedPeriodsForMonth(
        User $master,
        int $year,
        int $month,
        string $tz,
    ): array {
        $utcStart = Carbon::create($year, $month, 1, 0, 0, 0, $tz)
            ->startOfDay()->timezone('UTC');
        $utcEnd = Carbon::create($year, $month, 1, 0, 0, 0, $tz)
            ->endOfMonth()->endOfDay()->timezone('UTC');

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
            $monthEnd = Carbon::create($year, $month, 1, 0, 0, 0, $tz)->endOfMonth()->startOfDay();

            while ($day->lte($monthEnd) && $day->lte($end)) {
                $grouped[$day->format('Y-m-d')][] = $entry;
                $day->addDay();
            }
        }

        return $grouped;
    }

    // ═══════════════════════════════════════════════════════════════
    //  Slot calculation from pre-loaded data (zero DB queries)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Рассчитывает слоты для одного дня из предзагруженных данных. Без запросов к БД.
     */
    private function getAvailableSlotsFromData(
        User $master,
        Carbon $date,
        int $serviceDuration,
        Collection $workingHours,
        array $bookedByDate,
        array $blockedByDate,
        int $slotInterval,
    ): array {
        $tz = $master->getTimezone();
        $localDate = $date->copy()->timezone($tz)->startOfDay();
        $dayOfWeek = $localDate->dayOfWeek;
        $dateKey = $localDate->format('Y-m-d');

        $workingHour = $workingHours->get($dayOfWeek);

        if (! $workingHour || ! $workingHour->is_working) {
            return [];
        }

        $dayStart = $localDate->copy()->setTimeFromTimeString($workingHour->start_time);
        $dayEnd = $localDate->copy()->setTimeFromTimeString($workingHour->end_time);

        $breakPeriods = $this->getBreakPeriods($workingHour, $localDate);
        $bookedPeriods = collect($bookedByDate[$dateKey] ?? []);
        $blockedPeriods = collect($blockedByDate[$dateKey] ?? []);

        $allUnavailable = $breakPeriods->concat($bookedPeriods)->concat($blockedPeriods);

        return $this->generateSlots(
            $dayStart,
            $dayEnd,
            $slotInterval,
            $serviceDuration,
            $localDate,
            $allUnavailable,
            $tz,
        );
    }

    // ═══════════════════════════════════════════════════════════════
    //  Single-day methods (used by getAvailableSlots, isSlotFree, etc.)
    // ═══════════════════════════════════════════════════════════════

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

    private function getBookedPeriods(User $master, Carbon $date, ?string $excludeAppointmentId = null): Collection
    {
        $tz = $master->getTimezone();
        $utcStart = $date->copy()->startOfDay()->timezone('UTC');
        $utcEnd = $date->copy()->endOfDay()->timezone('UTC');

        $blockingStatuses = [
            AppointmentStatus::Booked,
            AppointmentStatus::PendingPayment,
            AppointmentStatus::Prepaid,
            AppointmentStatus::Paid,
        ];

        $appointments = Appointment::where('master_id', $master->id)
            ->whereIn('status', $blockingStatuses)
            ->whereBetween('start_time', [$utcStart, $utcEnd])
            ->when($excludeAppointmentId, fn ($q) => $q->where('id', '!=', $excludeAppointmentId))
            ->with('service')
            ->get();

        return $appointments->map(function (Appointment $a) use ($tz) {
            $start = Carbon::parse($a->start_time)->timezone($tz);
            $duration = $a->service->duration_minutes ?? 60;

            return [
                'start' => $start,
                'end' => $start->copy()->addMinutes($duration),
            ];
        });
    }

    private function getBlockedPeriods(User $master, Carbon $date): Collection
    {
        $tz = $master->getTimezone();
        $utcStart = $date->copy()->startOfDay()->timezone('UTC');
        $utcEnd = $date->copy()->endOfDay()->timezone('UTC');

        return collect(BlockedTime::where('user_id', $master->id)
            ->where('start_datetime', '<=', $utcEnd)
            ->where('end_datetime', '>=', $utcStart)
            ->get()
            ->map(fn (BlockedTime $b) => [
                'start' => $b->start_datetime->copy()->timezone($tz),
                'end' => $b->end_datetime->copy()->timezone($tz),
            ]));
    }

    private function generateSlots(
        Carbon $dayStart,
        Carbon $dayEnd,
        int $interval,
        int $serviceDuration,
        Carbon $date,
        Collection $unavailablePeriods,
        string $timezone,
    ): array {
        $slots = [];
        $now = Carbon::now($timezone);

        $periods = $unavailablePeriods->values()->all();

        $slotStart = $dayStart->copy();

        while (true) {
            $slotEnd = $slotStart->copy()->addMinutes($serviceDuration);

            if ($slotEnd->gt($dayEnd)) {
                break;
            }

            if ($date->isToday() && $slotStart->lt($now)) {
                $slotStart->addMinutes($interval);

                continue;
            }

            $fits = true;
            foreach ($periods as $period) {
                if (! is_array($period) || ! isset($period['start'], $period['end'])) {
                    continue;
                }

                $periodStart = $period['start'];
                $periodEnd = $period['end'];

                if (! $periodStart instanceof CarbonInterface || ! $periodEnd instanceof CarbonInterface) {
                    continue;
                }

                if ($slotStart->lt($periodEnd) && $slotEnd->gt($periodStart)) {
                    $fits = false;
                    break;
                }
            }

            if ($fits) {
                $slots[] = $slotStart->format('H:i');
            }

            $slotStart->addMinutes($interval);
        }

        return $slots;
    }
}
