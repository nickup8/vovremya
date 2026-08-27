<?php

namespace Tests\Feature;

use App\DTOs\AppointmentWindowFreed;
use App\Enums\AppointmentStatus;
use App\Enums\SlotOfferStatus;
use App\Enums\SlotOpportunitySourceType;
use App\Enums\SlotOpportunityStatus;
use App\Enums\SlotRequestDeliveryChannel;
use App\Enums\SlotRequestSource;
use App\Enums\SlotRequestType;
use App\Enums\SubscriptionStatus;
use App\Jobs\CreateSlotOpportunityJob;
use App\Jobs\ExpireSlotOfferJob;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutofillOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;
    private User $master;
    private Workspace $ws;
    private Client $client;
    private MasterService $masterService;
    private Appointment $sourceAppointment;
    private SlotRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();

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
    }

    private function createWindowFreed(): AppointmentWindowFreed
    {
        return new AppointmentWindowFreed(
            originEventId: (string) Str::uuid(),
            chainId: null,
            workspaceId: $this->ws->id,
            masterId: $this->master->id,
            masterServiceId: $this->masterService->id,
            sourceAppointmentId: $this->sourceAppointment->id,
            sourceType: SlotOpportunitySourceType::Cancellation,
            startTime: Carbon::tomorrow()->setTime(10, 0),
            duration: 60,
        );
    }

    // ── CreateSlotOpportunityJob → MatchSlotOpportunityJob ──

    public function test_create_opportunity_dispatches_match_job(): void
    {
        Bus::fake([MatchSlotOpportunityJob::class]);
        Bus::assertNothingDispatched();

        $job = new CreateSlotOpportunityJob($this->createWindowFreed());
        $job->handle(app(\App\Services\SlotOpportunityService::class));

        Bus::assertDispatched(MatchSlotOpportunityJob::class, function ($job) {
            return $job->slotOpportunityId !== null;
        });
    }

    public function test_no_opportunity_created_no_match_job(): void
    {
        // Past window → no opportunity created
        $pastWindow = new AppointmentWindowFreed(
            originEventId: (string) Str::uuid(),
            chainId: null,
            workspaceId: $this->ws->id,
            masterId: $this->master->id,
            masterServiceId: $this->masterService->id,
            sourceAppointmentId: $this->sourceAppointment->id,
            sourceType: SlotOpportunitySourceType::Cancellation,
            startTime: Carbon::yesterday()->setTime(10, 0),
            duration: 60,
        );

        $job = new CreateSlotOpportunityJob($pastWindow);
        $job->handle(app(\App\Services\SlotOpportunityService::class));

        Bus::assertNotDispatched(MatchSlotOpportunityJob::class);
    }

    // ── MatchSlotOpportunityJob → ExpireSlotOfferJob ──

    public function test_match_creates_offer_schedules_expiry(): void
    {
        // Create opportunity directly
        $opportunity = SlotOpportunity::create([
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

        // Run match job synchronously (Bus::fake is active, so dispatch is captured)
        $job = new MatchSlotOpportunityJob($opportunity->id);
        $job->handle(app(\App\Services\SlotMatcherService::class));

        // Offer should be created
        $this->assertDatabaseCount('slot_offers', 1);
        $offer = SlotOffer::first();
        $this->assertSame(SlotOfferStatus::Pending, $offer->status);

        // ExpireSlotOfferJob should be dispatched
        Bus::assertDispatched(ExpireSlotOfferJob::class, function ($job) use ($offer) {
            return $job->slotOfferId === $offer->id;
        });
    }

    public function test_match_returns_null_no_expiry_job(): void
    {
        // No eligible request → matcher returns null
        $this->request->update(['status' => \App\Enums\SlotRequestStatus::Cancelled]);

        $opportunity = SlotOpportunity::create([
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

        $job = new MatchSlotOpportunityJob($opportunity->id);
        $job->handle(app(\App\Services\SlotMatcherService::class));

        $this->assertDatabaseCount('slot_offers', 0);
        Bus::assertNotDispatched(ExpireSlotOfferJob::class);
    }

    // ── ExpireSlotOfferJob lifecycle ──

    public function test_expiry_transitions_and_remaches(): void
    {
        // Create opportunity and offer
        $opportunity = SlotOpportunity::create([
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

        $offer = SlotOffer::create([
            'slot_request_id' => $this->request->id,
            'slot_opportunity_id' => $opportunity->id,
            'status' => SlotOfferStatus::Pending,
            'expires_at' => Carbon::yesterday(), // already past
        ]);

        // Run expiry job
        $job = new ExpireSlotOfferJob($offer->id);
        $job->handle(app(\App\Services\SlotOfferService::class));

        // Offer should be expired
        $this->assertSame(SlotOfferStatus::Expired, $offer->fresh()->status);
        $this->assertNotNull($offer->fresh()->expired_at);

        // MatchSlotOpportunityJob should be dispatched for rematch
        Bus::assertDispatched(MatchSlotOpportunityJob::class, function ($job) use ($opportunity) {
            return $job->slotOpportunityId === $opportunity->id;
        });
    }

    public function test_non_pending_offer_expiry_does_nothing(): void
    {
        $opportunity = SlotOpportunity::create([
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

        // Already declined offer
        $offer = SlotOffer::create([
            'slot_request_id' => $this->request->id,
            'slot_opportunity_id' => $opportunity->id,
            'status' => SlotOfferStatus::Declined,
            'expires_at' => Carbon::yesterday(),
            'declined_at' => now(),
        ]);

        $job = new ExpireSlotOfferJob($offer->id);
        $job->handle(app(\App\Services\SlotOfferService::class));

        // Status unchanged
        $this->assertSame(SlotOfferStatus::Declined, $offer->fresh()->status);

        // No rematch dispatched
        Bus::assertNotDispatched(MatchSlotOpportunityJob::class);
    }

    public function test_duplicate_expiry_job_safe(): void
    {
        $opportunity = SlotOpportunity::create([
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

        $offer = SlotOffer::create([
            'slot_request_id' => $this->request->id,
            'slot_opportunity_id' => $opportunity->id,
            'status' => SlotOfferStatus::Pending,
            'expires_at' => Carbon::yesterday(),
        ]);

        // First expiry
        $job1 = new ExpireSlotOfferJob($offer->id);
        $job1->handle(app(\App\Services\SlotOfferService::class));

        $this->assertSame(SlotOfferStatus::Expired, $offer->fresh()->status);

        // Second expiry — idempotent, no crash, no extra rematch
        $job2 = new ExpireSlotOfferJob($offer->id);
        $job2->handle(app(\App\Services\SlotOfferService::class));

        $this->assertSame(SlotOfferStatus::Expired, $offer->fresh()->status);
    }

    // ── Duplicate match job ──

    public function test_duplicate_match_job_no_second_offer(): void
    {
        $opportunity = SlotOpportunity::create([
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

        $matcher = app(\App\Services\SlotMatcherService::class);

        // First match
        $offer1 = $matcher->matchOpportunity($opportunity);
        $this->assertNotNull($offer1);
        $this->assertDatabaseCount('slot_offers', 1);

        // Second match — should not create another offer
        $offer2 = $matcher->matchOpportunity($opportunity);
        $this->assertNull($offer2);
        $this->assertDatabaseCount('slot_offers', 1);
    }
}
