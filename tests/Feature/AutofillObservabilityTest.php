<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\SlotInvalidationReason;
use App\Enums\SlotOfferStatus;
use App\Enums\SlotOpportunitySourceType;
use App\Enums\SlotOpportunityStatus;
use App\Enums\SlotRequestDeliveryChannel;
use App\Enums\SlotRequestSource;
use App\Enums\SlotRequestStatus;
use App\Enums\SlotRequestType;
use App\Enums\SubscriptionStatus;
use App\Jobs\MatchSlotOpportunityJob;
use App\Jobs\SendMaxSlotOfferJob;
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
use App\Services\MaxApiClient;
use App\Services\SlotOfferAcceptanceService;
use App\Services\SlotOfferService;
use App\Services\SlotOpportunityService;
use App\Services\SlotMatcherService;
use App\Services\Booking\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class AutofillObservabilityTest extends TestCase
{
    use RefreshDatabase;

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

        $this->proPlan = TariffPlan::create([
            'code' => 'pro', 'name' => 'Профи', 'price_monthly' => 490,
            'features' => ['unlimited_appointments', 'client_management', 'channel_analytics', 'slot_autofill'],
            'is_active' => true,
        ]);

        $this->master = User::factory()->master()->create(['settings' => ['timezone' => 'Europe/Moscow']]);
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
            'max_id' => 'max_user_123',
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

        $this->sourceAppointment = Appointment::factory()
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
            'appointment_id' => $this->sourceAppointment->id,
            'master_service_id' => $this->masterService->id,
            'type' => SlotRequestType::Earlier,
            'request_source' => SlotRequestSource::Max,
            'delivery_channel' => SlotRequestDeliveryChannel::Max,
            'date_from' => Carbon::tomorrow()->toDateString(),
            'date_to' => Carbon::tomorrow()->toDateString(),
            'time_from' => '09:00',
            'time_to' => '18:00',
            'timezone' => 'Europe/Moscow',
            'appointment_start_time_snapshot' => $this->sourceAppointment->start_time,
            'expires_at' => Carbon::tomorrow()->setTime(13, 59),
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

        $this->offer = SlotOffer::create([
            'slot_request_id' => $this->request->id,
            'slot_opportunity_id' => $this->opportunity->id,
            'status' => SlotOfferStatus::Pending,
            'expires_at' => Carbon::tomorrow()->setTime(9, 59),
        ]);
    }

    // ── Send evidence ─────────────────────────────────────

    public function test_successful_send_persists_sent_at_and_delivery_mid(): void
    {
        $maxApi = Mockery::mock(MaxApiClient::class);
        $this->app->instance(MaxApiClient::class, $maxApi);
        $maxApi->shouldReceive('sendMessage')->once()->andReturn('msg_abc_123');

        $job = new SendMaxSlotOfferJob($this->offer->id);
        $job->handle($maxApi, app(SlotOfferService::class));

        $offer = $this->offer->fresh();
        $this->assertNotNull($offer->sent_at);
        $this->assertEquals('msg_abc_123', $offer->delivery_mid);
    }

    public function test_duplicate_job_with_sent_at_does_not_send_twice(): void
    {
        $this->offer->update(['sent_at' => now(), 'delivery_mid' => 'existing_mid']);

        $maxApi = Mockery::mock(MaxApiClient::class);
        $this->app->instance(MaxApiClient::class, $maxApi);
        $maxApi->shouldReceive('sendMessage')->never();

        $job = new SendMaxSlotOfferJob($this->offer->id);
        $job->handle($maxApi, app(SlotOfferService::class));

        $offer = $this->offer->fresh();
        $this->assertEquals('existing_mid', $offer->delivery_mid);
    }

    // ── Invalidation reasons ──────────────────────────────

    public function test_missing_max_id_invalidates_with_missing_max_identity(): void
    {
        $this->client->update(['max_id' => null]);

        $maxApi = Mockery::mock(MaxApiClient::class);
        $this->app->instance(MaxApiClient::class, $maxApi);
        $maxApi->shouldReceive('sendMessage')->never();

        $job = new SendMaxSlotOfferJob($this->offer->id);
        $job->handle($maxApi, app(SlotOfferService::class));

        $offer = $this->offer->fresh();
        $this->assertSame(SlotOfferStatus::Invalidated, $offer->status);
        $this->assertSame(SlotInvalidationReason::MissingMaxIdentity, $offer->invalidation_reason);
    }

    public function test_final_max_failure_invalidates_with_delivery_failed(): void
    {
        $job = new SendMaxSlotOfferJob($this->offer->id);
        $job->failed(new \Exception('transient'));

        $offer = $this->offer->fresh();
        $this->assertSame(SlotOfferStatus::Invalidated, $offer->status);
        $this->assertSame(SlotInvalidationReason::DeliveryFailed, $offer->invalidation_reason);
    }

    public function test_slot_taken_acceptance_records_reason(): void
    {
        $blocker = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
        ]);

        Appointment::factory()
            ->forMaster($this->master)
            ->forClient($blocker)
            ->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(10, 0),
                'duration' => 60,
            ]);

        $result = app(SlotOfferAcceptanceService::class)->acceptEarlier($this->offer);

        $this->assertFalse($result['success']);
        $this->assertEquals('slot_unavailable', $result['error']);

        $offer = $this->offer->fresh();
        $this->assertSame(SlotInvalidationReason::SlotUnavailable, $offer->invalidation_reason);

        $opp = $this->opportunity->fresh();
        $this->assertSame(SlotInvalidationReason::SlotUnavailable, $opp->invalidation_reason);
    }

    public function test_stale_request_acceptance_records_stale_request_reason(): void
    {
        $this->sourceAppointment->update(['status' => AppointmentStatus::Cancelled]);

        $result = app(SlotOfferAcceptanceService::class)->acceptEarlier($this->offer);

        $this->assertFalse($result['success']);

        $offer = $this->offer->fresh();
        $this->assertSame(SlotInvalidationReason::SourceChanged, $offer->invalidation_reason);
    }

    // ── Idempotent reason preservation ────────────────────

    public function test_idempotent_invalidate_does_not_overwrite_first_reason(): void
    {
        $offerService = app(SlotOfferService::class);
        $offerService->invalidate($this->offer, SlotInvalidationReason::MissingMaxIdentity);

        $offer = $this->offer->fresh();
        $this->assertSame(SlotInvalidationReason::MissingMaxIdentity, $offer->invalidation_reason);

        // Second call — idempotent, should not overwrite
        $offerService->invalidate($offer, SlotInvalidationReason::DeliveryFailed);

        $offer = $this->offer->fresh();
        $this->assertSame(SlotInvalidationReason::MissingMaxIdentity, $offer->invalidation_reason);
    }

    public function test_idempotent_opportunity_invalidate_preserves_reason(): void
    {
        $oppService = app(SlotOpportunityService::class);
        $oppService->invalidate($this->opportunity, SlotInvalidationReason::SlotUnavailable);

        $opp = $this->opportunity->fresh();
        $this->assertSame(SlotInvalidationReason::SlotUnavailable, $opp->invalidation_reason);

        // Second call — idempotent
        $oppService->invalidate($opp, SlotInvalidationReason::SlotTaken);

        $opp = $this->opportunity->fresh();
        $this->assertSame(SlotInvalidationReason::SlotUnavailable, $opp->invalidation_reason);
    }

    // ── Unsupported channel reason ────────────────────────

    public function test_unsupported_channel_invalidates_with_correct_reason(): void
    {
        $this->request->update(['delivery_channel' => SlotRequestDeliveryChannel::Telegram]);

        $maxApi = Mockery::mock(MaxApiClient::class);
        $this->app->instance(MaxApiClient::class, $maxApi);
        $maxApi->shouldReceive('sendMessage')->never();

        $job = new SendMaxSlotOfferJob($this->offer->id);
        $job->handle($maxApi, app(SlotOfferService::class));

        $offer = $this->offer->fresh();
        $this->assertSame(SlotInvalidationReason::UnsupportedDeliveryChannel, $offer->invalidation_reason);
    }

    // ── Existing lifecycle tests remain green ─────────────

    public function test_offer_still_pending_with_no_reason_when_valid(): void
    {
        $maxApi = Mockery::mock(MaxApiClient::class);
        $this->app->instance(MaxApiClient::class, $maxApi);
        $maxApi->shouldReceive('sendMessage')->once()->andReturn('msg_123');

        $job = new SendMaxSlotOfferJob($this->offer->id);
        $job->handle($maxApi, app(SlotOfferService::class));

        $offer = $this->offer->fresh();
        $this->assertSame(SlotOfferStatus::Pending, $offer->status);
        $this->assertNull($offer->invalidation_reason);
        $this->assertNotNull($offer->sent_at);
    }
}
