<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Регрессия на pipeline chartData (buildChartData/getChartKeys/getChartLabels).
 * Проверяем shape { label, value, count, percent }, корректные week-бакеты,
 * revenue/count по известным Paid-записям, реакцию на смену period и empty-state.
 */
class AnalyticsChartDataTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();

        $this->master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);
        $this->ws = Workspace::create(['name' => 'WS Chart', 'owner_id' => $this->master->id]);
        $this->master->update(['workspace_id' => $this->ws->id]);
    }

    /** @return array<int, array{label:string,value:float,count:int,percent:float|int}> */
    private function chartData(string $period): array
    {
        $response = $this->actingAs($this->master)
            ->get(route('admin.analytics', ['period' => $period]))
            ->assertOk();

        return $response->viewData('page')['props']['chartData'];
    }

    // ── Shape + week buckets ──────────────────────────────

    public function test_week_chartdata_has_seven_buckets_with_correct_shape_and_labels(): void
    {
        $chart = $this->chartData('week');

        $this->assertCount(7, $chart);
        $this->assertSame(['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'], array_column($chart, 'label'));

        foreach ($chart as $bucket) {
            $this->assertArrayHasKey('label', $bucket);
            $this->assertArrayHasKey('value', $bucket);
            $this->assertArrayHasKey('count', $bucket);
            $this->assertArrayHasKey('percent', $bucket);
        }
    }

    // ── Revenue / count from known Paid appointments ──────

    public function test_week_chartdata_aggregates_revenue_and_count_for_paid(): void
    {
        // Две оплаченные записи, завершённые сегодня (в таймзоне мастера).
        $completedAt = Carbon::now('Europe/Moscow')->setTime(12, 0);
        $isoWeekday = (int) $completedAt->isoWeekday(); // 1..7
        $expectedLabel = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'][$isoWeekday - 1];

        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Paid,
            'price' => 2000,
            'duration' => 60,
            'start_time' => Carbon::now('Europe/Moscow')->setTime(9, 0),
            'completed_at' => $completedAt,
        ]);
        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Paid,
            'price' => 3000,
            'duration' => 60,
            'start_time' => Carbon::now('Europe/Moscow')->setTime(11, 0),
            'completed_at' => $completedAt->copy()->setTime(13, 0),
        ]);

        $chart = $this->chartData('week');

        // Суммарная выручка и количество по всем бакетам.
        $this->assertSame(5000.0, (float) array_sum(array_column($chart, 'value')));
        $this->assertSame(2, (int) array_sum(array_column($chart, 'count')));

        // Обе записи попали в бакет сегодняшнего дня недели.
        $todayBucket = collect($chart)->firstWhere('label', $expectedLabel);
        $this->assertNotNull($todayBucket);
        $this->assertSame(5000.0, (float) $todayBucket['value']);
        $this->assertSame(2, (int) $todayBucket['count']);
        // Активный (максимальный) бакет получает percent = 100.
        $this->assertSame(100.0, (float) $todayBucket['percent']);
    }

    // ── Period param changes buckets ──────────────────────

    public function test_period_param_changes_chartdata_buckets(): void
    {
        // year → 12 месячных бакетов Янв..Дек
        $year = $this->chartData('year');
        $this->assertCount(12, $year);
        $this->assertSame(
            ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'],
            array_column($year, 'label'),
        );

        // day → почасовые бакеты 00:00..23:00 (24 штуки)
        $day = $this->chartData('day');
        $this->assertCount(24, $day);
        $this->assertSame('00:00', $day[0]['label']);
        $this->assertSame('23:00', $day[23]['label']);
    }

    // ── Empty state ───────────────────────────────────────

    public function test_empty_chartdata_returns_zeroed_buckets(): void
    {
        $chart = $this->chartData('week');

        $this->assertCount(7, $chart);
        $this->assertSame(0.0, (float) array_sum(array_column($chart, 'value')));
        $this->assertSame(0, (int) array_sum(array_column($chart, 'count')));
        foreach ($chart as $bucket) {
            $this->assertSame(0.0, (float) $bucket['percent']);
        }
    }

    // ── Day chart covers full 24h ─────────────────────────

    public function test_day_chartdata_covers_all_24_hours(): void
    {
        $tz = 'Europe/Moscow';
        // UTC-время: 06:00 Moscow = 03:00 UTC, 22:00 Moscow = 19:00 UTC.
        $today = Carbon::now();

        // Раннее утро (06:00 Moscow) — вне старого диапазона 08–20.
        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Paid,
            'price' => 1000,
            'duration' => 60,
            'start_time' => $today->copy()->setTime(2, 0),
            'completed_at' => $today->copy()->setTime(3, 0),
        ]);

        // Поздний вечер (22:00 Moscow) — вне старого диапазона 08–20.
        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Paid,
            'price' => 2000,
            'duration' => 60,
            'start_time' => $today->copy()->setTime(18, 0),
            'completed_at' => $today->copy()->setTime(19, 0),
        ]);

        $response = $this->actingAs($this->master)
            ->get(route('admin.analytics', ['period' => 'day']))
            ->assertOk();

        $page = $response->viewData('page')['props'];
        $chart = $page['chartData'];
        $metrics = $page['metrics'];

        // 24 hourly buckets.
        $this->assertCount(24, $chart);

        // Обе записи попали в правильные buckets.
        $bucket06 = collect($chart)->firstWhere('label', '06:00');
        $bucket22 = collect($chart)->firstWhere('label', '22:00');
        $this->assertNotNull($bucket06);
        $this->assertNotNull($bucket22);
        $this->assertSame(1000.0, (float) $bucket06['value']);
        $this->assertSame(2000.0, (float) $bucket22['value']);

        // Сумма chartData совпадает с metrics.
        $this->assertSame((float) $metrics['revenue'], (float) array_sum(array_column($chart, 'value')));
        $this->assertSame((int) $metrics['total_visits'], (int) array_sum(array_column($chart, 'count')));
    }
}
