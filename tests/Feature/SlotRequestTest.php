<?php

namespace Tests\Feature;

use App\Enums\SlotRequestDeliveryChannel;
use App\Enums\SlotRequestSource;
use App\Enums\SlotRequestStatus;
use App\Enums\SlotRequestType;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\SlotRequest;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SlotRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $master;
    private Workspace $ws;
    private Client $client;
    private MasterService $masterService;
    private Appointment $appointment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->master = User::factory()->master()->create();
        $this->ws = Workspace::create(['name' => 'WS Test', 'owner_id' => $this->master->id]);
        $this->master->update(['workspace_id' => $this->ws->id]);

        $this->client = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
        ]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $this->ws->id,
            'title' => 'Массаж',
            'base_price' => 2000,
            'base_duration' => 60,
        ]);

        $this->masterService = MasterService::create([
            'master_id' => $this->master->id,
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);

        $this->appointment = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($this->client)
            ->withMasterService($this->masterService)
            ->create([
                'status' => 'booked',
                'start_time' => Carbon::tomorrow()->setTime(14, 0),
                'duration' => 60,
            ]);
    }

    private function earlierRequest(array $overrides = []): array
    {
        return array_merge([
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'client_id' => $this->client->id,
            'appointment_id' => $this->appointment->id,
            'master_service_id' => $this->masterService->id,
            'type' => SlotRequestType::Earlier,
            'request_source' => SlotRequestSource::Web,
            'delivery_channel' => SlotRequestDeliveryChannel::Telegram,
            'date_from' => Carbon::tomorrow()->toDateString(),
            'date_to' => Carbon::tomorrow()->addDays(7)->toDateString(),
            'time_from' => '09:00',
            'time_to' => '18:00',
            'timezone' => 'Europe/Moscow',
            'appointment_start_time_snapshot' => $this->appointment->start_time,
        ], $overrides);
    }

    private function openRequest(array $overrides = []): array
    {
        return array_merge([
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'client_id' => $this->client->id,
            'appointment_id' => null,
            'master_service_id' => $this->masterService->id,
            'type' => SlotRequestType::Open,
            'request_source' => SlotRequestSource::Telegram,
            'delivery_channel' => SlotRequestDeliveryChannel::Telegram,
            'date_from' => Carbon::tomorrow()->toDateString(),
            'date_to' => Carbon::tomorrow()->addDays(7)->toDateString(),
            'time_from' => '09:00',
            'time_to' => '18:00',
            'timezone' => 'Europe/Moscow',
            'appointment_start_time_snapshot' => null,
        ], $overrides);
    }

    // ── EARLIER row can be stored ─────────────────────────

    public function test_earlier_request_stored(): void
    {
        $sr = SlotRequest::create($this->earlierRequest());

        $this->assertDatabaseHas('slot_requests', [
            'id' => $sr->id,
            'type' => 'earlier',
            'status' => 'active',
            'appointment_id' => $this->appointment->id,
        ]);

        $this->assertNotNull($sr->appointment_start_time_snapshot);
    }

    // ── OPEN row can be stored ────────────────────────────

    public function test_open_request_stored(): void
    {
        $sr = SlotRequest::create($this->openRequest());

        $this->assertDatabaseHas('slot_requests', [
            'id' => $sr->id,
            'type' => 'open',
            'status' => 'active',
            'appointment_id' => null,
        ]);

        $this->assertNull($sr->appointment_start_time_snapshot);
    }

    // ── EARLIER without appointment_id allowed (historical state after FK SET NULL) ──

    public function test_earlier_without_appointment_allowed(): void
    {
        $sr = SlotRequest::create($this->earlierRequest([
            'appointment_id' => null,
        ]));

        $this->assertNotNull($sr->id);
        $this->assertNull($sr->appointment_id);
        $this->assertNotNull($sr->appointment_start_time_snapshot);
    }

    // ── EARLIER without snapshot rejected ─────────────────

    public function test_earlier_without_snapshot_rejected(): void
    {
        $this->expectException(QueryException::class);

        SlotRequest::create($this->earlierRequest([
            'appointment_start_time_snapshot' => null,
        ]));
    }

    // ── OPEN with appointment_id rejected ─────────────────

    public function test_open_with_appointment_rejected(): void
    {
        $this->expectException(QueryException::class);

        SlotRequest::create($this->openRequest([
            'appointment_id' => $this->appointment->id,
        ]));
    }

    // ── date_from > date_to rejected ──────────────────────

    public function test_date_from_after_date_to_rejected(): void
    {
        $this->expectException(QueryException::class);

        SlotRequest::create($this->openRequest([
            'date_from' => Carbon::tomorrow()->addDays(7)->toDateString(),
            'date_to' => Carbon::tomorrow()->toDateString(),
        ]));
    }

    // ── time_from >= time_to rejected ─────────────────────

    public function test_time_from_equal_time_to_rejected(): void
    {
        $this->expectException(QueryException::class);

        SlotRequest::create($this->openRequest([
            'time_from' => '18:00',
            'time_to' => '18:00',
        ]));
    }

    public function test_time_from_after_time_to_rejected(): void
    {
        $this->expectException(QueryException::class);

        SlotRequest::create($this->openRequest([
            'time_from' => '20:00',
            'time_to' => '09:00',
        ]));
    }

    // ── Second active EARLIER for same appointment rejected ──

    public function test_second_active_earlier_for_same_appointment_rejected(): void
    {
        SlotRequest::create($this->earlierRequest());

        $this->expectException(QueryException::class);

        SlotRequest::create($this->earlierRequest());
    }

    // ── Historical EARLIER does not block new active ──────

    public function test_historical_earlier_allows_new_active(): void
    {
        $old = SlotRequest::create($this->earlierRequest());
        $old->update([
            'status' => SlotRequestStatus::Fulfilled,
            'fulfilled_at' => now(),
        ]);

        $new = SlotRequest::create($this->earlierRequest());

        $this->assertNotNull($new->id);
        $this->assertEquals(SlotRequestStatus::Active, $new->status);
    }

    // ── Second active OPEN for same client+service rejected ──

    public function test_second_active_open_for_same_client_service_rejected(): void
    {
        SlotRequest::create($this->openRequest());

        $this->expectException(QueryException::class);

        SlotRequest::create($this->openRequest());
    }

    // ── Historical OPEN does not block new active ─────────

    public function test_historical_open_allows_new_active(): void
    {
        $old = SlotRequest::create($this->openRequest());
        $old->update([
            'status' => SlotRequestStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        $new = SlotRequest::create($this->openRequest());

        $this->assertNotNull($new->id);
        $this->assertEquals(SlotRequestStatus::Active, $new->status);
    }

    // ── Enum casts ────────────────────────────────────────

    public function test_enum_casts(): void
    {
        $sr = SlotRequest::create($this->earlierRequest());

        $this->assertInstanceOf(SlotRequestType::class, $sr->type);
        $this->assertSame(SlotRequestType::Earlier, $sr->type);

        $this->assertInstanceOf(SlotRequestStatus::class, $sr->status);
        $this->assertSame(SlotRequestStatus::Active, $sr->status);

        $this->assertInstanceOf(SlotRequestSource::class, $sr->request_source);
        $this->assertSame(SlotRequestSource::Web, $sr->request_source);

        $this->assertInstanceOf(SlotRequestDeliveryChannel::class, $sr->delivery_channel);
        $this->assertSame(SlotRequestDeliveryChannel::Telegram, $sr->delivery_channel);
    }

    // ── Date/time casts ───────────────────────────────────

    public function test_date_time_casts(): void
    {
        $sr = SlotRequest::create($this->earlierRequest());

        $this->assertInstanceOf(\Carbon\CarbonImmutable::class, $sr->date_from);
        $this->assertInstanceOf(\Carbon\CarbonImmutable::class, $sr->date_to);
        $this->assertIsString($sr->time_from);
        $this->assertIsString($sr->time_to);
        $this->assertInstanceOf(\Carbon\CarbonImmutable::class, $sr->appointment_start_time_snapshot);
    }

    // ── Relationships ─────────────────────────────────────

    public function test_relationships(): void
    {
        $sr = SlotRequest::create($this->earlierRequest());

        $this->assertEquals($this->ws->id, $sr->workspace->id);
        $this->assertEquals($this->master->id, $sr->master->id);
        $this->assertEquals($this->client->id, $sr->client->id);
        $this->assertEquals($this->appointment->id, $sr->appointment->id);
        $this->assertEquals($this->masterService->id, $sr->masterService->id);
    }

    // ── Default status ────────────────────────────────────

    public function test_default_status_is_active(): void
    {
        $sr = SlotRequest::create($this->openRequest());

        $this->assertSame(SlotRequestStatus::Active, $sr->status);
    }

    // ── Appointment deletion: FK ON DELETE SET NULL ────────

    public function test_appointment_delete_succeeds_with_active_earlier_request(): void
    {
        SlotRequest::create($this->earlierRequest());

        // DELETE should succeed (no CHECK violation)
        $this->appointment->delete();

        $this->assertDatabaseMissing('appointments', [
            'id' => $this->appointment->id,
        ]);
    }

    public function test_slot_request_survives_appointment_deletion(): void
    {
        $sr = SlotRequest::create($this->earlierRequest());

        $this->appointment->delete();

        $this->assertDatabaseHas('slot_requests', [
            'id' => $sr->id,
            'type' => 'earlier',
        ]);
    }

    public function test_appointment_id_null_after_appointment_deletion(): void
    {
        $sr = SlotRequest::create($this->earlierRequest());

        $this->appointment->delete();

        $this->assertNull($sr->fresh()->appointment_id);
    }

    public function test_snapshot_preserved_after_appointment_deletion(): void
    {
        $sr = SlotRequest::create($this->earlierRequest());
        $originalSnapshot = $sr->appointment_start_time_snapshot;

        $this->appointment->delete();

        $this->assertEquals(
            $originalSnapshot->format('Y-m-d H:i:s'),
            $sr->fresh()->appointment_start_time_snapshot->format('Y-m-d H:i:s'),
        );
    }

    public function test_other_fields_preserved_after_appointment_deletion(): void
    {
        $sr = SlotRequest::create($this->earlierRequest());

        $this->appointment->delete();

        $fresh = $sr->fresh();
        $this->assertEquals($this->ws->id, $fresh->workspace_id);
        $this->assertEquals($this->master->id, $fresh->master_id);
        $this->assertEquals($this->client->id, $fresh->client_id);
        $this->assertEquals($this->masterService->id, $fresh->master_service_id);
        $this->assertEquals(SlotRequestType::Earlier, $fresh->type);
        $this->assertEquals(SlotRequestStatus::Active, $fresh->status);
        $this->assertStringStartsWith('09:00', $fresh->time_from);
        $this->assertStringStartsWith('18:00', $fresh->time_to);
        $this->assertEquals('Europe/Moscow', $fresh->timezone);
    }

    // ── OPEN with snapshot rejected ────────────────────────

    public function test_open_with_snapshot_rejected(): void
    {
        $this->expectException(QueryException::class);

        SlotRequest::create($this->openRequest([
            'appointment_start_time_snapshot' => Carbon::tomorrow()->setTime(14, 0),
        ]));
    }

    // ── SlotRequestService creation contract ───────────────

    public function test_service_creates_earlier_with_real_appointment(): void
    {
        $this->client->update(['telegram_id' => '123456789']);

        $service = app(\App\Services\SlotRequestService::class);

        $sr = $service->createOrUpdateEarlierRequest(
            appointment: $this->appointment,
            client: $this->client,
            dateFrom: Carbon::tomorrow()->toDateString(),
            dateTo: Carbon::tomorrow()->toDateString(),
            timeFrom: '09:00',
            timeTo: '13:00',
            deliveryChannel: SlotRequestDeliveryChannel::Telegram,
            requestSource: SlotRequestSource::Web,
        );

        $this->assertNotNull($sr->appointment_id);
        $this->assertEquals($this->appointment->id, $sr->appointment_id);
        $this->assertNotNull($sr->appointment_start_time_snapshot);
    }
}
