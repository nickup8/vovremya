<?php

namespace App\Services\Booking;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SlotService
{
    public function generateSlots(
        Carbon $dayStart,
        Carbon $dayEnd,
        int $intervalMinutes,
    ): array {
        $slots = [];
        $slot = $dayStart->copy();

        while ($slot->lte($dayEnd)) {
            $slots[] = $slot->format('H:i');
            $slot->addMinutes($intervalMinutes);
        }

        return $slots;
    }

    public function filterAvailableSlots(
        array $slots,
        Carbon $date,
        Collection $unavailablePeriods,
        int $durationMinutes,
    ): array {
        return array_filter($slots, function (string $time) use ($date, $unavailablePeriods, $durationMinutes) {
            $slotStart = $date->copy()->setTimeFromTimeString($time);
            $slotEnd = $slotStart->copy()->addMinutes($durationMinutes);

            $isPast = $date->isToday() && $slotStart->lt(Carbon::now());

            $hasOverlap = $unavailablePeriods->contains(
                fn (array $period) => $slotStart->lt($period['end']) && $slotEnd->gt($period['start'])
            );

            return ! $isPast && ! $hasOverlap;
        });
    }
}
