<?php

namespace Tests\Feature;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * N01: независимый источник сегодняшних записей для счётчика «Сегодня».
 * Счётчик не зависит от start/end открытого периода; границы дня — по таймзоне мастера;
 * границы доступа мастера/workspace — как у range().
 */
class CalendarTodaySummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function createMaster(string $tz = 'Asia/Yekaterinburg', string $role = 'master'): User
    {
        $master = User::factory()->master()->create([
            'settings' => ['timezone' => $tz, 'timezone_confirmed' => true],
            'role' => $role,
        ]);
        $ws = Workspace::create(['name' => 'WS '.$master->id, 'owner_id' => $master->id]);
        $master->update(['workspace_id' => $ws->id]);

        return $master;
    }

    public function test_non_master_gets_403(): void
    {
        $user = User::factory()->create([
            'is_master' => false,
            'role' => UserRole::Master,
        ]);

        $this->actingAs($user)
            ->getJson('/admin/calendar/today')
            ->assertStatus(403);
    }

    public function test_returns_today_in_master_timezone_with_absolute_bounds(): void
    {
        $master = $this->createMaster();
        $client = Client::factory()->create();

        // 2026-06-15 01:30 Asia/Yekaterinburg = 2026-06-14 20:30 UTC —
        // вчерашний день UTC, но «сегодня» в таймзоне мастера.
        $today = Appointment::factory()->forMaster($master)->forClient($client)->create([
            'source' => AppointmentSource::Admin,
            'status' => AppointmentStatus::Booked,
            'duration' => 60,
            'start_time' => Carbon::parse('2026-06-15 01:30:00', 'Asia/Yekaterinburg')->utc(),
        ]);

        $response = $this->actingAs($master)
            ->getJson('/admin/calendar/today');

        $response->assertOk()
            ->assertJsonPath('date', '2026-06-15')
            ->assertJsonPath('timezone', 'Asia/Yekaterinburg');

        $appointments = $response->json('appointments');
        $this->assertCount(1, $appointments);
        $this->assertSame($today->id, $appointments[0]['id']);
        $this->assertSame(
            Carbon::parse('2026-06-14 20:30:00', 'UTC')->toISOString(),
            $appointments[0]['start_at'],
        );
        $this->assertSame(
            Carbon::parse('2026-06-14 21:30:00', 'UTC')->toISOString(),
            $appointments[0]['end_at'],
        );
        $this->assertSame('booked', $appointments[0]['status']);
    }

    public function test_tomorrow_in_master_timezone_is_excluded_even_if_same_utc_day(): void
    {
        $master = $this->createMaster();
        $client = Client::factory()->create();

        // 2026-06-16 01:30 Yekaterinburg = 2026-06-15 20:30 UTC:
        // тот же календарный день UTC, что и «сейчас», но завтра у мастера.
        Appointment::factory()->forMaster($master)->forClient($client)->create([
            'source' => AppointmentSource::Admin,
            'start_time' => Carbon::parse('2026-06-16 01:30:00', 'Asia/Yekaterinburg')->utc(),
        ]);

        $this->actingAs($master)
            ->getJson('/admin/calendar/today')
            ->assertOk()
            ->assertJsonPath('appointments', []);
    }

    public function test_url_start_end_do_not_affect_result(): void
    {
        $master = $this->createMaster();
        $client = Client::factory()->create();

        $today = Appointment::factory()->forMaster($master)->forClient($client)->create([
            'source' => AppointmentSource::Admin,
            'start_time' => Carbon::parse('2026-06-15 10:00:00', 'Asia/Yekaterinburg')->utc(),
        ]);

        $response = $this->actingAs($master)
            ->getJson('/admin/calendar/today?start=2027-01-01&end=2027-01-31');

        $response->assertOk();
        $ids = collect($response->json('appointments'))->pluck('id');
        $this->assertTrue($ids->contains($today->id));
    }

    public function test_cancelled_and_drafts_are_excluded(): void
    {
        $master = $this->createMaster();
        $client = Client::factory()->create();

        $paid = Appointment::factory()->forMaster($master)->forClient($client)->create([
            'source' => AppointmentSource::Admin,
            'status' => AppointmentStatus::Paid,
            'start_time' => Carbon::parse('2026-06-15 10:00:00', 'Asia/Yekaterinburg')->utc(),
        ]);
        $noShow = Appointment::factory()->forMaster($master)->forClient($client)->create([
            'source' => AppointmentSource::Admin,
            'status' => AppointmentStatus::NoShow,
            'start_time' => Carbon::parse('2026-06-15 11:00:00', 'Asia/Yekaterinburg')->utc(),
        ]);
        $cancelled = Appointment::factory()->forMaster($master)->forClient($client)->create([
            'source' => AppointmentSource::Admin,
            'status' => AppointmentStatus::Cancelled,
            'start_time' => Carbon::parse('2026-06-15 12:00:00', 'Asia/Yekaterinburg')->utc(),
        ]);
        // Черновик: нет клиента и нет источника — в календаре не виден.
        $draft = Appointment::factory()->forMaster($master)->create([
            'start_time' => Carbon::parse('2026-06-15 13:00:00', 'Asia/Yekaterinburg')->utc(),
        ]);

        $response = $this->actingAs($master)
            ->getJson('/admin/calendar/today');

        $response->assertOk();
        $ids = collect($response->json('appointments'))->pluck('id');

        $this->assertTrue($ids->contains($paid->id), 'Paid должен входить в набор');
        $this->assertTrue($ids->contains($noShow->id), 'NoShow входит в набор (исключается только на уровне правил)');
        $this->assertFalse($ids->contains($cancelled->id), 'Cancelled не должен входить');
        $this->assertFalse($ids->contains($draft->id), 'Черновик не должен входить');
    }

    public function test_owner_sees_workspace_masters_but_not_foreign_workspace(): void
    {
        $owner = $this->createMaster('Asia/Yekaterinburg', 'owner');
        $workspaceMaster = User::factory()->master()->create([
            'workspace_id' => $owner->workspace_id,
            'role' => 'master',
            'settings' => ['timezone' => 'Asia/Yekaterinburg', 'timezone_confirmed' => true],
        ]);
        $foreignMaster = $this->createMaster();
        $client = Client::factory()->create();

        $wsAppt = Appointment::factory()->forMaster($workspaceMaster)->forClient($client)->create([
            'source' => AppointmentSource::Admin,
            'start_time' => Carbon::parse('2026-06-15 10:00:00', 'Asia/Yekaterinburg')->utc(),
        ]);
        $foreignAppt = Appointment::factory()->forMaster($foreignMaster)->forClient($client)->create([
            'source' => AppointmentSource::Admin,
            'start_time' => Carbon::parse('2026-06-15 11:00:00', 'Asia/Yekaterinburg')->utc(),
        ]);

        $ids = collect($this->actingAs($owner)
            ->getJson('/admin/calendar/today')
            ->assertOk()
            ->json('appointments'))->pluck('id');

        $this->assertTrue($ids->contains($wsAppt->id), 'Запись мастера workspace должна видеться');
        $this->assertFalse($ids->contains($foreignAppt->id), 'Чужой workspace не должен видеться');
    }

    public function test_plain_master_sees_only_own_appointments(): void
    {
        $master = $this->createMaster();
        $other = $this->createMaster();
        $client = Client::factory()->create();

        $own = Appointment::factory()->forMaster($master)->forClient($client)->create([
            'source' => AppointmentSource::Admin,
            'start_time' => Carbon::parse('2026-06-15 10:00:00', 'Asia/Yekaterinburg')->utc(),
        ]);
        Appointment::factory()->forMaster($other)->forClient($client)->create([
            'source' => AppointmentSource::Admin,
            'start_time' => Carbon::parse('2026-06-15 11:00:00', 'Asia/Yekaterinburg')->utc(),
        ]);

        $ids = collect($this->actingAs($master)
            ->getJson('/admin/calendar/today')
            ->assertOk()
            ->json('appointments'))->pluck('id');

        $this->assertTrue($ids->contains($own->id));
        $this->assertCount(1, $ids, 'Простой мастер видит только свои записи');
    }
}
