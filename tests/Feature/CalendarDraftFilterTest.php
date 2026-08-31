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
 * Регрессия: Calendar должен скрывать только widget drafts (client=NULL, source=NULL),
 * но показывать confirmed записи без клиента (client=NULL, source!=NULL).
 */
class CalendarDraftFilterTest extends TestCase
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
        $this->ws = Workspace::create(['name' => 'WS Cal', 'owner_id' => $this->master->id]);
        $this->master->update(['workspace_id' => $this->ws->id]);
    }

    public function test_widget_draft_hidden_from_calendar(): void
    {
        // client=NULL, source=NULL → widget draft, скрыт.
        Appointment::factory()->forMaster($this->master)->create([
            'client_id' => null,
            'source' => null,
            'status' => AppointmentStatus::Booked,
            'start_time' => Carbon::now()->setTime(10, 0),
        ]);

        $response = $this->actingAs($this->master)
            ->get('/admin/calendar');

        $response->assertOk();
        $appointments = $response->viewData('page')['props']['appointments'];
        $this->assertCount(0, $appointments);
    }

    public function test_confirmed_telegram_without_client_visible(): void
    {
        // client=NULL, source=telegram → confirmed, видим.
        Appointment::factory()->forMaster($this->master)->create([
            'client_id' => null,
            'source' => AppointmentSource::Telegram,
            'status' => AppointmentStatus::Paid,
            'start_time' => Carbon::now()->setTime(10, 0),
        ]);

        $response = $this->actingAs($this->master)
            ->get('/admin/calendar');

        $response->assertOk();
        $appointments = $response->viewData('page')['props']['appointments'];
        $this->assertCount(1, $appointments);
    }

    public function test_legacy_record_with_client_visible(): void
    {
        // client!=NULL, source=NULL → legacy запись, видим.
        $client = Client::factory()->create();

        Appointment::factory()->forMaster($this->master)->forClient($client)->create([
            'source' => null,
            'status' => AppointmentStatus::Paid,
            'start_time' => Carbon::now()->setTime(10, 0),
        ]);

        $response = $this->actingAs($this->master)
            ->get('/admin/calendar');

        $response->assertOk();
        $appointments = $response->viewData('page')['props']['appointments'];
        $this->assertCount(1, $appointments);
    }

    public function test_api_range_same_draft_filter(): void
    {
        // Widget draft → скрыт из API.
        Appointment::factory()->forMaster($this->master)->create([
            'client_id' => null,
            'source' => null,
            'status' => AppointmentStatus::Booked,
            'start_time' => Carbon::now()->setTime(10, 0),
        ]);

        // Telegram без клиента → видим в API.
        Appointment::factory()->forMaster($this->master)->create([
            'client_id' => null,
            'source' => AppointmentSource::Telegram,
            'status' => AppointmentStatus::Paid,
            'start_time' => Carbon::now()->setTime(14, 0),
        ]);

        $response = $this->actingAs($this->master)
            ->getJson(route('admin.calendar.data', [
                'start' => Carbon::now()->toDateString(),
                'end' => Carbon::now()->toDateString(),
            ]));

        $response->assertOk();
        $appointments = $response->json('appointments');
        $this->assertCount(1, $appointments);
        $this->assertSame('paid', $appointments[0]['status']);
    }
}
