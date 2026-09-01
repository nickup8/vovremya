<?php

namespace Tests\Feature;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Регрессия: Calendar range должен интерпретировать YYYY-MM-DD
 * в timezone мастера, а не UTC.
 */
class CalendarTimezoneRangeTest extends TestCase
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
        $this->ws = Workspace::create(['name' => 'WS Tz', 'owner_id' => $this->master->id]);
        $this->master->update(['workspace_id' => $this->ws->id]);
    }

    public function test_early_morning_msk_included_in_local_day(): void
    {
        // 2026-07-27 01:00 MSK = 2026-07-26 22:00 UTC
        // Calendar за 2026-07-27 должен включать эту запись.
        $client = Client::factory()->create();

        Appointment::factory()->forMaster($this->master)->forClient($client)->create([
            'source' => AppointmentSource::Admin,
            'status' => AppointmentStatus::Paid,
            'start_time' => Carbon::parse('2026-07-27 01:00:00', 'Europe/Moscow')->utc(),
        ]);

        $response = $this->actingAs($this->master)
            ->get('/admin/calendar?start=2026-07-27&end=2026-07-27');

        $response->assertOk();
        $appointments = $response->viewData('page')['props']['appointments'];
        $this->assertCount(1, $appointments);
    }

    public function test_next_day_early_morning_msk_excluded_from_previous_day(): void
    {
        // 2026-07-28 01:00 MSK = 2026-07-27 22:00 UTC
        // Calendar за 2026-07-27 НЕ должен включать эту запись.
        $client = Client::factory()->create();

        Appointment::factory()->forMaster($this->master)->forClient($client)->create([
            'source' => AppointmentSource::Admin,
            'status' => AppointmentStatus::Paid,
            'start_time' => Carbon::parse('2026-07-28 01:00:00', 'Europe/Moscow')->utc(),
        ]);

        $response = $this->actingAs($this->master)
            ->get('/admin/calendar?start=2026-07-27&end=2026-07-27');

        $response->assertOk();
        $appointments = $response->viewData('page')['props']['appointments'];
        $this->assertCount(0, $appointments);
    }

    public function test_api_range_early_morning_msk_included_in_local_day(): void
    {
        // 2026-07-27 01:00 MSK = 2026-07-26 22:00 UTC
        $client = Client::factory()->create();

        Appointment::factory()->forMaster($this->master)->forClient($client)->create([
            'source' => AppointmentSource::Admin,
            'status' => AppointmentStatus::Paid,
            'start_time' => Carbon::parse('2026-07-27 01:00:00', 'Europe/Moscow')->utc(),
        ]);

        $response = $this->actingAs($this->master)
            ->getJson(route('admin.calendar.data', [
                'start' => '2026-07-27',
                'end' => '2026-07-27',
            ]));

        $response->assertOk();
        $appointments = $response->json('appointments');
        $this->assertCount(1, $appointments);
    }

    public function test_api_range_next_day_excluded_from_previous_day(): void
    {
        // 2026-07-28 01:00 MSK = 2026-07-27 22:00 UTC
        $client = Client::factory()->create();

        Appointment::factory()->forMaster($this->master)->forClient($client)->create([
            'source' => AppointmentSource::Admin,
            'status' => AppointmentStatus::Paid,
            'start_time' => Carbon::parse('2026-07-28 01:00:00', 'Europe/Moscow')->utc(),
        ]);

        $response = $this->actingAs($this->master)
            ->getJson(route('admin.calendar.data', [
                'start' => '2026-07-27',
                'end' => '2026-07-27',
            ]));

        $response->assertOk();
        $appointments = $response->json('appointments');
        $this->assertCount(0, $appointments);
    }

    public function test_draft_filter_still_works_with_timezone_fix(): void
    {
        // Widget draft (client=NULL, source=NULL) должен быть скрыт.
        Appointment::factory()->forMaster($this->master)->create([
            'client_id' => null,
            'source' => null,
            'status' => AppointmentStatus::Booked,
            'start_time' => Carbon::parse('2026-07-27 10:00:00', 'Europe/Moscow')->utc(),
        ]);

        // Telegram без клиента → видим.
        Appointment::factory()->forMaster($this->master)->create([
            'client_id' => null,
            'source' => AppointmentSource::Telegram,
            'status' => AppointmentStatus::Paid,
            'start_time' => Carbon::parse('2026-07-27 14:00:00', 'Europe/Moscow')->utc(),
        ]);

        $response = $this->actingAs($this->master)
            ->getJson(route('admin.calendar.data', [
                'start' => '2026-07-27',
                'end' => '2026-07-27',
            ]));

        $response->assertOk();
        $appointments = $response->json('appointments');
        $this->assertCount(1, $appointments);
        $this->assertSame('paid', $appointments[0]['status']);
    }
}
