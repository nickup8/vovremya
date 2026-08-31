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
 * Регрессия: chart keys для month/year должны строиться от dateFrom,
 * а не от Carbon::now().
 */
class AnalyticsHistoricalChartKeysTest extends TestCase
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
        $this->ws = Workspace::create(['name' => 'WS Hist', 'owner_id' => $this->master->id]);
        $this->master->update(['workspace_id' => $this->ws->id]);
    }

    private function chartData(string $period, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $params = ['period' => $period];
        if ($dateFrom) $params['date_from'] = $dateFrom;
        if ($dateTo) $params['date_to'] = $dateTo;

        $response = $this->actingAs($this->master)
            ->get(route('admin.analytics', $params))
            ->assertOk();

        return $response->viewData('page')['props']['chartData'];
    }

    public function test_previous_month_chart_has_correct_day_buckets(): void
    {
        // July 2025: 31 день. Paid 15 июля.
        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Paid,
            'price' => 4000,
            'duration' => 60,
            'start_time' => Carbon::parse('2025-07-15 10:00:00'),
            'completed_at' => Carbon::parse('2025-07-15 11:00:00'),
        ]);

        $chart = $this->chartData('month', '2025-07-01', '2025-07-31');

        // July = 31 день → 31 bucket.
        $this->assertCount(31, $chart);
        $this->assertSame('1', $chart[0]['label']);
        $this->assertSame('31', $chart[30]['label']);

        // Запись 15 июля попала в bucket 15.
        $this->assertSame(4000.0, (float) $chart[14]['value']);
        $this->assertSame(1, $chart[14]['count']);
    }

    public function test_june_chart_has_30_day_buckets(): void
    {
        // June 2025: 30 дней.
        $chart = $this->chartData('month', '2025-06-01', '2025-06-30');

        $this->assertCount(30, $chart);
        $this->assertSame('1', $chart[0]['label']);
        $this->assertSame('30', $chart[29]['label']);
    }

    public function test_previous_year_chart_has_correct_month_buckets(): void
    {
        // Paid в марте 2024.
        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Paid,
            'price' => 6000,
            'duration' => 60,
            'start_time' => Carbon::parse('2024-03-10 10:00:00'),
            'completed_at' => Carbon::parse('2024-03-10 11:00:00'),
        ]);

        $chart = $this->chartData('year', '2024-01-01', '2024-12-31');

        // 12 monthly buckets.
        $this->assertCount(12, $chart);
        $this->assertSame('Янв', $chart[0]['label']);
        $this->assertSame('Дек', $chart[11]['label']);

        // Запись марта попала в bucket Мар (index 2).
        $this->assertSame(6000.0, (float) $chart[2]['value']);
        $this->assertSame(1, $chart[2]['count']);
    }

    public function test_chart_sum_matches_revenue_for_historical_month(): void
    {
        // Две записи в июне 2025.
        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Paid,
            'price' => 2000,
            'duration' => 60,
            'start_time' => Carbon::parse('2025-06-05 10:00:00'),
            'completed_at' => Carbon::parse('2025-06-05 11:00:00'),
        ]);
        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Paid,
            'price' => 3000,
            'duration' => 60,
            'start_time' => Carbon::parse('2025-06-20 14:00:00'),
            'completed_at' => Carbon::parse('2025-06-20 15:00:00'),
        ]);

        $response = $this->actingAs($this->master)
            ->get(route('admin.analytics', [
                'period' => 'month',
                'date_from' => '2025-06-01',
                'date_to' => '2025-06-30',
            ]))
            ->assertOk();

        $props = $response->viewData('page')['props'];
        $chartSum = array_sum(array_column($props['chartData'], 'value'));

        $this->assertSame((float) $props['metrics']['revenue'], (float) $chartSum);
    }
}
