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
 * Регрессия: backend при отсутствии date_from/date_to должен использовать
 * полный календарный диапазон периода (как показывает frontend),
 * а не start..today.
 */
class AnalyticsDefaultDateRangeTest extends TestCase
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
        $this->ws = Workspace::create(['name' => 'WS Range', 'owner_id' => $this->master->id]);
        $this->master->update(['workspace_id' => $this->ws->id]);
    }

    /**
     * Создаёт Paid-appointment с заданным смещением от «сейчас» (в днях).
     * Время — 12:00 UTC (15:00 Moscow), безопасно внутри любого дня.
     */
    private function createPaidAppointment(int $dayOffset = 0, int $price = 1000): Appointment
    {
        $start = Carbon::now()->addDays($dayOffset)->setTime(12, 0);

        return Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Paid,
            'price' => $price,
            'duration' => 60,
            'start_time' => $start,
            'completed_at' => $start->copy()->addHour(),
        ]);
    }

    // ── week: полный диапазон startOfWeek → endOfWeek ──────

    public function test_week_initial_load_uses_full_week_range(): void
    {
        // Запись в конце текущей недели (воскресенье).
        $endOfWeek = Carbon::now()->endOfWeek()->setTime(12, 0);
        $daysFromNow = (int) Carbon::now()->diffInDays($endOfWeek);
        $this->createPaidAppointment($daysFromNow, 1500);

        $response = $this->actingAs($this->master)
            ->get(route('admin.analytics', ['period' => 'week']))
            ->assertOk();

        $metrics = $response->viewData('page')['props']['metrics'];

        $this->assertGreaterThan(0, $metrics['revenue']);
    }

    // ── month: полный диапазон startOfMonth → endOfMonth ───

    public function test_month_initial_load_uses_full_month_range(): void
    {
        // Запись в последний день месяца.
        $endOfMonth = Carbon::now()->endOfMonth()->setTime(12, 0);
        $daysFromNow = (int) Carbon::now()->diffInDays($endOfMonth);
        $this->createPaidAppointment($daysFromNow, 2500);

        $response = $this->actingAs($this->master)
            ->get(route('admin.analytics', ['period' => 'month']))
            ->assertOk();

        $metrics = $response->viewData('page')['props']['metrics'];

        $this->assertGreaterThan(0, $metrics['revenue']);
    }

    // ── year: полный диапазон startOfYear → endOfYear ──────

    public function test_year_initial_load_uses_full_year_range(): void
    {
        // Запись в конце года.
        $endOfYear = Carbon::now()->endOfYear()->setTime(12, 0);
        $daysFromNow = (int) Carbon::now()->diffInDays($endOfYear);
        $this->createPaidAppointment($daysFromNow, 3000);

        $response = $this->actingAs($this->master)
            ->get(route('admin.analytics', ['period' => 'year']))
            ->assertOk();

        $metrics = $response->viewData('page')['props']['metrics'];

        $this->assertGreaterThan(0, $metrics['revenue']);
    }

    // ── explicit date_from/date_to имеют приоритет ──────────

    public function test_explicit_date_params_override_default_range(): void
    {
        // Запись сегодня.
        $this->createPaidAppointment(0, 1000);

        // Запрашиваем узкий диапазон вчера..вчера — сегодняшняя запись не должна попасть.
        $yesterday = Carbon::now()->subDay()->toDateString();

        $response = $this->actingAs($this->master)
            ->get(route('admin.analytics', [
                'period' => 'week',
                'date_from' => $yesterday,
                'date_to' => $yesterday,
            ]))
            ->assertOk();

        $metrics = $response->viewData('page')['props']['metrics'];

        $this->assertSame(0.0, (float) $metrics['revenue']);
    }

    // ── day: startOfDay → endOfDay ─────────────────────────

    public function test_day_initial_load_uses_full_day_range(): void
    {
        // Запись сегодня днём (UTC).
        $this->createPaidAppointment(0, 800);

        $response = $this->actingAs($this->master)
            ->get(route('admin.analytics', ['period' => 'day']))
            ->assertOk();

        $metrics = $response->viewData('page')['props']['metrics'];

        $this->assertGreaterThan(0, $metrics['revenue']);
    }
}
