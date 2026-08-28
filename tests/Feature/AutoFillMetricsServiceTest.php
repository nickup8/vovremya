<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\SlotInvalidationReason;
use App\Enums\SlotOfferStatus;
use App\Enums\SlotOpportunitySourceType;
use App\Enums\SlotOpportunityStatus;
use App\Enums\SlotRequestDeliveryChannel;
use App\Enums\SlotRequestSource;
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
use App\Models\WorkingHour;
use App\Services\AutoFillMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutoFillMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    private AutoFillMetricsService $metrics;
    private User $master;
    private Workspace $ws;
    private Client $client;
    private MasterService $masterService;
    private Appointment $appointment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->metrics = app(AutoFillMetricsService::class);

        $proPlan = TariffPlan::create([
            'code' => 'pro', 'name' => 'Профи', 'price_monthly' => 490,
            'features' => ['unlimited_appointments', 'client_management', 'channel_analytics', 'slot_autofill'],
            'is_active' => true,
        ]);

        $this->master = User::factory()->master()->create(['settings' => ['timezone' => 'Europe/Moscow']]);
        $this->ws = Workspace::create(['name' => 'WS Test', 'owner_id' => $this->master->id]);
        $this->master->update(['workspace_id' => $this->ws->id, 'autofill_enabled' => true]);

        Subscription::create([
            'workspace_id' => $this->ws->id,
            'tariff_plan_id' => $proPlan->id,
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

        $this->appointment = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($this->client)
            ->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(14, 0),
                'duration' => 60,
            ]);
    }

    private int $apptCounter = 1;

    private function createRequest(?Carbon $createdAt = null): SlotRequest
    {
        $hour = 14 + ($this->apptCounter % 4);
        $this->apptCounter++;

        $appt = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($this->client)
            ->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime($hour, 0),
                'duration' => 60,
            ]);

        return SlotRequest::create([
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'client_id' => $this->client->id,
            'appointment_id' => $appt->id,
            'master_service_id' => $this->masterService->id,
            'type' => SlotRequestType::Earlier,
            'request_source' => SlotRequestSource::Max,
            'delivery_channel' => SlotRequestDeliveryChannel::Max,
            'date_from' => Carbon::tomorrow()->toDateString(),
            'date_to' => Carbon::tomorrow()->toDateString(),
            'time_from' => '09:00',
            'time_to' => '18:00',
            'timezone' => 'Europe/Moscow',
            'appointment_start_time_snapshot' => $appt->start_time,
            'expires_at' => Carbon::tomorrow()->setTime(13, 59),
            'created_at' => $createdAt ?? now(),
        ]);
    }

    private function createOpportunity(string $sourceType = 'cancellation', ?Carbon $createdAt = null, ?string $chainId = null): SlotOpportunity
    {
        return SlotOpportunity::create([
            'origin_event_id' => (string) Str::uuid(),
            'chain_id' => $chainId ?? (string) Str::uuid(),
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'master_service_id' => $this->masterService->id,
            'source_type' => $sourceType,
            'status' => SlotOpportunityStatus::Open,
            'start_time' => Carbon::tomorrow()->setTime(10, 0),
            'duration' => 60,
            'created_at' => $createdAt ?? now(),
        ]);
    }

    private function createOffer(SlotRequest $request, SlotOpportunity $opportunity, array $attrs = []): SlotOffer
    {
        return SlotOffer::create(array_merge([
            'slot_request_id' => $request->id,
            'slot_opportunity_id' => $opportunity->id,
            'status' => SlotOfferStatus::Pending,
            'expires_at' => Carbon::tomorrow()->setTime(9, 59),
        ], $attrs));
    }

    // ── Master/workspace isolation ────────────────────────

    public function test_master_isolation_excludes_other_master_data(): void
    {
        $otherMaster = User::factory()->master()->create();
        $otherWs = Workspace::create(['name' => 'Other', 'owner_id' => $otherMaster->id]);
        $otherMaster->update(['workspace_id' => $otherWs->id]);

        $otherClient = Client::factory()->create([
            'user_id' => $otherMaster->id,
            'workspace_id' => $otherWs->id,
        ]);

        $otherCatalog = ServiceCatalog::create([
            'workspace_id' => $otherWs->id,
            'title' => 'Other', 'base_price' => 1000, 'base_duration' => 60,
        ]);

        $otherMs = MasterService::create([
            'master_id' => $otherMaster->id,
            'catalog_id' => $otherCatalog->id,
            'is_active' => true,
        ]);

        $otherAppt = Appointment::factory()
            ->forMaster($otherMaster)
            ->forClient($otherClient)
            ->withMasterService($otherMs)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(14, 0),
                'duration' => 60,
            ]);

        $otherReq = SlotRequest::create([
            'workspace_id' => $otherWs->id,
            'master_id' => $otherMaster->id,
            'client_id' => $otherClient->id,
            'appointment_id' => $otherAppt->id,
            'master_service_id' => $otherMs->id,
            'type' => SlotRequestType::Earlier,
            'request_source' => SlotRequestSource::Max,
            'delivery_channel' => SlotRequestDeliveryChannel::Max,
            'date_from' => Carbon::tomorrow()->toDateString(),
            'date_to' => Carbon::tomorrow()->toDateString(),
            'time_from' => '09:00',
            'time_to' => '18:00',
            'timezone' => 'Europe/Moscow',
            'appointment_start_time_snapshot' => $otherAppt->start_time,
            'expires_at' => Carbon::tomorrow()->setTime(13, 59),
        ]);

        $from = Carbon::now()->subHour();
        $to = Carbon::now()->addHour();

        $result = $this->metrics->getMetrics($this->master, $from, $to);

        $this->assertEquals(0, $result['requests_created']);
    }

    // ── Period boundaries [from, to) ──────────────────────

    public function test_period_boundaries_half_open(): void
    {
        $from = Carbon::now()->startOfHour();
        $mid = $from->copy()->addMinutes(30);
        $to = $from->copy()->addHour();

        // Inside period
        $this->createRequest($mid);

        // Before period
        $reqBefore = $this->createRequest($from->copy()->subMinute());
        SlotRequest::where('id', $reqBefore->id)->update(['created_at' => $from->copy()->subMinute()]);

        // At exact `to` — excluded
        $reqAtTo = $this->createRequest($to);
        SlotRequest::where('id', $reqAtTo->id)->update(['created_at' => $to]);

        $result = $this->metrics->getMetrics($this->master, $from, $to);

        $this->assertEquals(1, $result['requests_created']);
    }

    // ── Opportunity source types ──────────────────────────

    public function test_opportunities_includes_all_three_source_types(): void
    {
        $from = Carbon::now()->subHour();
        $to = Carbon::now()->addHour();

        $this->createOpportunity('cancellation');
        $this->createOpportunity('reschedule');
        $this->createOpportunity('autofill_reschedule');

        $result = $this->metrics->getMetrics($this->master, $from, $to);

        $this->assertEquals(3, $result['opportunities_created']);
        $this->assertEquals(1, $result['autofill_reschedule_count']);
    }

    // ── Sent vs unsent ────────────────────────────────────

    public function test_sent_vs_unsent_correctly_counted(): void
    {
        $from = Carbon::now()->subHour();
        $to = Carbon::now()->addHour();

        $req = $this->createRequest();
        $opp = $this->createOpportunity();

        // Sent offer
        $this->createOffer($req, $opp, ['sent_at' => now(), 'delivery_mid' => 'mid_1']);

        // Unsent offer (different request+opportunity pair)
        $req2 = $this->createRequest();
        $opp2 = $this->createOpportunity();
        $this->createOffer($req2, $opp2);

        $result = $this->metrics->getMetrics($this->master, $from, $to);

        $this->assertEquals(2, $result['offers_created']);
        $this->assertEquals(1, $result['offers_sent']);
    }

    // ── Acceptance rate excludes invalidated ──────────────

    public function test_acceptance_rate_excludes_invalidated(): void
    {
        $from = Carbon::now()->subHour();
        $to = Carbon::now()->addHour();

        $req = $this->createRequest();
        $opp = $this->createOpportunity();
        $this->createOffer($req, $opp, ['status' => SlotOfferStatus::Accepted, 'accepted_at' => now()]);

        $req2 = $this->createRequest();
        $opp2 = $this->createOpportunity();
        $this->createOffer($req2, $opp2, ['status' => SlotOfferStatus::Declined, 'declined_at' => now()]);

        $req3 = $this->createRequest();
        $opp3 = $this->createOpportunity();
        $this->createOffer($req3, $opp3, [
            'status' => SlotOfferStatus::Invalidated,
            'invalidated_at' => now(),
            'invalidation_reason' => SlotInvalidationReason::DeliveryFailed,
        ]);

        $result = $this->metrics->getMetrics($this->master, $from, $to);

        // 1 accepted, 1 declined, 1 invalidated
        // acceptance_rate = 1 / (1 + 1 + 0) * 100 = 50%
        // invalidated excluded from denominator
        $this->assertEquals(50.0, $result['acceptance_rate']);
    }

    // ── Send rate + zero denominator ──────────────────────

    public function test_send_rate_and_zero_denominator(): void
    {
        $from = Carbon::now()->subHour();
        $to = Carbon::now()->addHour();

        // No offers → send_rate = 0
        $result = $this->metrics->getMetrics($this->master, $from, $to);
        $this->assertEquals(0.0, $result['send_rate']);

        // Add 2 offers, 1 sent
        $req = $this->createRequest();
        $opp = $this->createOpportunity();
        $this->createOffer($req, $opp, ['sent_at' => now(), 'delivery_mid' => 'mid_1']);

        $req2 = $this->createRequest();
        $opp2 = $this->createOpportunity();
        $this->createOffer($req2, $opp2);

        $result = $this->metrics->getMetrics($this->master, $from, $to);
        $this->assertEquals(50.0, $result['send_rate']);
    }

    // ── Median accept uses sent_at ────────────────────────

    public function test_median_accept_uses_sent_at_not_created_at(): void
    {
        $from = Carbon::now()->subHour();
        $to = Carbon::now()->addHour();

        $req = $this->createRequest();
        $opp = $this->createOpportunity();

        // Offer created at T, sent at T+100s, accepted at T+200s
        // sent_at → accepted_at = 100s
        // created_at → accepted_at = 200s (should NOT be used)
        $createdAt = now();
        $sentAt = $createdAt->copy()->addSeconds(100);
        $acceptedAt = $createdAt->copy()->addSeconds(200);

        $this->createOffer($req, $opp, [
            'status' => SlotOfferStatus::Accepted,
            'sent_at' => $sentAt,
            'accepted_at' => $acceptedAt,
            'created_at' => $createdAt,
        ]);

        $result = $this->metrics->getMetrics($this->master, $from, $to);

        $this->assertEquals(100, $result['median_time_to_accept_seconds']);
    }

    // ── Invalidation reasons including unknown ────────────

    public function test_invalidation_reasons_grouped_including_unknown(): void
    {
        $from = Carbon::now()->subHour();
        $to = Carbon::now()->addHour();

        $req = $this->createRequest();
        $opp = $this->createOpportunity();
        $this->createOffer($req, $opp, [
            'status' => SlotOfferStatus::Invalidated,
            'invalidated_at' => now(),
            'invalidation_reason' => SlotInvalidationReason::MissingMaxIdentity,
        ]);

        $req2 = $this->createRequest();
        $opp2 = $this->createOpportunity();
        $this->createOffer($req2, $opp2, [
            'status' => SlotOfferStatus::Invalidated,
            'invalidated_at' => now(),
            'invalidation_reason' => SlotInvalidationReason::MissingMaxIdentity,
        ]);

        $req3 = $this->createRequest();
        $opp3 = $this->createOpportunity();
        $this->createOffer($req3, $opp3, [
            'status' => SlotOfferStatus::Invalidated,
            'invalidated_at' => now(),
            'invalidation_reason' => null,
        ]);

        $result = $this->metrics->getMetrics($this->master, $from, $to);

        $this->assertEquals(2, $result['invalidations_by_reason']['missing_max_identity']);
        $this->assertEquals(1, $result['invalidations_by_reason']['unknown']);
    }

    // ── Chain aggregates ──────────────────────────────────

    public function test_chain_aggregates_correct(): void
    {
        $from = Carbon::now()->subHour();
        $to = Carbon::now()->addHour();

        $chainA = (string) Str::uuid();
        $chainB = (string) Str::uuid();

        // Chain A: 3 opportunities
        $this->createOpportunity('cancellation', null, $chainA);
        $this->createOpportunity('reschedule', null, $chainA);
        $this->createOpportunity('autofill_reschedule', null, $chainA);

        // Chain B: 1 opportunity
        $this->createOpportunity('cancellation', null, $chainB);

        $result = $this->metrics->getMetrics($this->master, $from, $to);

        $this->assertEquals(2, $result['chain_count']);
        $this->assertEquals(2.0, $result['average_opportunities_per_chain']);
        $this->assertEquals(3, $result['max_opportunities_per_chain']);
    }

    // ── No data → zeros + nullable timing ─────────────────

    public function test_no_data_returns_zeros_and_null_timing(): void
    {
        $from = Carbon::now()->subHour();
        $to = Carbon::now()->addHour();

        $result = $this->metrics->getMetrics($this->master, $from, $to);

        $this->assertEquals(0, $result['requests_created']);
        $this->assertEquals(0, $result['opportunities_created']);
        $this->assertEquals(0, $result['offers_created']);
        $this->assertEquals(0.0, $result['send_rate']);
        $this->assertEquals(0.0, $result['acceptance_rate']);
        $this->assertNull($result['median_time_to_accept_seconds']);
        $this->assertNull($result['opportunity_to_offer_median_seconds']);
        $this->assertEquals([], $result['invalidations_by_reason']);
        $this->assertEquals(0, $result['chain_count']);
    }

    // ── Filled window count ───────────────────────────────

    public function test_filled_window_count_tracks_filled_opportunities(): void
    {
        $from = Carbon::now()->subHour();
        $to = Carbon::now()->addHour();

        $opp1 = $this->createOpportunity('cancellation');
        $opp2 = $this->createOpportunity('cancellation');
        $opp2->update([
            'status' => SlotOpportunityStatus::Filled,
            'filled_at' => now(),
            'filled_by_appointment_id' => $this->appointment->id,
        ]);

        $result = $this->metrics->getMetrics($this->master, $from, $to);

        $this->assertEquals(2, $result['opportunities_created']);
        $this->assertEquals(1, $result['filled_window_count']);
    }
}
