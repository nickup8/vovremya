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
 * Регрессия: operational_total_visits (по start_time) и total_visits (по completed_at)
 * могут расходиться. Проверяем, что attendance_rate и operational_total_visits используют
 * operational-набор, а total_visits остаётся финансовым.
 */
class AnalyticsOperationalVisitsTest extends TestCase
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
        $this->ws = Workspace::create(['name' => 'WS Op', 'owner_id' => $this->master->id]);
        $this->master->update(['workspace_id' => $this->ws->id]);
    }

    public function test_operational_total_visits_differs_from_financial_total_visits(): void
    {
        $now = Carbon::now();

        // A: start_time сегодня, completed_at сегодня → попадает в оба набора.
        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Paid,
            'price' => 2000,
            'duration' => 60,
            'start_time' => $now->copy()->setTime(10, 0),
            'completed_at' => $now->copy()->setTime(11, 0),
        ]);

        // B: start_time сегодня, completed_at ВЧЕРА → operational да, financial нет.
        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Paid,
            'price' => 3000,
            'duration' => 60,
            'start_time' => $now->copy()->setTime(14, 0),
            'completed_at' => $now->copy()->subDay()->setTime(15, 0),
        ]);

        // C: NoShow сегодня → влияет на attendance_rate и funnel.
        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::NoShow,
            'price' => 1000,
            'duration' => 60,
            'start_time' => $now->copy()->setTime(16, 0),
        ]);

        $response = $this->actingAs($this->master)
            ->get(route('admin.analytics', ['period' => 'day']))
            ->assertOk();

        $metrics = $response->viewData('page')['props']['metrics'];

        // operational_total_visits = 2 Paid по start_time (A + B).
        $this->assertSame(2, $metrics['operational_total_visits']);

        // total_visits = 1 Paid по completed_at (только A, т.к. B завершена вчера).
        $this->assertSame(1, $metrics['total_visits']);

        // attendance_rate = Paid / (Paid + NoShow + Cancelled) по operational = 2 / (2+1+0) = 67%.
        $this->assertEquals(67, $metrics['attendance_rate']);
    }

    public function test_no_show_affects_operational_funnel_but_not_financial(): void
    {
        $now = Carbon::now();

        // Paid сегодня (start_time и completed_at).
        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Paid,
            'price' => 1500,
            'duration' => 60,
            'start_time' => $now->copy()->setTime(9, 0),
            'completed_at' => $now->copy()->setTime(10, 0),
        ]);

        // Cancelled сегодня (start_time).
        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Cancelled,
            'price' => 800,
            'duration' => 30,
            'start_time' => $now->copy()->setTime(12, 0),
        ]);

        $response = $this->actingAs($this->master)
            ->get(route('admin.analytics', ['period' => 'day']))
            ->assertOk();

        $metrics = $response->viewData('page')['props']['metrics'];

        // operational_total_visits = 1 (Paid по start_time).
        $this->assertSame(1, $metrics['operational_total_visits']);

        // cancelled_count = 1.
        $this->assertSame(1, $metrics['cancelled_count']);

        // attendance_rate = 1 / (1 + 0 + 1) = 50%.
        $this->assertEquals(50, $metrics['attendance_rate']);
    }
}
