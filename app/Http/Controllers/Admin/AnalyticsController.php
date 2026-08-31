<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\Analytics\AnalyticsService;
use App\Services\Analytics\SourceAnalyticsService;
use App\Services\AutoFillMetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class AnalyticsController extends Controller
{
    public function __construct(
        private AnalyticsService $analyticsService,
        private SourceAnalyticsService $sourceAnalyticsService,
        private AutoFillMetricsService $autoFillMetricsService,
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

        $tz = $user->getTimezone();
        $dateStart = $dateFrom ?? $this->getPeriodStart($period, $tz)->toDateString();
        $dateEnd = $dateTo ?? $this->getPeriodEnd($period, $tz)->toDateString();

        // Границы периода в timezone мастера → UTC для сравнения с PostgreSQL timestamps.
        $periodStartUtc = Carbon::parse($dateStart, $tz)->startOfDay()->utc();
        $periodEndUtc = Carbon::parse($dateEnd, $tz)->endOfDay()->utc();

        // Операционный набор по start_time (utilization, отмены, неявки, посещаемость).
        $appointments = Appointment::whereIn('master_id', $masterIds)
            ->with(['masterService.catalog'])
            ->whereBetween('start_time', [$periodStartUtc, $periodEndUtc])
            ->get();

        // Финансовый набор по completed_at (выручка, завершённые, средний чек, график, услуги, NEW/RETURNING).
        $completedInPeriod = Appointment::whereIn('master_id', $masterIds)
            ->with(['masterService.catalog'])
            ->where('status', AppointmentStatus::Paid)
            ->whereBetween('completed_at', [$periodStartUtc, $periodEndUtc])
            ->get();

        // Операционные метрики (отмены/неявки/посещаемость/упущенная выгода) — оставляем на start_time.
        $metrics = $this->analyticsService->calculateMetrics($appointments);
        // Финансовые метрики — переводим на completed_at.
        $metrics = array_merge($metrics, $this->analyticsService->financialFromCompleted($completedInPeriod));

        $chartData = $this->buildChartData($completedInPeriod, $period, $dateFrom, $dateTo, $tz);
        $serviceStats = $this->buildServiceStats($completedInPeriod);
        $clientRetention = $this->buildClientRetention($masterIds, $completedInPeriod, $periodStartUtc);

        $utilization = $this->analyticsService->calculateUtilization($targetMasters, $appointments, $dateStart, $dateEnd);

        [$prevStart, $prevEnd] = $this->getPreviousPeriodDates($period, $dateFrom, $dateTo, $tz);
        $prevStartUtc = Carbon::parse($prevStart->toDateString(), $tz)->startOfDay()->utc();
        $prevEndUtc = Carbon::parse($prevEnd->toDateString(), $tz)->endOfDay()->utc();

        $prevAppointments = Appointment::whereIn('master_id', $masterIds)
            ->whereBetween('start_time', [$prevStartUtc, $prevEndUtc])
            ->get();
        $prevCompleted = Appointment::whereIn('master_id', $masterIds)
            ->where('status', AppointmentStatus::Paid)
            ->whereBetween('completed_at', [$prevStartUtc, $prevEndUtc])
            ->get();

        $prevMetrics = array_merge(
            $this->analyticsService->calculateMetrics($prevAppointments),
            $this->analyticsService->financialFromCompleted($prevCompleted),
        );
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

        // ─── Каналы записи (source analytics) ───
        // Приватный доступ гейтится тарифом ПРОФИ (feature capability).
        // START-пользователю реальные данные НЕ отправляются: только флаг locked.
        $activeTab = $request->query('tab', 'overview');
        $hasChannelFeature = $user->hasFeature('channel_analytics');

        $channelPayload = [
            'channels_feature' => $hasChannelFeature,
            'activeTab' => $activeTab,
        ];

        if ($hasChannelFeature) {
            $sources = $this->sourceAnalyticsService->buildForMaster($user, $periodStartUtc, $periodEndUtc);

            $channelPayload['top_channels'] = $this->sourceAnalyticsService->topByRevenue($sources, 5);
            // Полный список — только когда открыта вкладка каналов (экономим payload).
            $channelPayload['channels'] = $activeTab === 'channels' ? $sources : null;
            $channelPayload['tracking_links'] = $activeTab === 'channels'
                ? $this->buildTrackingLinks($user)
                : null;
        } else {
            $channelPayload['top_channels'] = null;
            $channelPayload['channels'] = null;
            $channelPayload['tracking_links'] = null;
        }

        // ─── AutoFill analytics ───
        $hasAutoFillFeature = $user->hasFeature('slot_autofill');
        $autoFillPayload = ['autofill_feature' => $hasAutoFillFeature, 'autofill' => null];

        if ($hasAutoFillFeature) {
            $autoFillMetrics = $this->autoFillMetricsService->getMetrics($user, $periodStartUtc, $periodEndUtc);

            $autoFillPayload['autofill'] = [
                'requests_created' => $autoFillMetrics['requests_created'],
                'offers_sent' => $autoFillMetrics['offers_sent'],
                'offers_accepted' => $autoFillMetrics['offers_accepted'],
                'acceptance_rate' => $autoFillMetrics['acceptance_rate'],
                'median_time_to_accept_seconds' => $autoFillMetrics['median_time_to_accept_seconds'],
            ];
        }

        return Inertia::render('admin/analytics', array_merge([
            'metrics' => $metrics,
            'trends' => $trends,
            'prev_metrics' => $prevMetricsAbsolute,
            'chartData' => $chartData,
            'activePeriod' => $period,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ], $channelPayload, $autoFillPayload));
    }

    /**
     * Список tracking-ссылок мастера для управления (только ПРОФИ).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildTrackingLinks($master): array
    {
        return $master->trackingLinks()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($link) => [
                'id' => $link->id,
                'name' => $link->name,
                'is_active' => $link->is_active,
                'url' => route('tracking-link.redirect', $link->token),
            ])
            ->all();
    }

    private function buildChartData(Collection $completed, string $period, ?string $dateFrom, ?string $dateTo, string $tz): array
    {
        $groupByFn = function ($app) use ($period, $dateFrom, $dateTo, $tz) {
            // Финансовый график — по completed_at в timezone мастера.
            $date = $app->completed_at?->copy()->timezone($tz) ?? $app->start_time->copy()->timezone($tz);

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
                'value' => (float) $group->sum(fn ($app) => $app->display_price),
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

        $anchor = $dateFrom ? Carbon::parse($dateFrom) : Carbon::now();

        return match ($period) {
            'day' => array_map(
                fn ($h) => sprintf('%02d:00', $h),
                range(0, 23)
            ),
            'week' => ['1', '2', '3', '4', '5', '6', '7'],
            'month' => collect()
                ->range(1, $anchor->daysInMonth)
                ->map(fn ($d) => $anchor->copy()->startOfMonth()->addDays($d - 1)->format('Y-m-d'))
                ->toArray(),
            'year' => collect()
                ->range(1, 12)
                ->map(fn ($m) => $anchor->copy()->startOfYear()->addMonths($m - 1)->format('Y-m'))
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

        $anchor = $dateFrom ? Carbon::parse($dateFrom) : Carbon::now();

        return match ($period) {
            'day' => array_map(
                fn ($h) => sprintf('%02d:00', $h),
                range(0, 23)
            ),
            'week' => ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'],
            'month' => collect()
                ->range(1, $anchor->daysInMonth)
                ->map(fn ($d) => (string) $d)
                ->toArray(),
            'year' => ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'],
            default => [],
        };
    }

    private function buildServiceStats(Collection $completed): array
    {
        if ($completed->isEmpty()) {
            return [];
        }

        $grouped = $completed->groupBy(fn ($app) => $app->display_name);
        $totalCount = $completed->count();

        $stats = $grouped->map(function ($apps, $serviceName) use ($totalCount) {
            $count = $apps->count();
            $revenue = (float) $apps->sum(fn ($app) => $app->display_price);

            return [
                'name' => $serviceName,
                'count' => $count,
                'revenue' => $revenue,
                'percent' => $totalCount > 0 ? round(($count / $totalCount) * 100) : 0,
            ];
        });

        return $stats->sortByDesc('count')->values()->take(10)->toArray();
    }

    private function buildClientRetention(array $masterIds, Collection $completed, Carbon $periodStartUtc): array
    {
        $currentClientIds = $completed->pluck('client_id')->filter()->unique()->values();

        if ($currentClientIds->isEmpty()) {
            return ['new_clients_count' => 0, 'returning_clients_count' => 0, 'first_visit_conversion' => null];
        }

        // Возвращающийся клиент — есть ранее завершённая услуга (по completed_at) до периода.
        $previousClientIds = Appointment::whereIn('master_id', $masterIds)
            ->where('status', AppointmentStatus::Paid)
            ->whereNotNull('completed_at')
            ->where('completed_at', '<', $periodStartUtc)
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

    private function getPeriodStart(string $period, string $tz = 'UTC')
    {
        $now = Carbon::now($tz);

        return match ($period) {
            'day' => $now->copy()->startOfDay(),
            'week' => $now->copy()->startOfWeek(),
            'month' => $now->copy()->startOfMonth(),
            'year' => $now->copy()->startOfYear(),
            default => $now->copy()->startOfMonth(),
        };
    }

    private function getPeriodEnd(string $period, string $tz = 'UTC')
    {
        $now = Carbon::now($tz);

        return match ($period) {
            'day' => $now->copy()->endOfDay(),
            'week' => $now->copy()->endOfWeek(),
            'month' => $now->copy()->endOfMonth(),
            'year' => $now->copy()->endOfYear(),
            default => $now->copy()->endOfMonth(),
        };
    }

    private function getPreviousPeriodDates(string $period, ?string $dateFrom, ?string $dateTo, string $tz = 'UTC'): array
    {
        if ($dateFrom && $dateTo) {
            $start = Carbon::parse($dateFrom);
            $end = Carbon::parse($dateTo);

            if ($period === 'month') {
                $prevMonthEnd = $start->copy()->startOfMonth()->subDay();

                return [
                    $prevMonthEnd->copy()->startOfMonth(),
                    $prevMonthEnd,
                ];
            }

            if ($period === 'year') {
                $prevYearEnd = $start->copy()->startOfYear()->subDay();

                return [
                    $prevYearEnd->copy()->startOfYear(),
                    $prevYearEnd,
                ];
            }

            $duration = $start->diffInDays($end);

            return [
                $start->copy()->subDays($duration + 1),
                $start->copy()->subDay(),
            ];
        }

        $now = Carbon::now($tz);

        return match ($period) {
            'day' => [$now->copy()->startOfDay()->subDay(), $now->copy()->startOfDay()->subSecond()],
            'week' => [$now->copy()->startOfWeek()->subWeek(), $now->copy()->startOfWeek()->subDay()],
            'month' => [$now->copy()->startOfMonth()->subMonth(), $now->copy()->startOfMonth()->subDay()],
            'year' => [$now->copy()->startOfYear()->subYear(), $now->copy()->startOfYear()->subDay()],
            default => [$now->copy()->startOfMonth()->subMonth(), $now->copy()->startOfMonth()->subDay()],
        };
    }
}
