<?php

namespace App\Services\Analytics;

use App\Enums\AppointmentStatus;
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
}
