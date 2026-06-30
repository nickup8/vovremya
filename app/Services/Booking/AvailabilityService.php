<?php

namespace App\Services\Booking;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\BlockedTime;
use App\Models\User;
use App\Models\WorkingHour;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AvailabilityService
{
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

        $allUnavailable = $breakPeriods->merge($bookedPeriods)->merge($blockedPeriods);

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
        ?int $excludeAppointmentId = null,
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
        ?int $excludeAppointmentId = null,
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

        $allUnavailable = $breakPeriods->merge($bookedPeriods)->merge($blockedPeriods);

        return ! $allUnavailable->contains(
            fn (array $period) => $localSlot->lt($period['end']) && $endDateTime->gt($period['start'])
        );
    }

    public function isSlotBookedOrBlocked(
        User $master,
        Carbon $startDateTime,
        int $durationMinutes,
        ?int $excludeAppointmentId = null,
    ): bool {
        $tz = $master->getTimezone();
        $localSlot = $startDateTime->copy()->timezone($tz);
        $endDateTime = $localSlot->copy()->addMinutes($durationMinutes);

        $bookedPeriods = $this->getBookedPeriods($master, $localSlot, $excludeAppointmentId);
        $blockedPeriods = $this->getBlockedPeriods($master, $localSlot);

        $conflicts = $bookedPeriods->merge($blockedPeriods);

        return $conflicts->contains(
            fn (array $period) => $localSlot->lt($period['end']) && $endDateTime->gt($period['start'])
        );
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

    private function getBookedPeriods(User $master, Carbon $date, ?int $excludeAppointmentId = null): Collection
    {
        $tz = $master->getTimezone();
        $utcStart = $date->copy()->timezone('UTC')->startOfDay();
        $utcEnd = $date->copy()->timezone('UTC')->endOfDay();

        return Appointment::where('master_id', $master->id)
            ->whereIn('status', [AppointmentStatus::Booked])
            ->whereBetween('start_time', [$utcStart, $utcEnd])
            ->when($excludeAppointmentId, fn ($q) => $q->where('id', '!=', $excludeAppointmentId))
            ->with('service')
            ->get()
            ->map(fn (Appointment $a) => [
                'start' => $a->start_time->copy()->timezone($tz),
                'end' => $a->start_time->copy()->timezone($tz)->addMinutes(
                    $a->service ? $a->service->duration_minutes : 60
                ),
            ]);
    }

    private function getBlockedPeriods(User $master, Carbon $date): Collection
    {
        $tz = $master->getTimezone();
        $utcStart = $date->copy()->timezone('UTC')->startOfDay();
        $utcEnd = $date->copy()->timezone('UTC')->endOfDay();

        return BlockedTime::where('user_id', $master->id)
            ->where('start_datetime', '<=', $utcEnd)
            ->where('end_datetime', '>=', $utcStart)
            ->get()
            ->map(fn (BlockedTime $b) => [
                'start' => $b->start_datetime->copy()->timezone($tz),
                'end' => $b->end_datetime->copy()->timezone($tz),
            ]);
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
        $slot = $dayStart->copy();
        $now = Carbon::now($timezone);

        while ($slot->copy()->addMinutes($serviceDuration)->lte($dayEnd)) {
            $slotEnd = $slot->copy()->addMinutes($serviceDuration);

            $isPast = $date->isToday() && $slot->lt($now);

            $hasOverlap = $unavailablePeriods->contains(
                fn (array $period) => $slot->lt($period['end']) && $slotEnd->gt($period['start'])
            );

            if (! $isPast && ! $hasOverlap) {
                $slots[] = $slot->format('H:i');
            }

            $slot->addMinutes($interval);
        }

        return $slots;
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
}
