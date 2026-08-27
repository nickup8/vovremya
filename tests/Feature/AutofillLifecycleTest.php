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
use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\SlotOffer;
use App\Models\SlotOpportunity;
use App\Models\SlotRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SlotOpportunityService;
use App\Services\SlotRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AutofillLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private SlotRequestService $requestService;
    private SlotOpportunityService $opportunityService;
    private User $master;
    private Workspace $ws;
    private Client $client;
    private MasterService $masterService;
    private Appointment $appointment;
    private SlotRequest $request;
    private SlotOpportunity $opportunity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requestService = new SlotRequestService();
        $this->opportunityService = new SlotOpportunityService();

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
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(14, 0),
                'duration' => 60,
            ]);

        $this->request = SlotRequest::create([
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
        ]);

        $this->opportunity = SlotOpportunity::create([
            'origin_event_id' => (string) \Illuminate\Support\Str::uuid(),
            'chain_id' => (string) \Illuminate\Support\Str::uuid(),
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'master_service_id' => $this->masterService->id,
            'source_type' => SlotOpportunitySourceType::Cancellation,
            'status' => SlotOpportunityStatus::Open,
            'start_time' => Carbon::yesterday()->setTime(10, 0),
            'duration' => 60,
        ]);
    }

    // ── SlotRequest expire ────────────────────────────────

    public function test_active_request_expires(): void
    {
        $result = $this->requestService->expire($this->request);

        $this->assertSame(SlotRequestStatus::Expired, $result->status);
    }

    public function test_request_expired_at_set(): void
    {
        $result = $this->requestService->expire($this->request);

        $this->assertNotNull($result->expired_at);
    }

    public function test_request_expire_idempotent(): void
    {
        $result1 = $this->requestService->expire($this->request);
        $result2 = $this->requestService->expire($result1);

        $this->assertEquals($result1->id, $result2->id);
        $this->assertSame(SlotRequestStatus::Expired, $result2->status);
    }

    public function test_cancelled_request_cannot_be_expired(): void
    {
        $this->request->update([
            'status' => SlotRequestStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        $this->expectException(\DomainException::class);

        $this->requestService->expire($this->request->fresh());
    }

    public function test_fulfilled_request_cannot_be_expired(): void
    {
        $this->request->update([
            'status' => SlotRequestStatus::Fulfilled,
            'fulfilled_at' => now(),
        ]);

        $this->expectException(\DomainException::class);

        $this->requestService->expire($this->request->fresh());
    }

    public function test_active_request_can_expire_before_request_expires_at(): void
    {
        // Request.expires_at is in the future, but we can still expire it
        // (matcher may expire stale requests before calendar expires_at)
        $this->request->update(['expires_at' => Carbon::tomorrow()]);

        $result = $this->requestService->expire($this->request);

        $this->assertSame(SlotRequestStatus::Expired, $result->status);
    }

    // ── SlotOpportunity expire ────────────────────────────

    public function test_open_past_opportunity_expires(): void
    {
        $result = $this->opportunityService->expire($this->opportunity);

        $this->assertSame(SlotOpportunityStatus::Expired, $result->status);
    }

    public function test_opportunity_expired_at_set(): void
    {
        $result = $this->opportunityService->expire($this->opportunity);

        $this->assertNotNull($result->expired_at);
    }

    public function test_opportunity_expire_idempotent(): void
    {
        $result1 = $this->opportunityService->expire($this->opportunity);
        $result2 = $this->opportunityService->expire($result1);

        $this->assertEquals($result1->id, $result2->id);
        $this->assertSame(SlotOpportunityStatus::Expired, $result2->status);
    }

    public function test_future_opportunity_expire_rejected(): void
    {
        $this->opportunity->update(['start_time' => Carbon::tomorrow()->setTime(10, 0)]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('before its start_time');

        $this->opportunityService->expire($this->opportunity->fresh());
    }

    public function test_filled_opportunity_cannot_be_expired(): void
    {
        $this->opportunity->update(['status' => SlotOpportunityStatus::Filled]);

        $this->expectException(\DomainException::class);

        $this->opportunityService->expire($this->opportunity->fresh());
    }

    public function test_invalidated_opportunity_cannot_be_expired(): void
    {
        $this->opportunity->update([
            'status' => SlotOpportunityStatus::Invalidated,
            'invalidated_at' => now(),
        ]);

        $this->expectException(\DomainException::class);

        $this->opportunityService->expire($this->opportunity->fresh());
    }

    // ── SlotOpportunity invalidate ────────────────────────

    public function test_open_opportunity_invalidates(): void
    {
        $result = $this->opportunityService->invalidate($this->opportunity);

        $this->assertSame(SlotOpportunityStatus::Invalidated, $result->status);
    }

    public function test_opportunity_invalidated_at_set(): void
    {
        $result = $this->opportunityService->invalidate($this->opportunity);

        $this->assertNotNull($result->invalidated_at);
    }

    public function test_opportunity_invalidate_idempotent(): void
    {
        $result1 = $this->opportunityService->invalidate($this->opportunity);
        $result2 = $this->opportunityService->invalidate($result1);

        $this->assertEquals($result1->id, $result2->id);
        $this->assertSame(SlotOpportunityStatus::Invalidated, $result2->status);
    }

    public function test_filled_opportunity_cannot_be_invalidated(): void
    {
        $this->opportunity->update(['status' => SlotOpportunityStatus::Filled]);

        $this->expectException(\DomainException::class);

        $this->opportunityService->invalidate($this->opportunity->fresh());
    }

    public function test_expired_opportunity_cannot_be_invalidated(): void
    {
        $this->opportunity->update([
            'status' => SlotOpportunityStatus::Expired,
            'expired_at' => now(),
        ]);

        $this->expectException(\DomainException::class);

        $this->opportunityService->invalidate($this->opportunity->fresh());
    }

    // ── SlotRequest relations ─────────────────────────────

    public function test_request_offers_returns_history(): void
    {
        $offer = SlotOffer::create([
            'slot_request_id' => $this->request->id,
            'slot_opportunity_id' => $this->opportunity->id,
            'status' => SlotOfferStatus::Pending,
            'expires_at' => Carbon::tomorrow()->setTime(9, 59),
        ]);

        $this->assertCount(1, $this->request->offers);
        $this->assertEquals($offer->id, $this->request->offers->first()->id);
    }

    public function test_request_pending_offer_returns_pending(): void
    {
        $offer = SlotOffer::create([
            'slot_request_id' => $this->request->id,
            'slot_opportunity_id' => $this->opportunity->id,
            'status' => SlotOfferStatus::Pending,
            'expires_at' => Carbon::tomorrow()->setTime(9, 59),
        ]);

        $this->assertNotNull($this->request->pendingOffer);
        $this->assertEquals($offer->id, $this->request->pendingOffer->id);
    }

    public function test_request_pending_offer_null_after_decline(): void
    {
        $offer = SlotOffer::create([
            'slot_request_id' => $this->request->id,
            'slot_opportunity_id' => $this->opportunity->id,
            'status' => SlotOfferStatus::Pending,
            'expires_at' => Carbon::tomorrow()->setTime(9, 59),
        ]);

        $offer->update([
            'status' => SlotOfferStatus::Declined,
            'declined_at' => now(),
        ]);

        $this->assertNull($this->request->fresh()->pendingOffer);
        $this->assertCount(1, $this->request->fresh()->offers);
    }

    // ── SlotOpportunity relations ─────────────────────────

    public function test_opportunity_offers_returns_history(): void
    {
        $offer = SlotOffer::create([
            'slot_request_id' => $this->request->id,
            'slot_opportunity_id' => $this->opportunity->id,
            'status' => SlotOfferStatus::Pending,
            'expires_at' => Carbon::tomorrow()->setTime(9, 59),
        ]);

        $this->assertCount(1, $this->opportunity->offers);
        $this->assertEquals($offer->id, $this->opportunity->offers->first()->id);
    }

    public function test_opportunity_pending_offer_works(): void
    {
        $offer = SlotOffer::create([
            'slot_request_id' => $this->request->id,
            'slot_opportunity_id' => $this->opportunity->id,
            'status' => SlotOfferStatus::Pending,
            'expires_at' => Carbon::tomorrow()->setTime(9, 59),
        ]);

        $this->assertNotNull($this->opportunity->pendingOffer);
        $this->assertEquals($offer->id, $this->opportunity->pendingOffer->id);
    }

    public function test_opportunity_pending_offer_null_after_expired(): void
    {
        $offer = SlotOffer::create([
            'slot_request_id' => $this->request->id,
            'slot_opportunity_id' => $this->opportunity->id,
            'status' => SlotOfferStatus::Pending,
            'expires_at' => Carbon::tomorrow()->setTime(9, 59),
        ]);

        $offer->update([
            'status' => SlotOfferStatus::Expired,
            'expired_at' => now(),
        ]);

        $this->assertNull($this->opportunity->fresh()->pendingOffer);
        $this->assertCount(1, $this->opportunity->fresh()->offers);
    }

    // ── TTL config ────────────────────────────────────────

    public function test_offer_ttl_config_default(): void
    {
        $this->assertEquals(10, config('booking.autofill.offer_ttl_minutes'));
    }
}
