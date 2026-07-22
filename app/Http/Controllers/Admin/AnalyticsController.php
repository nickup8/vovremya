<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class AnalyticsController extends Controller
{
    public function __construct(
        private AnalyticsService $analyticsService,
    ) {}

    public function index(Request $request)
    {
        $user = auth()->user();

        if (! $user->role->canManageTeam() && ! $user->is_master) {
            return redirect()->route('client.bookings')
                ->with('error', 'У вас нет доступа к аналитике.');
        }

        if ($user->role->canManageTeam()) {
            $targetMasters = $user->workspace
                ? $user->workspace->users()->where('is_master', true)->get()
                : collect([$user]);
        } else {
            $targetMasters = collect([$user]);
        }
        $masterIds = $targetMasters->pluck('id')->toArray();

        $period = $request->query('period', 'week');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        if ($period === 'custom') {
            $dateFrom = $dateFrom ?? Carbon::now()->startOfMonth()->format('Y-m-d');
            $dateTo = $dateTo ?? Carbon::today()->format('Y-m-d');
        }

        $appointments = Appointment::whereIn('master_id', $masterIds)
            ->with('service')
            ->whereBetween('start_time', [
                ($dateFrom ?? $this->getPeriodStart($period)->toDateString()).' 00:00:00',
                ($dateTo ?? Carbon::now()->toDateString()).' 23:59:59',
            ])
            ->get();

        $metrics = $this->analyticsService->calculateMetrics($appointments);
        $chartData = $this->buildChartData($appointments, $period, $dateFrom, $dateTo);
        $serviceStats = $this->buildServiceStats($appointments);

        $periodStart = ($dateFrom ?? $this->getPeriodStart($period)->toDateString()).' 00:00:00';
        $clientRetention = $this->buildClientRetention($masterIds, $appointments, $periodStart);

        $dateStart = $dateFrom ?? $this->getPeriodStart($period)->toDateString();
        $dateEnd = $dateTo ?? Carbon::now()->toDateString();
        $utilization = $this->analyticsService->calculateUtilization($targetMasters, $appointments, $dateStart, $dateEnd);

        [$prevStart, $prevEnd] = $this->getPreviousPeriodDates($period, $dateFrom, $dateTo);
        $prevAppointments = Appointment::whereIn('master_id', $masterIds)
            ->with('service')
            ->whereBetween('start_time', [
                $prevStart->startOfDay()->toDateTimeString(),
                $prevEnd->endOfDay()->toDateTimeString(),
            ])
            ->get();

        $prevMetrics = $this->analyticsService->calculateMetrics($prevAppointments);
        $prevUtilization = $this->analyticsService->calculateUtilization(
            $targetMasters,
            $prevAppointments,
            $prevStart->toDateString(),
            $prevEnd->toDateString(),
        );

        $trends = $this->analyticsService->calculateTrends($metrics, $prevMetrics, $utilization, $prevUtilization);

        $topServices = array_map(fn ($s) => [
            'name' => $s['name'],
            'count' => $s['count'],
            'percentage' => $s['percent'],
        ], array_slice($serviceStats, 0, 5));

        $metrics = array_merge($metrics, $clientRetention, [
            'top_services' => $topServices,
            'utilization_percentage' => $utilization,
        ]);

        $prevMetricsAbsolute = [
            'revenue' => $prevMetrics['revenue'] ?? 0,
            'avg_check' => $prevMetrics['avg_check'] ?? 0,
            'utilization' => $prevUtilization,
        ];

        return Inertia::render('admin/analytics', [
            'metrics' => $metrics,
            'trends' => $trends,
            'prev_metrics' => $prevMetricsAbsolute,
            'chartData' => $chartData,
            'serviceStats' => $serviceStats,
            'activePeriod' => $period,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ]);
    }

    private function buildChartData(Collection $appointments, string $period, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $completed = $this->analyticsService->getCompleted($appointments);

        $groupByFn = function ($app) use ($period, $dateFrom, $dateTo) {
            $date = $app->start_time;

            if ($period === 'custom' && $dateFrom && $dateTo) {
                $diff = Carbon::parse($dateFrom)->diffInDays(Carbon::parse($dateTo));

                return match (true) {
                    $diff <= 1 => $date->format('H:00'),
                    $diff <= 31 => $date->format('Y-m-d'),
                    default => $date->format('Y-m'),
                };
            }

            return match ($period) {
                'day' => $date->format('H:00'),
                'week' => $date->format('N'),
                'month' => $date->format('Y-m-d'),
                'year' => $date->format('Y-m'),
                default => $date->format('Y-m-d'),
            };
        };

        $grouped = $completed->groupBy($groupByFn);

        $keys = $this->getChartKeys($period, $dateFrom, $dateTo);
        $labels = $this->getChartLabels($period, $dateFrom, $dateTo);

        $data = [];
        foreach ($keys as $i => $key) {
            $group = $grouped->get($key, collect());
            $data[] = [
                'label' => $labels[$i] ?? $key,
                'value' => (float) $group->sum(fn ($app) => $app->service ? $app->service->price : 0),
                'count' => $group->count(),
            ];
        }

        $maxValue = $data !== [] ? max(array_column($data, 'value')) : 0;

        return array_map(function ($item) use ($maxValue) {
            return [
                'label' => $item['label'],
                'value' => $item['value'],
                'count' => $item['count'],
                'percent' => $maxValue > 0 ? round(($item['value'] / $maxValue) * 100) : 0,
            ];
        }, $data);
    }

    private function getChartKeys(string $period, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        if ($period === 'custom' && $dateFrom && $dateTo) {
            $diff = Carbon::parse($dateFrom)->diffInDays(Carbon::parse($dateTo));

            return match (true) {
                $diff <= 1 => array_map(
                    fn ($h) => sprintf('%02d:00', $h),
                    range(0, 23)
                ),
                $diff <= 31 => collect()
                    ->range(0, $diff)
                    ->map(fn ($d) => Carbon::parse($dateFrom)->addDays($d)->format('Y-m-d'))
                    ->toArray(),
                default => collect()
                    ->range(0, (int) ceil($diff / 30))
                    ->map(fn ($m) => Carbon::parse($dateFrom)->addMonths($m)->format('Y-m'))
                    ->toArray(),
            };
        }

        $now = Carbon::now();

        return match ($period) {
            'day' => array_map(
                fn ($h) => sprintf('%02d:00', $h),
                range(8, 20)
            ),
            'week' => ['1', '2', '3', '4', '5', '6', '7'],
            'month' => collect()
                ->range(1, $now->daysInMonth)
                ->map(fn ($d) => $now->copy()->startOfMonth()->addDays($d - 1)->format('Y-m-d'))
                ->toArray(),
            'year' => collect()
                ->range(1, 12)
                ->map(fn ($m) => $now->copy()->startOfYear()->addMonths($m - 1)->format('Y-m'))
                ->toArray(),
            default => [],
        };
    }

    private function getChartLabels(string $period, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        if ($period === 'custom' && $dateFrom && $dateTo) {
            $diff = Carbon::parse($dateFrom)->diffInDays(Carbon::parse($dateTo));

            return match (true) {
                $diff <= 1 => array_map(
                    fn ($h) => sprintf('%02d:00', $h),
                    range(0, 23)
                ),
                $diff <= 31 => collect()
                    ->range(0, $diff)
                    ->map(fn ($d) => (string) Carbon::parse($dateFrom)->addDays($d)->day)
                    ->toArray(),
                default => collect()
                    ->range(0, (int) ceil($diff / 30))
                    ->map(fn ($m) => Carbon::parse($dateFrom)->addMonths($m)->format('M Y'))
                    ->toArray(),
            };
        }

        $now = Carbon::now();

        return match ($period) {
            'day' => array_map(
                fn ($h) => sprintf('%02d:00', $h),
                range(8, 20)
            ),
            'week' => ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'],
            'month' => collect()
                ->range(1, $now->daysInMonth)
                ->map(fn ($d) => (string) $d)
                ->toArray(),
            'year' => ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'],
            default => [],
        };
    }

    private function buildServiceStats(Collection $appointments): array
    {
        $completed = $this->analyticsService->getCompleted($appointments);

        if ($completed->isEmpty()) {
            return [];
        }

        $grouped = $completed->groupBy('service_id');
        $totalCount = $completed->count();

        $stats = $grouped->map(function ($apps, $serviceId) use ($totalCount) {
            $service = $apps->first()->service;
            $count = $apps->count();
            $revenue = (float) $apps->sum(fn ($app) => $app->service ? $app->service->price : 0);

            return [
                'name' => $service?->title ?? 'Услуга #'.$serviceId,
                'count' => $count,
                'revenue' => $revenue,
                'percent' => $totalCount > 0 ? round(($count / $totalCount) * 100) : 0,
            ];
        });

        return $stats->sortByDesc('count')->values()->take(10)->toArray();
    }

    private function buildClientRetention(array $masterIds, Collection $appointments, string $periodStart): array
    {
        $completed = $this->analyticsService->getCompleted($appointments);
        $currentClientIds = $completed->pluck('client_id')->filter()->unique()->values();

        if ($currentClientIds->isEmpty()) {
            return ['new_clients_count' => 0, 'returning_clients_count' => 0, 'first_visit_conversion' => null];
        }

        $previousClientIds = Appointment::whereIn('master_id', $masterIds)
            ->where('status', AppointmentStatus::Paid)
            ->where('start_time', '<', $periodStart)
            ->whereIn('client_id', $currentClientIds)
            ->distinct()
            ->pluck('client_id');

        $returningCount = $previousClientIds->count();
        $newCount = $currentClientIds->count() - $returningCount;

        return [
            'new_clients_count' => max($newCount, 0),
            'returning_clients_count' => $returningCount,
            'first_visit_conversion' => null,
        ];
    }

    private function getPeriodStart(string $period)
    {
        return match ($period) {
            'day' => now()->startOfDay(),
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            'year' => now()->startOfYear(),
            default => now()->startOfMonth(),
        };
    }

    private function getPreviousPeriodDates(string $period, ?string $dateFrom, ?string $dateTo): array
    {
        if ($dateFrom && $dateTo) {
            $start = Carbon::parse($dateFrom);
            $end = Carbon::parse($dateTo);
            $duration = $start->diffInDays($end);

            return [
                $start->copy()->subDays($duration + 1),
                $start->copy()->subDay(),
            ];
        }

        return match ($period) {
            'day' => [now()->startOfDay()->subDay(), now()->startOfDay()->subSecond()],
            'week' => [now()->startOfWeek()->subWeek(), now()->startOfWeek()->subDay()],
            'month' => [now()->startOfMonth()->subMonth(), now()->startOfMonth()->subDay()],
            'year' => [now()->startOfYear()->subYear(), now()->startOfYear()->subDay()],
            default => [now()->startOfMonth()->subMonth(), now()->startOfMonth()->subDay()],
        };
    }
}
