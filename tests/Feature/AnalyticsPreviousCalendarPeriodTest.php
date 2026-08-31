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
 * Регрессия: previous period для calendar month/year должен быть
 * полным предыдущим календарным периодом, а не generic diffInDays.
 */
class AnalyticsPreviousCalendarPeriodTest extends TestCase
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
        $this->ws = Workspace::create(['name' => 'WS Prev', 'owner_id' => $this->master->id]);
        $this->master->update(['workspace_id' => $this->ws->id]);
    }

    public function test_july_previous_is_full_june(): void
    {
        // Paid в июне (completed_at в июне).
        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Paid,
            'price' => 5000,
            'duration' => 60,
            'start_time' => Carbon::parse('2025-06-15 10:00:00'),
            'completed_at' => Carbon::parse('2025-06-15 11:00:00'),
        ]);

        // Запрашиваем июль 2025 — previous = июнь 2025 (1–30).
        $response = $this->actingAs($this->master)
            ->get(route('admin.analytics', [
                'period' => 'month',
                'date_from' => '2025-07-01',
                'date_to' => '2025-07-31',
            ]))
            ->assertOk();

        $trends = $response->viewData('page')['props']['trends'];

        // Previous (июнь) нашёл запись → avg_check trend != 100% (prev != 0).
        $this->assertNotSame(100, $trends['avg_check']);
    }

    public function test_june_previous_is_full_may(): void
    {
        // Paid 31 мая — должен попасть в previous (май 1–31).
        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Paid,
            'price' => 3000,
            'duration' => 60,
            'start_time' => Carbon::parse('2025-05-31 10:00:00'),
            'completed_at' => Carbon::parse('2025-05-31 11:00:00'),
        ]);

        $response = $this->actingAs($this->master)
            ->get(route('admin.analytics', [
                'period' => 'month',
                'date_from' => '2025-06-01',
                'date_to' => '2025-06-30',
            ]))
            ->assertOk();

        $trends = $response->viewData('page')['props']['trends'];

        // Previous (май) нашёл запись 31 мая → trend != 100%.
        $this->assertNotSame(100, $trends['avg_check']);
    }

    public function test_full_year_previous_is_full_previous_year(): void
    {
        // Paid 31 декабря 2023 — должен попасть в previous (2023-01-01–2023-12-31).
        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Paid,
            'price' => 7000,
            'duration' => 60,
            'start_time' => Carbon::parse('2023-12-31 10:00:00'),
            'completed_at' => Carbon::parse('2023-12-31 11:00:00'),
        ]);

        $response = $this->actingAs($this->master)
            ->get(route('admin.analytics', [
                'period' => 'year',
                'date_from' => '2024-01-01',
                'date_to' => '2024-12-31',
            ]))
            ->assertOk();

        $trends = $response->viewData('page')['props']['trends'];

        // Previous (2023) нашёл запись → trend != 100%.
        $this->assertNotSame(100, $trends['avg_check']);
    }

    public function test_non_leap_year_previous_december_31(): void
    {
        // 2025 не високосный. Paid 31 декабря 2024.
        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Paid,
            'price' => 4000,
            'duration' => 60,
            'start_time' => Carbon::parse('2024-12-31 10:00:00'),
            'completed_at' => Carbon::parse('2024-12-31 11:00:00'),
        ]);

        $response = $this->actingAs($this->master)
            ->get(route('admin.analytics', [
                'period' => 'year',
                'date_from' => '2025-01-01',
                'date_to' => '2025-12-31',
            ]))
            ->assertOk();

        $trends = $response->viewData('page')['props']['trends'];

        // Previous (2024) нашёл запись → trend != 100%.
        $this->assertNotSame(100, $trends['avg_check']);
    }
}
