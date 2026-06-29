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
        $dayOfWeek = $date->dayOfWeek;

        $workingHour = WorkingHour::where('user_id', $master->id)
            ->where('day_of_week', $dayOfWeek)
            ->first();

        if (! $workingHour || ! $workingHour->is_working) {
            return [];
        }

        $dayStart = $date->copy()->setTimeFromTimeString($workingHour->start_time);
        $dayEnd = $date->copy()->setTimeFromTimeString($workingHour->end_time);

        $breakPeriods = $this->getBreakPeriods($workingHour, $date);
        $bookedPeriods = $this->getBookedPeriods($master, $date);
        $blockedPeriods = $this->getBlockedPeriods($master, $date);

        $allUnavailable = $breakPeriods->merge($bookedPeriods)->merge($blockedPeriods);

        $slotInterval = $master->slot_interval ?? 30;

        return $this->generateSlots(
            $dayStart,
            $dayEnd,
            $slotInterval,
            $serviceDuration,
            $date,
            $allUnavailable,
        );
    }

    public function isSlotAvailable(
        User $master,
        Carbon $startDateTime,
        int $durationMinutes,
        ?int $excludeAppointmentId = null,
    ): bool {
        $endDateTime = $startDateTime->copy()->addMinutes($durationMinutes);
        $dayOfWeek = $startDateTime->dayOfWeek;
        $workingHour = WorkingHour::where('user_id', $master->id)
            ->where('day_of_week', $dayOfWeek)->first();

        if (! $workingHour || ! $workingHour->is_working) return false;

        $dayStart = $startDateTime->copy()->setTimeFromTimeString($workingHour->start_time);
        $dayEnd = $startDateTime->copy()->setTimeFromTimeString($workingHour->end_time);

        if ($startDateTime->lt($dayStart) || $endDateTime->gt($dayEnd)) return false;

        $breakPeriods = $this->getBreakPeriods($workingHour, $startDateTime);
        $bookedPeriods = $this->getBookedPeriods($master, $startDateTime, $excludeAppointmentId);
        $blockedPeriods = $this->getBlockedPeriods($master, $startDateTime);

        $allUnavailable = $breakPeriods->merge($bookedPeriods)->merge($blockedPeriods);

        return ! $allUnavailable->contains(
            fn (array $period) => $startDateTime->lt($period['end']) && $endDateTime->gt($period['start'])
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
        return Appointment::where('master_id', $master->id)
            ->whereIn('status', [AppointmentStatus::PendingClient, AppointmentStatus::Confirmed])
            ->whereDate('start_time', $date)
            ->when($excludeAppointmentId, fn ($q) => $q->where('id', '!=', $excludeAppointmentId))
            ->with('service')
            ->get()
            ->map(fn (Appointment $a) => [
                'start' => $a->start_time,
                'end' => $a->start_time->copy()->addMinutes(
                    $a->service ? $a->service->duration_minutes : 60
                ),
            ]);
    }

    private function getBlockedPeriods(User $master, Carbon $date): Collection
    {
        return BlockedTime::where('user_id', $master->id)
            ->where('start_datetime', '<=', $date->copy()->endOfDay())
            ->where('end_datetime', '>=', $date->copy()->startOfDay())
            ->get()
            ->map(fn (BlockedTime $b) => [
                'start' => $b->start_datetime,
                'end' => $b->end_datetime,
            ]);
    }

    private function generateSlots(
        Carbon $dayStart,
        Carbon $dayEnd,
        int $interval,
        int $serviceDuration,
        Carbon $date,
        Collection $unavailablePeriods,
    ): array {
        $slots = [];
        $slot = $dayStart->copy();

        while ($slot->copy()->addMinutes($serviceDuration)->lte($dayEnd)) {
            $slotEnd = $slot->copy()->addMinutes($serviceDuration);

            $isPast = $date->isToday() && $slot->lt(Carbon::now());

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
        $dayOfWeek = $startDateTime->dayOfWeek;
        $workingHour = WorkingHour::where('user_id', $master->id)
            ->where('day_of_week', $dayOfWeek)
            ->first();

        if (! $workingHour || ! $workingHour->hasBreak()) {
            return null;
        }

        $endDateTime = $startDateTime->copy()->addMinutes($durationMinutes);
        $breakStart = $startDateTime->copy()->setTimeFromTimeString($workingHour->break_start_time);
        $breakEnd = $startDateTime->copy()->setTimeFromTimeString($workingHour->break_end_time);

        if ($startDateTime->lt($breakEnd) && $endDateTime->gt($breakStart)) {
            return [
                'break_start' => $workingHour->break_start_time,
                'break_end' => $workingHour->break_end_time,
            ];
        }

        return null;
    }
}
