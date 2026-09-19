<?php

namespace App\Services\Recurrence;

use App\Enums\RecurrenceType;
use Carbon\Carbon;

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
     * Weekly recurrence: every N weeks, on the given weekdays.
     * The first week is anchored to startDate — only dates >= startDate are returned.
     *
     * @return Carbon[]
     */
    private function generateWeekly(
        RecurrenceRule $rule,
        Carbon $start,
        Carbon $end,
    ): array {
        $weekdays = $rule->weekdays;

        // Fallback: if weekdays is empty (legacy data), derive from startDate
        if (empty($weekdays)) {
            $weekdays = [$rule->startDate->dayOfWeekIso];
        }

        $weekdays = array_map('intval', $weekdays);
        $interval = $rule->interval;
        $ruleStart = $rule->startDate->copy()->timezone($rule->timezone)->startOfDay();

        // The Monday of the week containing startDate is our anchor
        $anchorMonday = $ruleStart->copy()->startOfWeek();
        $current = $start->copy();
        $occurrences = [];

        while ($current->lte($end)) {
            $currentMonday = $current->copy()->startOfWeek();
            $weeksSinceAnchor = (int) $anchorMonday->diffInWeeks($currentMonday);

            if ($weeksSinceAnchor % $interval === 0
                && $current->gte($ruleStart)
                && in_array($current->dayOfWeekIso, $weekdays, true)
            ) {
                $occurrences[] = $current->copy();
            }

            $current->addDay();
        }

        return $occurrences;
    }
}
