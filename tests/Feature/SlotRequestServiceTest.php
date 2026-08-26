<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
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
use App\Services\SlotRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlotRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    private SlotRequestService $service;
    private User $master;
    private Workspace $ws;
    private Client $client;
    private MasterService $masterService;
    private Appointment $appointment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SlotRequestService;

        $this->master = User::factory()->master()->create();
        $this->ws = Workspace::create(['name' => 'WS Test', 'owner_id' => $this->master->id]);
        $this->master->update(['workspace_id' => $this->ws->id]);

        $this->client = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'telegram_id' => 'tg_123',
            'max_id' => 'max_123',
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

        // Appointment tomorrow at 16:00 master-local (stored as UTC)
        $tz = $this->master->getTimezone();
        $localStart = Carbon::tomorrow($tz)->setTime(16, 0);
        $utcStart = $localStart->copy()->setTimezone('UTC');

        $this->appointment = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($this->client)
            ->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => $utcStart,
                'duration' => 60,
            ]);
    }

    private function validEarlierArgs(array $overrides = []): array
    {
        $tz = $this->master->getTimezone();
        $apptLocal = $this->appointment->start_time->timezone($tz);

        return array_merge([
            'appointment' => $this->appointment,
            'client' => $this->client,
            'dateFrom' => $apptLocal->format('Y-m-d'),
            'dateTo' => $apptLocal->format('Y-m-d'),
            'timeFrom' => '09:00',
            'timeTo' => '15:00',
            'deliveryChannel' => SlotRequestDeliveryChannel::Telegram,
            'requestSource' => SlotRequestSource::Web,
        ], $overrides);
    }

    // ── Eligibility: Booked ───────────────────────────────

    public function test_booked_appointment_can_create(): void
    {
        $sr = $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs());

        $this->assertEquals(SlotRequestType::Earlier, $sr->type);
        $this->assertEquals(SlotRequestStatus::Active, $sr->status);
    }

    // ── Eligibility: Prepaid rejected ─────────────────────

    public function test_prepaid_rejected(): void
    {
        $this->appointment->update(['status' => AppointmentStatus::Prepaid]);

        $this->expectException(\DomainException::class);

        $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs());
    }

    // ── Eligibility: PendingPayment rejected ──────────────

    public function test_pending_payment_rejected(): void
    {
        $this->appointment->update(['status' => AppointmentStatus::PendingPayment]);

        $this->expectException(\DomainException::class);

        $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs());
    }

    // ── Eligibility: Paid rejected ────────────────────────

    public function test_paid_rejected(): void
    {
        $this->appointment->update(['status' => AppointmentStatus::Paid]);

        $this->expectException(\DomainException::class);

        $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs());
    }

    // ── Eligibility: Cancelled rejected ───────────────────

    public function test_cancelled_rejected(): void
    {
        $this->appointment->update(['status' => AppointmentStatus::Cancelled]);

        $this->expectException(\DomainException::class);

        $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs());
    }

    // ── Eligibility: NoShow rejected ──────────────────────

    public function test_no_show_rejected(): void
    {
        $this->appointment->update(['status' => AppointmentStatus::NoShow]);

        $this->expectException(\DomainException::class);

        $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs());
    }

    // ── Eligibility: wrong client ─────────────────────────

    public function test_wrong_client_rejected(): void
    {
        $otherClient = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
        ]);

        $this->expectException(\DomainException::class);

        $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs([
            'client' => $otherClient,
        ]));
    }

    // ── Eligibility: past appointment ─────────────────────

    public function test_past_appointment_rejected(): void
    {
        $this->appointment->update(['start_time' => Carbon::yesterday()]);

        $this->expectException(\DomainException::class);

        $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs());
    }

    // ── Eligibility: no master_service_id ─────────────────

    public function test_no_master_service_rejected(): void
    {
        $this->appointment->update(['master_service_id' => null]);

        $this->expectException(\DomainException::class);

        $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs());
    }

    // ── Eligibility: inactive MasterService ───────────────

    public function test_inactive_master_service_rejected(): void
    {
        $this->masterService->update(['is_active' => false]);

        $this->expectException(\DomainException::class);

        $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs());
    }

    // ── Timezone from master ──────────────────────────────

    public function test_timezone_derived_from_master(): void
    {
        $this->master->setTimezone('Asia/Yekaterinburg');

        $sr = $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs());

        $this->assertEquals('Asia/Yekaterinburg', $sr->timezone);
    }

    // ── IDs derived server-side ───────────────────────────

    public function test_ids_derived_server_side(): void
    {
        $sr = $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs());

        $this->assertEquals($this->ws->id, $sr->workspace_id);
        $this->assertEquals($this->master->id, $sr->master_id);
        $this->assertEquals($this->client->id, $sr->client_id);
        $this->assertEquals($this->appointment->id, $sr->appointment_id);
        $this->assertEquals($this->masterService->id, $sr->master_service_id);
    }

    // ── Snapshot ──────────────────────────────────────────

    public function test_snapshot_equals_appointment_start_time(): void
    {
        $sr = $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs());

        $this->assertEquals($this->appointment->start_time, $sr->appointment_start_time_snapshot);
    }

    // ── Same-day earlier window accepted ──────────────────

    public function test_same_day_earlier_window_accepted(): void
    {
        $tz = $this->master->getTimezone();
        $apptLocal = $this->appointment->start_time->timezone($tz);

        $sr = $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs([
            'timeFrom' => '12:00',
            'timeTo' => '15:00',
        ]));

        $this->assertEquals(SlotRequestStatus::Active, $sr->status);
    }

    // ── Same-day window entirely after appointment ────────

    public function test_same_day_window_after_appointment_rejected(): void
    {
        $this->expectException(\DomainException::class);

        $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs([
            'timeFrom' => '17:00',
            'timeTo' => '19:00',
        ]));
    }

    // ── Date range completely after appointment ───────────

    public function test_date_range_after_appointment_rejected(): void
    {
        $tz = $this->master->getTimezone();
        $apptLocal = $this->appointment->start_time->timezone($tz);

        $this->expectException(\DomainException::class);

        $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs([
            'dateFrom' => $apptLocal->copy()->addDays(3)->format('Y-m-d'),
            'dateTo' => $apptLocal->copy()->addDays(5)->format('Y-m-d'),
        ]));
    }

    // ── Duration does not fit ─────────────────────────────

    public function test_duration_does_not_fit_rejected(): void
    {
        // Appointment is 60 min, window is only 30 min
        $this->expectException(\DomainException::class);

        $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs([
            'timeFrom' => '14:00',
            'timeTo' => '14:30',
        ]));
    }

    // ── time_from >= time_to rejected ─────────────────────

    public function test_time_from_equal_time_to_rejected(): void
    {
        $this->expectException(\DomainException::class);

        $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs([
            'timeFrom' => '15:00',
            'timeTo' => '15:00',
        ]));
    }

    // ── Create same active request again → update ─────────

    public function test_create_same_active_request_updates(): void
    {
        $sr1 = $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs());
        $sr2 = $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs([
            'timeFrom' => '10:00',
            'timeTo' => '14:00',
        ]));

        $this->assertEquals($sr1->id, $sr2->id);
        $this->assertEquals('10:00:00', $sr2->time_from);
        $this->assertEquals('14:00:00', $sr2->time_to);
    }

    // ── Update preserves id + created_at ──────────────────

    public function test_update_preserves_id_and_created_at(): void
    {
        $sr1 = $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs());
        $originalId = $sr1->id;
        $originalCreatedAt = $sr1->created_at;

        $sr2 = $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs([
            'timeFrom' => '10:00',
            'timeTo' => '14:00',
        ]));

        $this->assertEquals($originalId, $sr2->id);
        $this->assertEquals($originalCreatedAt->format('Y-m-d H:i:s'), $sr2->created_at->format('Y-m-d H:i:s'));
    }

    // ── Stale anchor: appointment start changed ───────────

    public function test_stale_anchor_creates_new_request(): void
    {
        $sr1 = $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs());

        // Manually reschedule appointment
        $tz = $this->master->getTimezone();
        $newLocal = Carbon::tomorrow($tz)->setTime(18, 0);
        $this->appointment->update(['start_time' => $newLocal->copy()->setTimezone('UTC')]);

        $sr2 = $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs([
            'timeTo' => '17:00',
        ]));

        $sr1->refresh();
        $this->assertEquals(SlotRequestStatus::Expired, $sr1->status);
        $this->assertNotNull($sr1->expired_at);

        $this->assertNotEquals($sr1->id, $sr2->id);
        $this->assertEquals(SlotRequestStatus::Active, $sr2->status);
        $this->assertEquals($this->appointment->fresh()->start_time, $sr2->appointment_start_time_snapshot);
    }

    // ── Historical fulfilled permits new ──────────────────

    public function test_historical_fulfilled_permits_new(): void
    {
        $sr1 = $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs());
        $sr1->update(['status' => SlotRequestStatus::Fulfilled, 'fulfilled_at' => now()]);

        $sr2 = $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs());

        $this->assertNotEquals($sr1->id, $sr2->id);
        $this->assertEquals(SlotRequestStatus::Active, $sr2->status);
    }

    // ── Historical cancelled permits new ──────────────────

    public function test_historical_cancelled_permits_new(): void
    {
        $sr1 = $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs());
        $this->service->cancel($sr1, $this->client);

        $sr2 = $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs());

        $this->assertNotEquals($sr1->id, $sr2->id);
        $this->assertEquals(SlotRequestStatus::Active, $sr2->status);
    }

    // ── Historical expired permits new ────────────────────

    public function test_historical_expired_permits_new(): void
    {
        $sr1 = $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs());
        $sr1->update(['status' => SlotRequestStatus::Expired, 'expired_at' => now()]);

        $sr2 = $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs());

        $this->assertNotEquals($sr1->id, $sr2->id);
        $this->assertEquals(SlotRequestStatus::Active, $sr2->status);
    }

    // ── Telegram delivery rejected without telegram_id ────

    public function test_telegram_delivery_rejected_without_telegram_id(): void
    {
        $this->client->update(['telegram_id' => null]);

        $this->expectException(\DomainException::class);

        $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs([
            'deliveryChannel' => SlotRequestDeliveryChannel::Telegram,
        ]));
    }

    // ── MAX delivery rejected without max_id ──────────────

    public function test_max_delivery_rejected_without_max_id(): void
    {
        $this->client->update(['max_id' => null]);

        $this->expectException(\DomainException::class);

        $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs([
            'deliveryChannel' => SlotRequestDeliveryChannel::Max,
        ]));
    }

    // ── Valid Telegram delivery accepted ───────────────────

    public function test_valid_telegram_delivery_accepted(): void
    {
        $sr = $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs([
            'deliveryChannel' => SlotRequestDeliveryChannel::Telegram,
        ]));

        $this->assertEquals(SlotRequestDeliveryChannel::Telegram, $sr->delivery_channel);
    }

    // ── Valid MAX delivery accepted ────────────────────────

    public function test_valid_max_delivery_accepted(): void
    {
        $sr = $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs([
            'deliveryChannel' => SlotRequestDeliveryChannel::Max,
        ]));

        $this->assertEquals(SlotRequestDeliveryChannel::Max, $sr->delivery_channel);
    }

    // ── Cancel active request ─────────────────────────────

    public function test_cancel_active_request(): void
    {
        $sr = $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs());

        $result = $this->service->cancel($sr, $this->client);

        $this->assertEquals(SlotRequestStatus::Cancelled, $result->status);
        $this->assertNotNull($result->cancelled_at);
    }

    // ── Cancel already cancelled is idempotent ────────────

    public function test_cancel_already_cancelled_is_idempotent(): void
    {
        $sr = $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs());

        $this->service->cancel($sr, $this->client);
        $cancelledAt = $sr->fresh()->cancelled_at;

        $result = $this->service->cancel($sr->fresh(), $this->client);

        $this->assertEquals(SlotRequestStatus::Cancelled, $result->status);
        $this->assertEquals($cancelledAt->format('Y-m-d H:i:s'), $result->cancelled_at->format('Y-m-d H:i:s'));
    }

    // ── One client cannot cancel another's request ────────

    public function test_other_client_cannot_cancel(): void
    {
        $sr = $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs());

        $otherClient = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
        ]);

        $this->expectException(\DomainException::class);

        $this->service->cancel($sr, $otherClient);
    }

    // ── expires_at derived server-side ────────────────────

    public function test_expires_at_not_after_appointment_start(): void
    {
        $sr = $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs());

        $this->assertNotNull($sr->expires_at);
        $this->assertTrue($sr->expires_at->lte($this->appointment->start_time));
    }

    // ── time_from/time_to are plain strings ───────────────

    public function test_time_fields_are_plain_strings(): void
    {
        $sr = $this->service->createOrUpdateEarlierRequest(...$this->validEarlierArgs());

        $this->assertIsString($sr->time_from);
        $this->assertIsString($sr->time_to);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}(:\d{2})?$/', $sr->time_from);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}(:\d{2})?$/', $sr->time_to);
    }
}
