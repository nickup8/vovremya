<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class AnalyticsController extends Controller
{
    public function index()
    {
        $master = auth()->user();

        if (! $master->is_master) {
            return redirect()->route('client.bookings')
                ->with('error', 'У вас нет профиля мастера.');
        }

        $appointments = $master->masterAppointments()
            ->with('service')
            ->get();

        $completed = $appointments->where('status', 'completed');

        $revenue = (float) $completed->sum(fn ($app) => $app->service ? $app->service->price : 0);
        $totalVisits = $completed->count();
        $avgCheck = $totalVisits > 0 ? round($revenue / $totalVisits, 2) : 0;

        $totalEnded = $appointments->whereIn('status', ['completed', 'no_show', 'cancelled'])->count();
        $attendanceRate = $totalEnded > 0 ? round(($totalVisits / $totalEnded) * 100) : 100;

        return Inertia::render('admin/analytics', [
            'metrics' => [
                'revenue' => $revenue,
                'total_visits' => $totalVisits,
                'avg_check' => $avgCheck,
                'attendance_rate' => $attendanceRate,
            ],
        ]);
    }
}
