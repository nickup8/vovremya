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
use App\Services\SlotOfferService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SlotOfferTest extends TestCase
{
    use RefreshDatabase;

    private SlotOfferService $service;
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

        $this->service = new SlotOfferService();

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
    }

    private function futureExpiresAt(): \Carbon\CarbonImmutable
    {
        return Carbon::tomorrow()->setTime(9, 59)->toImmutable();
    }

    // ── Schema / Model ────────────────────────────────────

    public function test_pending_offer_created(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $this->assertNotNull($offer->id);
        $this->assertDatabaseHas('slot_offers', ['id' => $offer->id]);
    }

    public function test_default_status_is_pending(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $this->assertSame(SlotOfferStatus::Pending, $offer->status);
    }

    public function test_request_relation(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $this->assertEquals($this->request->id, $offer->request->id);
    }

    public function test_opportunity_relation(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $this->assertEquals($this->opportunity->id, $offer->opportunity->id);
    }

    public function test_status_cast(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $this->assertInstanceOf(SlotOfferStatus::class, $offer->status);
    }

    public function test_expires_at_cast(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $this->assertInstanceOf(\Carbon\CarbonImmutable::class, $offer->expires_at);
    }

    public function test_invalid_status_rejected_by_db(): void
    {
        $this->expectException(QueryException::class);

        DB::table('slot_offers')->insert([
            'id' => (string) Str::uuid(),
            'slot_request_id' => $this->request->id,
            'slot_opportunity_id' => $this->opportunity->id,
            'status' => 'bogus',
            'expires_at' => $this->futureExpiresAt(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ── Exclusivity ───────────────────────────────────────

    public function test_one_request_cannot_have_two_pending_offers(): void
    {
        $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $otherOpportunity = SlotOpportunity::create([
            'origin_event_id' => (string) Str::uuid(),
            'chain_id' => (string) Str::uuid(),
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'master_service_id' => $this->masterService->id,
            'source_type' => SlotOpportunitySourceType::Cancellation,
            'status' => SlotOpportunityStatus::Open,
            'start_time' => Carbon::tomorrow()->setTime(11, 0),
            'duration' => 60,
        ]);

        $this->expectException(QueryException::class);

        $this->service->createPending(
            $this->request,
            $otherOpportunity,
            $this->futureExpiresAt(),
        );
    }

    public function test_one_opportunity_cannot_have_two_pending_offers(): void
    {
        $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $otherClient = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
        ]);

        $otherAppointment = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($otherClient)
            ->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(15, 0),
                'duration' => 60,
            ]);

        $otherRequest = SlotRequest::create([
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'client_id' => $otherClient->id,
            'appointment_id' => $otherAppointment->id,
            'master_service_id' => $this->masterService->id,
            'type' => SlotRequestType::Earlier,
            'request_source' => SlotRequestSource::Web,
            'delivery_channel' => SlotRequestDeliveryChannel::Telegram,
            'date_from' => Carbon::tomorrow()->toDateString(),
            'date_to' => Carbon::tomorrow()->addDays(7)->toDateString(),
            'time_from' => '09:00',
            'time_to' => '18:00',
            'timezone' => 'Europe/Moscow',
            'appointment_start_time_snapshot' => $otherAppointment->start_time,
        ]);

        $this->expectException(QueryException::class);

        $this->service->createPending(
            $otherRequest,
            $this->opportunity,
            $this->futureExpiresAt(),
        );
    }

    public function test_after_decline_request_can_get_other_opportunity(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $this->service->decline($offer);

        $otherOpportunity = SlotOpportunity::create([
            'origin_event_id' => (string) Str::uuid(),
            'chain_id' => (string) Str::uuid(),
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'master_service_id' => $this->masterService->id,
            'source_type' => SlotOpportunitySourceType::Cancellation,
            'status' => SlotOpportunityStatus::Open,
            'start_time' => Carbon::tomorrow()->setTime(11, 0),
            'duration' => 60,
        ]);

        $newOffer = $this->service->createPending(
            $this->request,
            $otherOpportunity,
            $this->futureExpiresAt(),
        );

        $this->assertNotNull($newOffer->id);
        $this->assertNotEquals($offer->id, $newOffer->id);
    }

    public function test_after_decline_opportunity_can_be_offered_to_other_request(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $this->service->decline($offer);

        $otherClient = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
        ]);

        $otherAppointment = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($otherClient)
            ->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(15, 0),
                'duration' => 60,
            ]);

        $otherRequest = SlotRequest::create([
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'client_id' => $otherClient->id,
            'appointment_id' => $otherAppointment->id,
            'master_service_id' => $this->masterService->id,
            'type' => SlotRequestType::Earlier,
            'request_source' => SlotRequestSource::Web,
            'delivery_channel' => SlotRequestDeliveryChannel::Telegram,
            'date_from' => Carbon::tomorrow()->toDateString(),
            'date_to' => Carbon::tomorrow()->addDays(7)->toDateString(),
            'time_from' => '09:00',
            'time_to' => '18:00',
            'timezone' => 'Europe/Moscow',
            'appointment_start_time_snapshot' => $otherAppointment->start_time,
        ]);

        $newOffer = $this->service->createPending(
            $otherRequest,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $this->assertNotNull($newOffer->id);
    }

    public function test_after_expired_request_can_get_other_opportunity(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        // Move expires_at to past so expire() succeeds
        $offer->update(['expires_at' => Carbon::yesterday()]);
        $this->service->expire($offer->fresh());

        $otherOpportunity = SlotOpportunity::create([
            'origin_event_id' => (string) Str::uuid(),
            'chain_id' => (string) Str::uuid(),
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'master_service_id' => $this->masterService->id,
            'source_type' => SlotOpportunitySourceType::Cancellation,
            'status' => SlotOpportunityStatus::Open,
            'start_time' => Carbon::tomorrow()->addDay()->setTime(11, 0),
            'duration' => 60,
        ]);

        $newOffer = $this->service->createPending(
            $this->request,
            $otherOpportunity,
            Carbon::tomorrow()->addDay()->setTime(10, 59)->toImmutable(),
        );

        $this->assertNotNull($newOffer->id);
    }

    public function test_after_expired_opportunity_can_be_offered_to_other_request(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        // Move expires_at to past so expire() succeeds
        $offer->update(['expires_at' => Carbon::yesterday()]);
        $this->service->expire($offer->fresh());

        $otherClient = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
        ]);

        $otherAppointment = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($otherClient)
            ->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->addDay()->setTime(15, 0),
                'duration' => 60,
            ]);

        $otherRequest = SlotRequest::create([
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'client_id' => $otherClient->id,
            'appointment_id' => $otherAppointment->id,
            'master_service_id' => $this->masterService->id,
            'type' => SlotRequestType::Earlier,
            'request_source' => SlotRequestSource::Web,
            'delivery_channel' => SlotRequestDeliveryChannel::Telegram,
            'date_from' => Carbon::tomorrow()->addDay()->toDateString(),
            'date_to' => Carbon::tomorrow()->addDays(8)->toDateString(),
            'time_from' => '09:00',
            'time_to' => '18:00',
            'timezone' => 'Europe/Moscow',
            'appointment_start_time_snapshot' => $otherAppointment->start_time,
        ]);

        $newOffer = $this->service->createPending(
            $otherRequest,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $this->assertNotNull($newOffer->id);
    }

    // ── Exact pair ────────────────────────────────────────

    public function test_exact_pair_unique_exists(): void
    {
        $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        // Direct DB insert with same pair should fail
        $this->expectException(QueryException::class);

        SlotOffer::create([
            'slot_request_id' => $this->request->id,
            'slot_opportunity_id' => $this->opportunity->id,
            'status' => SlotOfferStatus::Pending,
            'expires_at' => $this->futureExpiresAt(),
        ]);
    }

    public function test_exact_same_pending_pair_same_expires_at_returns_same_offer(): void
    {
        $expiresAt = $this->futureExpiresAt();

        $offer1 = $this->service->createPending($this->request, $this->opportunity, $expiresAt);
        $offer2 = $this->service->createPending($this->request, $this->opportunity, $expiresAt);

        $this->assertEquals($offer1->id, $offer2->id);
    }

    public function test_exact_same_pending_pair_different_expires_at_throws(): void
    {
        $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $this->expectException(\DomainException::class);

        $this->service->createPending(
            $this->request,
            $this->opportunity,
            Carbon::tomorrow()->setTime(9, 30)->toImmutable(),
        );
    }

    public function test_declined_exact_pair_cannot_be_reoffered(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $this->service->decline($offer);

        $this->expectException(\DomainException::class);

        $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );
    }

    public function test_expired_exact_pair_cannot_be_reoffered(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        // Move expires_at to past so expire() succeeds
        $offer->update(['expires_at' => Carbon::yesterday()]);
        $this->service->expire($offer->fresh());

        $this->expectException(\DomainException::class);

        $this->service->createPending(
            $this->request,
            $this->opportunity,
            Carbon::tomorrow()->addDay()->setTime(9, 59)->toImmutable(),
        );
    }

    public function test_invalidated_exact_pair_cannot_be_reoffered(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $this->service->invalidate($offer);

        $this->expectException(\DomainException::class);

        $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );
    }

    // ── Create guards ─────────────────────────────────────

    public function test_inactive_request_rejected(): void
    {
        $this->request->update(['status' => SlotRequestStatus::Cancelled]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('not active');

        $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );
    }

    public function test_non_open_opportunity_rejected(): void
    {
        $this->opportunity->update(['status' => SlotOpportunityStatus::Filled]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('not open');

        $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );
    }

    public function test_workspace_mismatch_rejected(): void
    {
        $otherWs = Workspace::create(['name' => 'Other WS', 'owner_id' => $this->master->id]);

        $opp = SlotOpportunity::create([
            'origin_event_id' => (string) Str::uuid(),
            'chain_id' => (string) Str::uuid(),
            'workspace_id' => $otherWs->id,
            'master_id' => $this->master->id,
            'master_service_id' => $this->masterService->id,
            'source_type' => SlotOpportunitySourceType::Cancellation,
            'status' => SlotOpportunityStatus::Open,
            'start_time' => Carbon::tomorrow()->setTime(10, 0),
            'duration' => 60,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('different workspaces');

        $this->service->createPending($this->request, $opp, $this->futureExpiresAt());
    }

    public function test_master_mismatch_rejected(): void
    {
        $otherMaster = User::factory()->master()->create();
        $otherMaster->update(['workspace_id' => $this->ws->id]);

        $opp = SlotOpportunity::create([
            'origin_event_id' => (string) Str::uuid(),
            'chain_id' => (string) Str::uuid(),
            'workspace_id' => $this->ws->id,
            'master_id' => $otherMaster->id,
            'master_service_id' => $this->masterService->id,
            'source_type' => SlotOpportunitySourceType::Cancellation,
            'status' => SlotOpportunityStatus::Open,
            'start_time' => Carbon::tomorrow()->setTime(10, 0),
            'duration' => 60,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('different masters');

        $this->service->createPending($this->request, $opp, $this->futureExpiresAt());
    }

    public function test_master_service_mismatch_rejected(): void
    {
        $otherCatalog = ServiceCatalog::create([
            'workspace_id' => $this->ws->id,
            'title' => 'Стрижка',
            'base_price' => 1000,
            'base_duration' => 30,
        ]);

        $otherMs = MasterService::create([
            'master_id' => $this->master->id,
            'catalog_id' => $otherCatalog->id,
            'is_active' => true,
        ]);

        $opp = SlotOpportunity::create([
            'origin_event_id' => (string) Str::uuid(),
            'chain_id' => (string) Str::uuid(),
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'master_service_id' => $otherMs->id,
            'source_type' => SlotOpportunitySourceType::Cancellation,
            'status' => SlotOpportunityStatus::Open,
            'start_time' => Carbon::tomorrow()->setTime(10, 0),
            'duration' => 60,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('different master services');

        $this->service->createPending($this->request, $opp, $this->futureExpiresAt());
    }

    public function test_opportunity_start_time_in_past_rejected(): void
    {
        $opp = SlotOpportunity::create([
            'origin_event_id' => (string) Str::uuid(),
            'chain_id' => (string) Str::uuid(),
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'master_service_id' => $this->masterService->id,
            'source_type' => SlotOpportunitySourceType::Cancellation,
            'status' => SlotOpportunityStatus::Open,
            'start_time' => Carbon::yesterday()->setTime(10, 0),
            'duration' => 60,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('in the past');

        $this->service->createPending(
            $this->request,
            $opp,
            Carbon::now()->addMinutes(5)->toImmutable(),
        );
    }

    public function test_expires_at_in_past_rejected(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('in the future');

        $this->service->createPending(
            $this->request,
            $this->opportunity,
            Carbon::yesterday()->toImmutable(),
        );
    }

    public function test_expires_after_opportunity_start_rejected(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('not be after');

        $this->service->createPending(
            $this->request,
            $this->opportunity,
            Carbon::tomorrow()->setTime(14, 1)->toImmutable(),
        );
    }

    public function test_expires_at_equals_opportunity_start_allowed(): void
    {
        $expiresAt = $this->opportunity->start_time->toImmutable();

        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $expiresAt,
        );

        $this->assertNotNull($offer->id);
    }

    // ── Decline ───────────────────────────────────────────

    public function test_pending_to_declined(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $result = $this->service->decline($offer);

        $this->assertSame(SlotOfferStatus::Declined, $result->status);
    }

    public function test_declined_at_set(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $result = $this->service->decline($offer);

        $this->assertNotNull($result->declined_at);
    }

    public function test_decline_request_remains_active(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $this->service->decline($offer);

        $this->assertSame(SlotRequestStatus::Active, $this->request->fresh()->status);
    }

    public function test_decline_opportunity_remains_open(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $this->service->decline($offer);

        $this->assertSame(SlotOpportunityStatus::Open, $this->opportunity->fresh()->status);
    }

    public function test_decline_idempotent(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $result1 = $this->service->decline($offer);
        $result2 = $this->service->decline($result1);

        $this->assertEquals($result1->id, $result2->id);
        $this->assertSame(SlotOfferStatus::Declined, $result2->status);
    }

    public function test_accepted_cannot_be_declined(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        // Directly set to accepted (no public accept method)
        $offer->update([
            'status' => SlotOfferStatus::Accepted,
            'accepted_at' => now(),
        ]);

        $this->expectException(\DomainException::class);

        $this->service->decline($offer->fresh());
    }

    public function test_expired_cannot_be_declined(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        // Move expires_at to past so expire() succeeds
        $offer->update(['expires_at' => Carbon::yesterday()]);
        $this->service->expire($offer->fresh());

        $this->expectException(\DomainException::class);

        $this->service->decline($offer->fresh());
    }

    public function test_invalidated_cannot_be_declined(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $this->service->invalidate($offer);

        $this->expectException(\DomainException::class);

        $this->service->decline($offer->fresh());
    }

    // ── Expire ────────────────────────────────────────────

    public function test_pending_with_expired_expires_at_becomes_expired(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        // Move expires_at to past so expire() succeeds
        $offer->update(['expires_at' => Carbon::yesterday()]);

        $result = $this->service->expire($offer->fresh());

        $this->assertSame(SlotOfferStatus::Expired, $result->status);
    }

    public function test_expired_at_set(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        // Move expires_at to past so expire() succeeds
        $offer->update(['expires_at' => Carbon::yesterday()]);

        $result = $this->service->expire($offer->fresh());

        $this->assertNotNull($result->expired_at);
    }

    public function test_expire_request_remains_active(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        // Move expires_at to past so expire() succeeds
        $offer->update(['expires_at' => Carbon::yesterday()]);
        $this->service->expire($offer->fresh());

        $this->assertSame(SlotRequestStatus::Active, $this->request->fresh()->status);
    }

    public function test_expire_opportunity_remains_open(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        // Move expires_at to past so expire() succeeds
        $offer->update(['expires_at' => Carbon::yesterday()]);
        $this->service->expire($offer->fresh());

        $this->assertSame(SlotOpportunityStatus::Open, $this->opportunity->fresh()->status);
    }

    public function test_expire_before_expires_at_rejected(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('before its expires_at');

        $this->service->expire($offer);
    }

    public function test_expire_idempotent(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        // Move expires_at to past so expire() succeeds
        $offer->update(['expires_at' => Carbon::yesterday()]);

        $result1 = $this->service->expire($offer->fresh());
        $result2 = $this->service->expire($result1);

        $this->assertEquals($result1->id, $result2->id);
        $this->assertSame(SlotOfferStatus::Expired, $result2->status);
    }

    // ── Invalidate ────────────────────────────────────────

    public function test_pending_to_invalidated(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $result = $this->service->invalidate($offer);

        $this->assertSame(SlotOfferStatus::Invalidated, $result->status);
    }

    public function test_invalidated_at_set(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $result = $this->service->invalidate($offer);

        $this->assertNotNull($result->invalidated_at);
    }

    public function test_invalidate_request_remains_active(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $this->service->invalidate($offer);

        $this->assertSame(SlotRequestStatus::Active, $this->request->fresh()->status);
    }

    public function test_invalidate_opportunity_remains_open(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $this->service->invalidate($offer);

        $this->assertSame(SlotOpportunityStatus::Open, $this->opportunity->fresh()->status);
    }

    public function test_invalidate_idempotent(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $result1 = $this->service->invalidate($offer);
        $result2 = $this->service->invalidate($result1);

        $this->assertEquals($result1->id, $result2->id);
        $this->assertSame(SlotOfferStatus::Invalidated, $result2->status);
    }

    // ── Timestamp constraint ──────────────────────────────

    public function test_pending_with_declined_at_rejected_by_db(): void
    {
        $this->expectException(QueryException::class);

        SlotOffer::create([
            'slot_request_id' => $this->request->id,
            'slot_opportunity_id' => $this->opportunity->id,
            'status' => SlotOfferStatus::Pending,
            'expires_at' => $this->futureExpiresAt(),
            'declined_at' => now(),
        ]);
    }

    public function test_declined_without_declined_at_rejected_by_db(): void
    {
        $this->expectException(QueryException::class);

        SlotOffer::create([
            'slot_request_id' => $this->request->id,
            'slot_opportunity_id' => $this->opportunity->id,
            'status' => SlotOfferStatus::Declined,
            'expires_at' => $this->futureExpiresAt(),
        ]);
    }

    public function test_declined_with_accepted_at_rejected_by_db(): void
    {
        $this->expectException(QueryException::class);

        SlotOffer::create([
            'slot_request_id' => $this->request->id,
            'slot_opportunity_id' => $this->opportunity->id,
            'status' => SlotOfferStatus::Declined,
            'expires_at' => $this->futureExpiresAt(),
            'declined_at' => now(),
            'accepted_at' => now(),
        ]);
    }

    public function test_accepted_without_accepted_at_rejected_by_db(): void
    {
        $this->expectException(QueryException::class);

        SlotOffer::create([
            'slot_request_id' => $this->request->id,
            'slot_opportunity_id' => $this->opportunity->id,
            'status' => SlotOfferStatus::Accepted,
            'expires_at' => $this->futureExpiresAt(),
        ]);
    }

    public function test_expired_without_expired_at_rejected_by_db(): void
    {
        $this->expectException(QueryException::class);

        SlotOffer::create([
            'slot_request_id' => $this->request->id,
            'slot_opportunity_id' => $this->opportunity->id,
            'status' => SlotOfferStatus::Expired,
            'expires_at' => $this->futureExpiresAt(),
        ]);
    }

    public function test_invalidated_without_invalidated_at_rejected_by_db(): void
    {
        $this->expectException(QueryException::class);

        SlotOffer::create([
            'slot_request_id' => $this->request->id,
            'slot_opportunity_id' => $this->opportunity->id,
            'status' => SlotOfferStatus::Invalidated,
            'expires_at' => $this->futureExpiresAt(),
        ]);
    }

    // ── DB invariants ─────────────────────────────────────

    public function test_db_partial_unique_request_pending_enforced(): void
    {
        $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        // Decline first offer to free the partial index
        $offer = SlotOffer::first();
        $this->service->decline($offer);

        // Now create a new pending for same request with different opportunity
        $otherOpportunity = SlotOpportunity::create([
            'origin_event_id' => (string) Str::uuid(),
            'chain_id' => (string) Str::uuid(),
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'master_service_id' => $this->masterService->id,
            'source_type' => SlotOpportunitySourceType::Cancellation,
            'status' => SlotOpportunityStatus::Open,
            'start_time' => Carbon::tomorrow()->setTime(11, 0),
            'duration' => 60,
        ]);

        $newOffer = $this->service->createPending(
            $this->request,
            $otherOpportunity,
            $this->futureExpiresAt(),
        );

        $this->assertNotNull($newOffer->id);

        // Now try to create ANOTHER pending for same request — should fail
        $thirdOpportunity = SlotOpportunity::create([
            'origin_event_id' => (string) Str::uuid(),
            'chain_id' => (string) Str::uuid(),
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'master_service_id' => $this->masterService->id,
            'source_type' => SlotOpportunitySourceType::Cancellation,
            'status' => SlotOpportunityStatus::Open,
            'start_time' => Carbon::tomorrow()->setTime(12, 0),
            'duration' => 60,
        ]);

        $this->expectException(QueryException::class);

        $this->service->createPending(
            $this->request,
            $thirdOpportunity,
            $this->futureExpiresAt(),
        );
    }

    public function test_db_partial_unique_opportunity_pending_enforced(): void
    {
        $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        // Decline to free the partial index
        $offer = SlotOffer::first();
        $this->service->decline($offer);

        // Create new pending for same opportunity with different request
        $otherClient = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
        ]);

        $otherAppointment = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($otherClient)
            ->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(15, 0),
                'duration' => 60,
            ]);

        $otherRequest = SlotRequest::create([
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'client_id' => $otherClient->id,
            'appointment_id' => $otherAppointment->id,
            'master_service_id' => $this->masterService->id,
            'type' => SlotRequestType::Earlier,
            'request_source' => SlotRequestSource::Web,
            'delivery_channel' => SlotRequestDeliveryChannel::Telegram,
            'date_from' => Carbon::tomorrow()->toDateString(),
            'date_to' => Carbon::tomorrow()->addDays(7)->toDateString(),
            'time_from' => '09:00',
            'time_to' => '18:00',
            'timezone' => 'Europe/Moscow',
            'appointment_start_time_snapshot' => $otherAppointment->start_time,
        ]);

        $newOffer = $this->service->createPending(
            $otherRequest,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        $this->assertNotNull($newOffer->id);

        // Now try another pending for same opportunity — should fail
        $thirdClient = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
        ]);

        $thirdAppointment = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($thirdClient)
            ->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(16, 0),
                'duration' => 60,
            ]);

        $thirdRequest = SlotRequest::create([
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'client_id' => $thirdClient->id,
            'appointment_id' => $thirdAppointment->id,
            'master_service_id' => $this->masterService->id,
            'type' => SlotRequestType::Earlier,
            'request_source' => SlotRequestSource::Web,
            'delivery_channel' => SlotRequestDeliveryChannel::Telegram,
            'date_from' => Carbon::tomorrow()->toDateString(),
            'date_to' => Carbon::tomorrow()->addDays(7)->toDateString(),
            'time_from' => '09:00',
            'time_to' => '18:00',
            'timezone' => 'Europe/Moscow',
            'appointment_start_time_snapshot' => $thirdAppointment->start_time,
        ]);

        $this->expectException(QueryException::class);

        $this->service->createPending(
            $thirdRequest,
            $this->opportunity,
            $this->futureExpiresAt(),
        );
    }

    public function test_exact_pair_unique_enforced(): void
    {
        $offer = $this->service->createPending(
            $this->request,
            $this->opportunity,
            $this->futureExpiresAt(),
        );

        // Decline to make it non-pending
        $this->service->decline($offer);

        // Direct insert with same pair should still fail (UNIQUE, not partial)
        $this->expectException(QueryException::class);

        SlotOffer::create([
            'slot_request_id' => $this->request->id,
            'slot_opportunity_id' => $this->opportunity->id,
            'status' => SlotOfferStatus::Pending,
            'expires_at' => $this->futureExpiresAt(),
        ]);
    }
}
