<?php

namespace App\Services\Recurrence;

use App\Enums\RecurrenceType;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class RecurrenceService
{
    /**
     * Generate occurrence dates for a recurrence rule within a date range.
     * Returns array of Carbon dates (start of day in rule's timezone).
     *
     * @return Carbon[]
     */
    public function generateOccurrences(
        RecurrenceRule $rule,
        Carbon $rangeStart,
        Carbon $rangeEnd,
    ): array {
        $tz = $rule->timezone;
        $start = $rangeStart->copy()->timezone($tz)->startOfDay();
        $end = $rangeEnd->copy()->timezone($tz)->startOfDay();
        $ruleStart = $rule->startDate->copy()->timezone($tz)->startOfDay();

        if ($start->lt($ruleStart)) {
            $start = $ruleStart->copy();
        }

        if ($rule->endsAt && $end->gt($rule->endsAt)) {
            $end = $rule->endsAt->copy()->timezone($tz)->startOfDay();
        }

        if ($start->gt($end)) {
            return [];
        }

        return match ($rule->recurrenceType) {
            RecurrenceType::Daily => $this->generateDaily($rule, $start, $end),
            RecurrenceType::Weekly => $this->generateWeekly($rule, $start, $end),
            RecurrenceType::CustomWeekly => $this->generateCustomWeekly($rule, $start, $end),
        };
    }

    /**
     * @return Carbon[]
     */
    private function generateDaily(
        RecurrenceRule $rule,
        Carbon $start,
        Carbon $end,
    ): array {
        $occurrences = [];
        $interval = $rule->interval;
        $ruleStart = $rule->startDate->copy()->timezone($rule->timezone)->startOfDay();
        $current = $start->copy();

        while ($current->lte($end)) {
            $daysSinceStart = $ruleStart->diffInDays($current);
            if ($daysSinceStart % $interval === 0) {
                $occurrences[] = $current->copy();
            }
            $current->addDay();
        }

        return $occurrences;
    }

    /**
     * @return Carbon[]
     */
    private function generateWeekly(
        RecurrenceRule $rule,
        Carbon $start,
        Carbon $end,
    ): array {
        $occurrences = [];
        $ruleStart = $rule->startDate->copy()->timezone($rule->timezone)->startOfDay();
        $current = $start->copy();

        while ($current->lte($end)) {
            $weeksSinceStart = (int) floor($ruleStart->diffInDays($current) / 7);
            if ($weeksSinceStart % $rule->interval === 0
                && $current->dayOfWeek === $ruleStart->dayOfWeek
            ) {
                $occurrences[] = $current->copy();
            }
            $current->addDay();
        }

        return $occurrences;
    }

    /**
     * @return Carbon[]
     */
    private function generateCustomWeekly(
        RecurrenceRule $rule,
        Carbon $start,
        Carbon $end,
    ): array {
        $weekdays = $rule->weekdays;
        if (empty($weekdays)) {
            return [];
        }

        $occurrences = [];
        $ruleStart = $rule->startDate->copy()->timezone($rule->timezone)->startOfDay();
        $current = $start->copy();

        while ($current->lte($end)) {
            $weeksSinceStart = (int) floor($ruleStart->diffInDays($current) / 7);
            if ($weeksSinceStart % $rule->interval === 0
                && in_array($current->dayOfWeekIso, $weekdays, true)
            ) {
                $occurrences[] = $current->copy();
            }
            $current->addDay();
        }

        return $occurrences;
    }
}
