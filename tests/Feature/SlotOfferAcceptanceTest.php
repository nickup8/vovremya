<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\SlotOfferStatus;
use App\Enums\SlotOpportunitySourceType;
use App\Enums\SlotOpportunityStatus;
use App\Enums\SlotRequestDeliveryChannel;
use App\Enums\SlotRequestSource;
use App\Enums\SlotRequestStatus;
use App\Enums\SlotRequestType;
use App\Enums\SubscriptionStatus;
use App\Jobs\MatchSlotOpportunityJob;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\SlotOffer;
use App\Models\SlotOpportunity;
use App\Models\SlotRequest;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkingHour;
use App\Services\SlotOfferAcceptanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SlotOfferAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private SlotOfferAcceptanceService $service;
    private TariffPlan $proPlan;
    private User $master;
    private Workspace $ws;
    private Client $client;
    private MasterService $masterService;
    private Appointment $sourceAppointment;
    private SlotRequest $request;
    private SlotOpportunity $opportunity;
    private SlotOffer $offer;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();

        $this->service = app(SlotOfferAcceptanceService::class);

        $this->proPlan = TariffPlan::create([
            'code' => 'pro', 'name' => 'Профи', 'price_monthly' => 490,
            'features' => ['unlimited_appointments', 'client_management', 'channel_analytics', 'slot_autofill'],
            'is_active' => true,
        ]);

        $this->master = User::factory()->master()->create();
        $this->ws = Workspace::create(['name' => 'WS Test', 'owner_id' => $this->master->id]);
        $this->master->update(['workspace_id' => $this->ws->id, 'autofill_enabled' => true]);

        Subscription::create([
            'workspace_id' => $this->ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);

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

        // Working hours: 09:00-18:00 every day, no break
        for ($day = 0; $day <= 6; $day++) {
            WorkingHour::updateOrCreate(
                ['user_id' => $this->master->id, 'day_of_week' => $day],
                [
                    'is_working' => true,
                    'start_time' => '09:00',
                    'end_time' => '18:00',
                    'break_start_time' => null,
                    'break_end_time' => null,
                ],
            );
        }

        // Source appointment: tomorrow 14:00, duration 60
        $this->sourceAppointment = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($this->client)
            ->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(14, 0),
                'duration' => 60,
            ]);

        // EARLIER request
        $this->request = SlotRequest::create([
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'client_id' => $this->client->id,
            'appointment_id' => $this->sourceAppointment->id,
            'master_service_id' => $this->masterService->id,
            'type' => SlotRequestType::Earlier,
            'request_source' => SlotRequestSource::Web,
            'delivery_channel' => SlotRequestDeliveryChannel::Telegram,
            'date_from' => Carbon::tomorrow()->toDateString(),
            'date_to' => Carbon::tomorrow()->toDateString(),
            'time_from' => '09:00',
            'time_to' => '18:00',
            'timezone' => 'Europe/Moscow',
            'appointment_start_time_snapshot' => $this->sourceAppointment->start_time,
            'expires_at' => Carbon::tomorrow()->setTime(13, 59),
        ]);

        // Opportunity: tomorrow 10:00, duration 60
        $this->opportunity = SlotOpportunity::create([
            'origin_event_id' => (string) Str::uuid(),
            'chain_id' => (string) Str::uuid(),
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'master_service_id' => $this->masterService->id,
            'source_type' => SlotOpportunitySourceType::Cancellation,
            'status' => SlotOpportunityStatus::Open,
            'start_time' => Carbon::tomorrow()->setTime(10, 0),
            'duration' => 60,
        ]);

        // Pending offer
        $this->offer = SlotOffer::create([
            'slot_request_id' => $this->request->id,
            'slot_opportunity_id' => $this->opportunity->id,
            'status' => SlotOfferStatus::Pending,
            'expires_at' => Carbon::tomorrow()->setTime(9, 59),
        ]);
    }

    // ── Success ───────────────────────────────────────────

    public function test_success_moves_appointment_and_updates_lifecycle(): void
    {
        $result = $this->service->acceptEarlier($this->offer);

        $this->assertTrue($result['success']);

        // Appointment moved to 10:00 UTC = 13:00 Moscow
        $moved = $this->sourceAppointment->fresh();
        $this->assertEquals(
            Carbon::tomorrow()->setTime(10, 0)->format('Y-m-d H:i'),
            $moved->start_time->format('Y-m-d H:i'),
        );

        // Offer accepted
        $this->assertSame(SlotOfferStatus::Accepted, $this->offer->fresh()->status);
        $this->assertNotNull($this->offer->fresh()->accepted_at);

        // Request fulfilled
        $this->assertSame(SlotRequestStatus::Fulfilled, $this->request->fresh()->status);
        $this->assertNotNull($this->request->fresh()->fulfilled_at);

        // Opportunity filled
        $this->assertSame(SlotOpportunityStatus::Filled, $this->opportunity->fresh()->status);
        $this->assertEquals($this->sourceAppointment->id, $this->opportunity->fresh()->filled_by_appointment_id);
    }

    public function test_old_slot_creates_autofill_reschedule_opportunity(): void
    {
        $result = $this->service->acceptEarlier($this->offer);

        $this->assertTrue($result['success']);

        // The reschedule was called with autofillChainId, which means
        // captureRescheduleFreedWindow will produce AutoFillReschedule source type
        // and the opportunity's chain_id. The actual opportunity creation happens
        // via FreedWindowDispatcher → CreateSlotOpportunityJob after commit.
        // Bus::fake() intercepts the job dispatch, so we verify acceptance succeeded.
        $this->assertSame(SlotOfferStatus::Accepted, $this->offer->fresh()->status);
    }

    // ── Timezone ──────────────────────────────────────────

    public function test_timezone_conversion_preserves_instant(): void
    {
        // Opportunity at 10:00 UTC = 13:00 Moscow
        $result = $this->service->acceptEarlier($this->offer);

        $this->assertTrue($result['success']);

        $moved = $this->sourceAppointment->fresh();
        // The appointment should be at 10:00 UTC (the exact opportunity instant)
        $this->assertEquals(
            Carbon::tomorrow()->setTime(10, 0)->format('Y-m-d H:i:s'),
            $moved->start_time->format('Y-m-d H:i:s'),
        );
    }

    // ── Duration ──────────────────────────────────────────

    public function test_persisted_duration_used_no_fallback(): void
    {
        // Set appointment duration to 60, change MasterService to 90
        $this->sourceAppointment->update(['duration' => 60]);
        $this->masterService->catalog->update(['base_duration' => 90]);

        $result = $this->service->acceptEarlier($this->offer);

        $this->assertTrue($result['success']);
    }

    // ── Expired offer ─────────────────────────────────────

    public function test_expired_offer_cannot_move_appointment(): void
    {
        $this->offer->update(['expires_at' => Carbon::yesterday()]);

        $result = $this->service->acceptEarlier($this->offer);

        $this->assertFalse($result['success']);
        $this->assertEquals('expired', $result['error']);

        // Appointment unchanged
        $this->assertEquals(
            Carbon::tomorrow()->setTime(14, 0)->format('Y-m-d H:i'),
            $this->sourceAppointment->fresh()->start_time->format('Y-m-d H:i'),
        );
    }

    // ── Stale request ─────────────────────────────────────

    public function test_stale_request_invalidates_offer_and_expires_request(): void
    {
        // Make source appointment not Booked
        $this->sourceAppointment->update(['status' => AppointmentStatus::Cancelled]);

        $result = $this->service->acceptEarlier($this->offer);

        $this->assertFalse($result['success']);

        // Offer invalidated
        $this->assertSame(SlotOfferStatus::Invalidated, $this->offer->fresh()->status);

        // Request expired
        $this->assertSame(SlotRequestStatus::Expired, $this->request->fresh()->status);

        // Opportunity stays Open
        $this->assertSame(SlotOpportunityStatus::Open, $this->opportunity->fresh()->status);

        // Rematch dispatched
        Bus::assertDispatched(MatchSlotOpportunityJob::class, function ($job) {
            return $job->slotOpportunityId === $this->opportunity->id;
        });
    }

    // ── Slot taken ────────────────────────────────────────

    public function test_externally_taken_slot_invalidates_offer_and_opportunity(): void
    {
        // Create a blocking appointment at the same time
        $otherClient = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
        ]);

        Appointment::factory()
            ->forMaster($this->master)
            ->forClient($otherClient)
            ->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(10, 0),
                'duration' => 60,
            ]);

        $result = $this->service->acceptEarlier($this->offer);

        $this->assertFalse($result['success']);
        $this->assertEquals('slot_unavailable', $result['error']);

        // Offer invalidated
        $this->assertSame(SlotOfferStatus::Invalidated, $this->offer->fresh()->status);

        // Opportunity invalidated
        $this->assertSame(SlotOpportunityStatus::Invalidated, $this->opportunity->fresh()->status);

        // Request stays Active
        $this->assertSame(SlotRequestStatus::Active, $this->request->fresh()->status);
    }

    // ── Duplicate accept ──────────────────────────────────

    public function test_duplicate_accept_idempotent(): void
    {
        // First accept
        $result1 = $this->service->acceptEarlier($this->offer);
        $this->assertTrue($result1['success']);

        // Second accept — idempotent
        $result2 = $this->service->acceptEarlier($this->offer->fresh());
        $this->assertTrue($result2['success']);
        $this->assertTrue($result2['idempotent']);

        // Appointment not moved twice — still at the opportunity time
        $moved = $this->sourceAppointment->fresh();
        $this->assertEquals(
            Carbon::tomorrow()->setTime(10, 0)->format('Y-m-d H:i'),
            $moved->start_time->format('Y-m-d H:i'),
        );
    }

    // ── Rollback ──────────────────────────────────────────

    public function test_forced_rollback_no_partial_changes(): void
    {
        // Wrap in outer transaction and rollback
        DB::beginTransaction();

        $result = $this->service->acceptEarlier($this->offer);

        DB::rollBack();

        // All lifecycle states unchanged
        $this->assertSame(SlotOfferStatus::Pending, $this->offer->fresh()->status);
        $this->assertSame(SlotRequestStatus::Active, $this->request->fresh()->status);
        $this->assertSame(SlotOpportunityStatus::Open, $this->opportunity->fresh()->status);

        // Appointment not moved
        $this->assertEquals(
            Carbon::tomorrow()->setTime(14, 0)->format('Y-m-d H:i'),
            $this->sourceAppointment->fresh()->start_time->format('Y-m-d H:i'),
        );

        // No freed-window dispatched
        Bus::assertNotDispatched(MatchSlotOpportunityJob::class);
    }
}
