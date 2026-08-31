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
 * Регрессия: operational query по start_time должен использовать
 * timezone-aware UTC boundaries, а не UTC-календарный день.
 */
class AnalyticsTimezoneOperationalTest extends TestCase
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
        $this->ws = Workspace::create(['name' => 'WS TZ', 'owner_id' => $this->master->id]);
        $this->master->update(['workspace_id' => $this->ws->id]);
    }

    public function test_early_morning_local_time_included_in_current_day(): void
    {
        // 01:00 Moscow = 22:00 UTC предыдущего дня.
        // Должна попасть в текущий локальный день мастера.
        $tz = 'Europe/Moscow';
        $localDate = Carbon::now($tz);
        $startLocal = $localDate->copy()->setTime(1, 0);  // 01:00 Moscow
        $startUtc = $startLocal->copy()->utc();            // 22:00 UTC prev day

        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Paid,
            'price' => 1000,
            'duration' => 60,
            'start_time' => $startUtc,
            'completed_at' => $startUtc->copy()->addHour(),
        ]);

        $response = $this->actingAs($this->master)
            ->get(route('admin.analytics', ['period' => 'day']))
            ->assertOk();

        $metrics = $response->viewData('page')['props']['metrics'];

        $this->assertSame(1, $metrics['operational_total_visits']);
    }

    public function test_next_day_local_time_excluded_from_current_day(): void
    {
        // 01:00 Moscow СЛЕДУЮЩЕГО дня = 22:00 UTC текущего дня.
        // НЕ должна попасть в текущий локальный день.
        $tz = 'Europe/Moscow';
        $localDate = Carbon::now($tz);
        $nextDayLocal = $localDate->copy()->addDay()->setTime(1, 0); // 01:00 Moscow next day
        $nextDayUtc = $nextDayLocal->copy()->utc();                  // 22:00 UTC current day

        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Paid,
            'price' => 2000,
            'duration' => 60,
            'start_time' => $nextDayUtc,
            'completed_at' => $nextDayUtc->copy()->addHour(),
        ]);

        $response = $this->actingAs($this->master)
            ->get(route('admin.analytics', ['period' => 'day']))
            ->assertOk();

        $metrics = $response->viewData('page')['props']['metrics'];

        $this->assertSame(0, $metrics['operational_total_visits']);
    }

    public function test_no_show_counted_in_local_day_not_utc_day(): void
    {
        // NoShow в 01:30 Moscow = 22:30 UTC предыдущего дня.
        // Должна попасть в operational набор текущего локального дня.
        $tz = 'Europe/Moscow';
        $localDate = Carbon::now($tz);
        $startLocal = $localDate->copy()->setTime(1, 30);
        $startUtc = $startLocal->copy()->utc();

        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::NoShow,
            'price' => 500,
            'duration' => 30,
            'start_time' => $startUtc,
        ]);

        $response = $this->actingAs($this->master)
            ->get(route('admin.analytics', ['period' => 'day']))
            ->assertOk();

        $metrics = $response->viewData('page')['props']['metrics'];

        $this->assertSame(1, $metrics['no_show_count']);
    }

    public function test_previous_period_uses_timezone_boundaries(): void
    {
        // Запись в 01:00 Moscow вчерашнего дня = 22:00 UTC позавчерашнего.
        // Должна попасть в previous period (вчера по Moscow).
        $tz = 'Europe/Moscow';
        $yesterdayLocal = Carbon::now($tz)->subDay()->setTime(1, 0);
        $yesterdayUtc = $yesterdayLocal->copy()->utc();

        Appointment::factory()->forMaster($this->master)->create([
            'status' => AppointmentStatus::Paid,
            'price' => 1500,
            'duration' => 60,
            'start_time' => $yesterdayUtc,
            'completed_at' => $yesterdayUtc->copy()->addHour(),
        ]);

        // Запрашиваем текущий день — previous = вчера.
        $response = $this->actingAs($this->master)
            ->get(route('admin.analytics', ['period' => 'day']))
            ->assertOk();

        $trends = $response->viewData('page')['props']['trends'];

        // Если previous period нашёл запись, avg_check trend != 100% (prev != 0).
        // Если не нашёл — prev = 0, current > 0 → trend = 100%.
        // Запись в 01:00 Moscow вчера должна быть найдена → prev != 0 → trend != 100%.
        $this->assertNotSame(100, $trends['avg_check']);
    }
}
