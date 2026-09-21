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
 * Regression: финансовые KPI (revenue, avg_check) должны относиться к дате визита (start_time),
 * а не к моменту оплаты (completed_at).
 */
class AnalyticsRevenuePeriodTest extends TestCase
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
        $this->ws = Workspace::create(['name' => 'WS Rev', 'owner_id' => $this->master->id]);
        $this->master->update(['workspace_id' => $this->ws->id]);
    }

    private function analytics(array $params): array
    {
        $response = $this->actingAs($this->master)
            ->get(route('admin.analytics', $params))
            ->assertOk();

        return $response->viewData('page')['props'];
    }

    // ── Scenario A: Paid inside period, marked Paid after period ──────

    public function test_paid_inside_period_marked_paid_after_period_counts_in_revenue(): void
    {
        // start_time = 16 Sep 2026 10:00 Moscow, completed_at = 21 Sep 2026.
        // Period: 14–20 Sep. Визит должен попасть в revenue этого периода.
        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Paid,
            'price' => 1500,
            'duration' => 60,
            'start_time' => Carbon::parse('2026-09-16 10:00:00', 'Europe/Moscow'),
            'completed_at' => Carbon::parse('2026-09-21 12:00:00'),
        ]);

        $props = $this->analytics([
            'period' => 'custom',
            'date_from' => '2026-09-14',
            'date_to' => '2026-09-20',
        ]);

        $m = $props['metrics'];

        $this->assertSame(1, $m['operational_total_visits'], 'operational: visit on 16 Sep counts');
        $this->assertSame(1, $m['total_visits'], 'financial: visit counts by start_time, not completed_at');
        $this->assertSame(1500.0, (float) $m['revenue']);
        $this->assertSame(1500.0, (float) $m['avg_check']);
    }

    // ── Scenario B: Two visits, different completed_at ────────────────

    public function test_two_paid_visits_revenue_sums_by_start_time(): void
    {
        // Обе start_time внутри периода 14–20 Sep.
        // completed_at у одной — 13 Sep (вне периода), у другой — 21 Sep (вне периода).
        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Paid,
            'price' => 1000,
            'duration' => 60,
            'start_time' => Carbon::parse('2026-09-15 10:00:00', 'Europe/Moscow'),
            'completed_at' => Carbon::parse('2026-09-13 12:00:00'),
        ]);
        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Paid,
            'price' => 2000,
            'duration' => 60,
            'start_time' => Carbon::parse('2026-09-18 14:00:00', 'Europe/Moscow'),
            'completed_at' => Carbon::parse('2026-09-21 15:00:00'),
        ]);

        $props = $this->analytics([
            'period' => 'custom',
            'date_from' => '2026-09-14',
            'date_to' => '2026-09-20',
        ]);

        $m = $props['metrics'];

        $this->assertSame(3000.0, (float) $m['revenue']);
        $this->assertSame(1500.0, (float) $m['avg_check']);
        $this->assertSame(2, $m['total_visits']);
    }

    // ── Scenario C: Paid marked inside period, visit outside ──────────

    public function test_paid_marked_inside_but_visit_outside_not_in_revenue(): void
    {
        // start_time = 21 Sep (вне периода), completed_at = 20 Sep (внутри).
        // НЕ должен попасть в revenue 14–20 Sep.
        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Paid,
            'price' => 1800,
            'duration' => 60,
            'start_time' => Carbon::parse('2026-09-21 10:00:00', 'Europe/Moscow'),
            'completed_at' => Carbon::parse('2026-09-20 12:00:00'),
        ]);

        $props = $this->analytics([
            'period' => 'custom',
            'date_from' => '2026-09-14',
            'date_to' => '2026-09-20',
        ]);

        $m = $props['metrics'];

        $this->assertSame(0.0, (float) $m['revenue']);
        $this->assertSame(0.0, (float) $m['avg_check']);
        $this->assertSame(0, $m['total_visits']);
    }

    // ── Scenario D: cancelled / no_show not in revenue ────────────────

    public function test_cancelled_and_no_show_not_in_revenue(): void
    {
        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Cancelled,
            'price' => 500,
            'duration' => 30,
            'start_time' => Carbon::parse('2026-09-15 10:00:00', 'Europe/Moscow'),
        ]);
        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::NoShow,
            'price' => 700,
            'duration' => 60,
            'start_time' => Carbon::parse('2026-09-17 14:00:00', 'Europe/Moscow'),
        ]);

        $props = $this->analytics([
            'period' => 'custom',
            'date_from' => '2026-09-14',
            'date_to' => '2026-09-20',
        ]);

        $m = $props['metrics'];

        $this->assertSame(0.0, (float) $m['revenue']);
        $this->assertSame(0.0, (float) $m['avg_check']);
        $this->assertSame(0, $m['total_visits']);
    }

    // ── Scenario E: Operational metrics unchanged ─────────────────────

    public function test_operational_metrics_unchanged_by_semantic_shift(): void
    {
        // Paid + start_time inside period, completed_at outside.
        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Paid,
            'price' => 2000,
            'duration' => 60,
            'start_time' => Carbon::parse('2026-09-16 10:00:00', 'Europe/Moscow'),
            'completed_at' => Carbon::parse('2026-09-21 12:00:00'),
        ]);

        // Cancelled inside period.
        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Cancelled,
            'price' => 800,
            'duration' => 30,
            'start_time' => Carbon::parse('2026-09-17 12:00:00', 'Europe/Moscow'),
        ]);

        // NoShow inside period.
        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::NoShow,
            'price' => 1000,
            'duration' => 60,
            'start_time' => Carbon::parse('2026-09-18 15:00:00', 'Europe/Moscow'),
        ]);

        $props = $this->analytics([
            'period' => 'custom',
            'date_from' => '2026-09-14',
            'date_to' => '2026-09-20',
        ]);

        $m = $props['metrics'];

        // Operational: Paid=1, Cancelled=1, NoShow=1 → attendance = 1/(1+1+1) = 33%.
        $this->assertSame(1, $m['operational_total_visits']);
        $this->assertSame(1, $m['cancelled_count']);
        $this->assertSame(1, $m['no_show_count']);
        $this->assertEquals(33, $m['attendance_rate']);
    }

    // ── Chart consistency: chart sum matches metrics revenue ──────────

    public function test_chart_revenue_matches_metrics_revenue_semantics(): void
    {
        // start_time inside period, completed_at outside.
        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Paid,
            'price' => 2500,
            'duration' => 60,
            'start_time' => Carbon::parse('2026-09-16 10:00:00', 'Europe/Moscow'),
            'completed_at' => Carbon::parse('2026-09-21 12:00:00'),
        ]);

        $props = $this->analytics([
            'period' => 'custom',
            'date_from' => '2026-09-14',
            'date_to' => '2026-09-20',
        ]);

        $chartSum = (float) array_sum(array_column($props['chartData'], 'value'));
        $this->assertSame((float) $props['metrics']['revenue'], $chartSum);
    }
}
