<?php

namespace App\Services\Analytics;

use App\Enums\AppointmentStatus;
use App\Models\BlockedTime;
use App\Models\WorkingHour;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AnalyticsService
{
    public function calculateMetrics(Collection $appointments): array
    {
        $completed = $this->getCompleted($appointments);

        $revenue = (float) $completed->sum(fn ($app) => $app->service ? $app->service->price : 0);
        $totalVisits = $completed->count();
        $avgCheck = $totalVisits > 0 ? round($revenue / $totalVisits, 2) : 0;

        $totalEnded = $appointments->filter(fn ($app) => in_array($app->status, [
            AppointmentStatus::Paid,
            AppointmentStatus::NoShow,
            AppointmentStatus::Cancelled,
        ]))->count();
        $attendanceRate = $totalEnded > 0 ? round(($totalVisits / $totalEnded) * 100) : 100;

        $cancelled = $appointments->filter(fn ($app) => $app->status === AppointmentStatus::Cancelled);
        $noShows = $appointments->filter(fn ($app) => $app->status === AppointmentStatus::NoShow);
        $lostRevenue = (float) $cancelled->sum(fn ($app) => $app->service ? $app->service->price : 0)
            + (float) $noShows->sum(fn ($app) => $app->service ? $app->service->price : 0);

        return [
            'revenue' => $revenue,
            'total_visits' => $totalVisits,
            'avg_check' => $avgCheck,
            'attendance_rate' => $attendanceRate,
            'lost_revenue' => $lostRevenue,
            'cancelled_count' => $cancelled->count(),
            'no_show_count' => $noShows->count(),
        ];
    }

    public function getCompleted(Collection $appointments): Collection
    {
        return $appointments->filter(fn ($app) => $app->status === AppointmentStatus::Paid);
    }

    public function calculateUtilization($master, Collection $appointments, string $startDate, string $endDate): int
    {
        $occupied = $appointments->filter(fn ($app) => in_array($app->status, [
            AppointmentStatus::Paid,
            AppointmentStatus::Booked,
        ]));
        $bookedMinutes = (int) $occupied->sum(fn ($app) => $app->service ? $app->service->duration_minutes : 0);

        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        $workingHours = WorkingHour::where('user_id', $master->id)->get()->keyBy('day_of_week');
        $hasAnySchedule = $workingHours->isNotEmpty();

        $blockedTimes = BlockedTime::where('user_id', $master->id)
            ->where('start_datetime', '<', $end->copy()->addDay())
            ->where('end_datetime', '>', $start)
            ->get();

        $availableMinutes = 0;
        $current = $start->copy();

        while ($current->lte($end)) {
            $dayMinutes = 0;
            $wh = $workingHours->get($current->dayOfWeek);

            if ($wh && $wh->is_working && $wh->start_time && $wh->end_time) {
                $dayMinutes = Carbon::parse($wh->start_time)->diffInMinutes(Carbon::parse($wh->end_time));

                if ($wh->hasBreak()) {
                    $dayMinutes -= Carbon::parse($wh->break_start_time)->diffInMinutes(Carbon::parse($wh->break_end_time));
                }

                $dayMinutes = max($dayMinutes, 0);

                $dayStart = $current->copy()->startOfDay();
                $dayEnd = $current->copy()->endOfDay();

                $overlappingBlocked = $blockedTimes->filter(fn ($bt) =>
                    $bt->start_datetime->lt($dayEnd) && $bt->end_datetime->gt($dayStart)
                );

                foreach ($overlappingBlocked as $bt) {
                    $blockStart = max($bt->start_datetime->timestamp, $dayStart->timestamp);
                    $blockEnd = min($bt->end_datetime->timestamp, $dayEnd->timestamp);
                    $blockedMinutes = (int) round(($blockEnd - $blockStart) / 60);
                    $dayMinutes -= $blockedMinutes;
                }

                $dayMinutes = max($dayMinutes, 0);
            } elseif (! $hasAnySchedule) {
                $dayMinutes = in_array($current->dayOfWeek, [1, 2, 3, 4, 5]) ? 480 : 0;
            }

            $availableMinutes += $dayMinutes;
            $current->addDay();
        }

        \Log::info('Utilization Debug:', [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'booked_minutes' => $bookedMinutes,
            'available_minutes' => $availableMinutes,
        ]);

        return $availableMinutes > 0 ? (int) round(($bookedMinutes / $availableMinutes) * 100) : 0;
    }

    public function calculateTrends(array $currentMetrics, array $prevMetrics, int $currentUtilization, int $prevUtilization): array
    {
        return [
            'revenue' => $this->trendPercent($currentMetrics['revenue'] ?? 0, $prevMetrics['revenue'] ?? 0),
            'avg_check' => $this->trendPercent($currentMetrics['avg_check'] ?? 0, $prevMetrics['avg_check'] ?? 0),
            'utilization' => $currentUtilization - $prevUtilization,
        ];
    }

    private function trendPercent(float $current, float $prev): int
    {
        if ($prev == 0 && $current == 0) {
            return 0;
        }

        if ($prev == 0) {
            return 100;
        }

        return (int) round((($current - $prev) / $prev) * 100);
    }
}
