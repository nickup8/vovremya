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
use App\Models\WorkingHour;
use App\Services\SlotMatcherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class SlotMatcherTest extends TestCase
{
    use RefreshDatabase;

    private SlotMatcherService $matcher;
    private TariffPlan $proPlan;
    private User $master;
    private Workspace $ws;
    private Client $client;
    private MasterService $masterService;
    private Appointment $sourceAppointment;
    private SlotRequest $request;
    private SlotOpportunity $opportunity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->matcher = app(SlotMatcherService::class);

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

        // EARLIER request: wants earlier slot before source appointment
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

        // Opportunity: tomorrow 10:00, duration 60 (fits before source at 14:00)
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

    // ── Opportunity guards ────────────────────────────────

    public function test_valid_match_creates_pending_offer(): void
    {
        $offer = $this->matcher->matchOpportunity($this->opportunity);

        $this->assertNotNull($offer);
        $this->assertSame(SlotOfferStatus::Pending, $offer->status);
        $this->assertEquals($this->request->id, $offer->slot_request_id);
        $this->assertEquals($this->opportunity->id, $offer->slot_opportunity_id);
    }

    public function test_non_open_opportunity_returns_null(): void
    {
        $this->opportunity->update(['status' => SlotOpportunityStatus::Filled]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));
    }

    public function test_past_opportunity_expires_and_returns_null(): void
    {
        $this->opportunity->update(['start_time' => Carbon::yesterday()->setTime(10, 0)]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        $this->assertSame(SlotOpportunityStatus::Expired, $this->opportunity->fresh()->status);
    }

    public function test_unavailable_opportunity_invalidates_and_returns_null(): void
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

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        $this->assertSame(SlotOpportunityStatus::Invalidated, $this->opportunity->fresh()->status);
    }

    public function test_autofill_disabled_returns_null(): void
    {
        $this->master->update(['autofill_enabled' => false]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        // Opportunity remains Open
        $this->assertSame(SlotOpportunityStatus::Open, $this->opportunity->fresh()->status);
    }

    public function test_start_plan_returns_null(): void
    {
        Subscription::where('workspace_id', $this->ws->id)->update(['expires_at' => now()->subDay()]);

        $startPlan = TariffPlan::create([
            'code' => 'start', 'name' => 'Старт', 'price_monthly' => 0,
            'features' => ['calendar', 'basic_client_management'], 'is_active' => true,
        ]);

        Subscription::create([
            'workspace_id' => $this->ws->id,
            'tariff_plan_id' => $startPlan->id,
            'period_months' => 1, 'amount_paid' => 0,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(), 'expires_at' => now()->addMonth(),
        ]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        $this->assertSame(SlotOpportunityStatus::Open, $this->opportunity->fresh()->status);
    }

    public function test_inactive_master_service_returns_null(): void
    {
        $this->masterService->update(['is_active' => false]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        $this->assertSame(SlotOpportunityStatus::Open, $this->opportunity->fresh()->status);
    }

    public function test_opportunity_already_has_pending_offer_returns_null(): void
    {
        // Create first offer
        $offer = $this->matcher->matchOpportunity($this->opportunity);
        $this->assertNotNull($offer);

        // Second match attempt should return null
        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));
    }

    // ── Stale request expiration ──────────────────────────

    public function test_null_appointment_id_expires_request(): void
    {
        $this->request->update(['appointment_id' => null]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        $this->assertSame(SlotRequestStatus::Expired, $this->request->fresh()->status);
    }

    public function test_missing_source_appointment_expires_request(): void
    {
        $this->sourceAppointment->delete();

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        $this->assertSame(SlotRequestStatus::Expired, $this->request->fresh()->status);
    }

    public function test_source_not_booked_expires_request(): void
    {
        $this->sourceAppointment->update(['status' => AppointmentStatus::Cancelled]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        $this->assertSame(SlotRequestStatus::Expired, $this->request->fresh()->status);
    }

    public function test_source_past_expires_request(): void
    {
        $this->sourceAppointment->update(['start_time' => Carbon::yesterday()->setTime(14, 0)]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        $this->assertSame(SlotRequestStatus::Expired, $this->request->fresh()->status);
    }

    public function test_client_mismatch_expires_request(): void
    {
        $otherClient = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
        ]);
        $this->sourceAppointment->update(['client_id' => $otherClient->id]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        $this->assertSame(SlotRequestStatus::Expired, $this->request->fresh()->status);
    }

    public function test_workspace_mismatch_expires_request(): void
    {
        // Change master's workspace AFTER request creation to create mismatch
        $otherWs = Workspace::create(['name' => 'Other', 'owner_id' => $this->master->id]);
        $this->master->update(['workspace_id' => $otherWs->id]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        // No offer should be created
        $this->assertDatabaseCount('slot_offers', 0);
    }

    public function test_master_mismatch_expires_request(): void
    {
        $otherMaster = User::factory()->master()->create();
        $otherMaster->update(['workspace_id' => $this->ws->id]);
        $this->sourceAppointment->update(['master_id' => $otherMaster->id]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        $this->assertSame(SlotRequestStatus::Expired, $this->request->fresh()->status);
    }

    public function test_master_service_mismatch_expires_request(): void
    {
        $otherCatalog = ServiceCatalog::create([
            'workspace_id' => $this->ws->id, 'title' => 'Other', 'base_price' => 1000, 'base_duration' => 30,
        ]);
        $otherMs = MasterService::create([
            'master_id' => $this->master->id, 'catalog_id' => $otherCatalog->id, 'is_active' => true,
        ]);
        $this->sourceAppointment->update(['master_service_id' => $otherMs->id]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        $this->assertSame(SlotRequestStatus::Expired, $this->request->fresh()->status);
    }

    public function test_snapshot_drift_expires_request(): void
    {
        $this->sourceAppointment->update(['start_time' => Carbon::tomorrow()->setTime(15, 0)]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        $this->assertSame(SlotRequestStatus::Expired, $this->request->fresh()->status);
    }

    public function test_source_duration_null_expires_request(): void
    {
        $this->sourceAppointment->update(['duration' => null]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        $this->assertSame(SlotRequestStatus::Expired, $this->request->fresh()->status);
    }

    public function test_source_duration_zero_expires_request(): void
    {
        $this->sourceAppointment->update(['duration' => 0]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        $this->assertSame(SlotRequestStatus::Expired, $this->request->fresh()->status);
    }

    public function test_request_expires_at_past_expires_request(): void
    {
        $this->request->update(['expires_at' => Carbon::yesterday()]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        $this->assertSame(SlotRequestStatus::Expired, $this->request->fresh()->status);
    }

    public function test_stale_first_valid_second_creates_offer(): void
    {
        // Create a second client with a valid request
        $client2 = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
        ]);

        $appointment2 = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client2)
            ->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(15, 0),
                'duration' => 60,
            ]);

        $request2 = SlotRequest::create([
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'client_id' => $client2->id,
            'appointment_id' => $appointment2->id,
            'master_service_id' => $this->masterService->id,
            'type' => SlotRequestType::Earlier,
            'request_source' => SlotRequestSource::Web,
            'delivery_channel' => SlotRequestDeliveryChannel::Telegram,
            'date_from' => Carbon::tomorrow()->toDateString(),
            'date_to' => Carbon::tomorrow()->toDateString(),
            'time_from' => '09:00',
            'time_to' => '18:00',
            'timezone' => 'Europe/Moscow',
            'appointment_start_time_snapshot' => $appointment2->start_time,
            'expires_at' => Carbon::tomorrow()->setTime(14, 59),
        ]);

        // Make first request stale
        $this->sourceAppointment->update(['status' => AppointmentStatus::Cancelled]);

        $offer = $this->matcher->matchOpportunity($this->opportunity);

        $this->assertNotNull($offer);
        $this->assertEquals($request2->id, $offer->slot_request_id);
        $this->assertSame(SlotRequestStatus::Expired, $this->request->fresh()->status);
    }

    // ── Duration compatibility ────────────────────────────

    public function test_exact_duration_match_creates_offer(): void
    {
        $offer = $this->matcher->matchOpportunity($this->opportunity);

        $this->assertNotNull($offer);
    }

    public function test_source_duration_less_than_opportunity_skipped(): void
    {
        $this->sourceAppointment->update(['duration' => 30]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        // Request remains Active (not expired)
        $this->assertSame(SlotRequestStatus::Active, $this->request->fresh()->status);
    }

    public function test_source_duration_greater_than_opportunity_skipped(): void
    {
        $this->sourceAppointment->update(['duration' => 90]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        $this->assertSame(SlotRequestStatus::Active, $this->request->fresh()->status);
    }

    public function test_opportunity_ends_exactly_at_source_start_eligible(): void
    {
        // Opportunity: 13:00-14:00, Source: 14:00
        $this->opportunity->update(['start_time' => Carbon::tomorrow()->setTime(13, 0)]);

        $offer = $this->matcher->matchOpportunity($this->opportunity);

        $this->assertNotNull($offer);
    }

    public function test_opportunity_ends_after_source_start_skipped(): void
    {
        // Opportunity: 13:30-14:30, Source: 14:00
        $this->opportunity->update(['start_time' => Carbon::tomorrow()->setTime(13, 30)]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        $this->assertSame(SlotRequestStatus::Active, $this->request->fresh()->status);
    }

    public function test_same_day_earlier_valid(): void
    {
        // Both on same day, opportunity before source
        $offer = $this->matcher->matchOpportunity($this->opportunity);

        $this->assertNotNull($offer);
    }

    // ── Hard date/time bounds ─────────────────────────────

    public function test_before_date_from_skipped(): void
    {
        // Request only valid day after tomorrow — opportunity is tomorrow
        $dayAfter = Carbon::tomorrow()->addDay()->toDateString();
        $this->request->update(['date_from' => $dayAfter, 'date_to' => $dayAfter]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        $this->assertSame(SlotRequestStatus::Active, $this->request->fresh()->status);
    }

    public function test_after_date_to_skipped(): void
    {
        // Request only valid yesterday — opportunity is tomorrow
        $yesterday = Carbon::yesterday()->toDateString();
        \DB::table('slot_requests')->where('id', $this->request->id)->update([
            'date_from' => $yesterday,
            'date_to' => $yesterday,
        ]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        $this->assertSame(SlotRequestStatus::Active, $this->request->fresh()->status);
    }

    public function test_exact_date_bounds_eligible(): void
    {
        $offer = $this->matcher->matchOpportunity($this->opportunity);

        $this->assertNotNull($offer);
    }

    public function test_start_before_time_from_skipped(): void
    {
        // Opportunity at 05:00 UTC = 08:00 Moscow, request time_from 09:00 Moscow
        $this->opportunity->update(['start_time' => Carbon::tomorrow()->setTime(5, 0)]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        $this->assertSame(SlotRequestStatus::Active, $this->request->fresh()->status);
    }

    public function test_end_after_time_to_skipped(): void
    {
        // Opportunity at 17:30, ends 18:30, request time_to 18:00
        $this->opportunity->update(['start_time' => Carbon::tomorrow()->setTime(17, 30)]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        $this->assertSame(SlotRequestStatus::Active, $this->request->fresh()->status);
    }

    public function test_start_equals_time_from_end_equals_time_to_eligible(): void
    {
        // Request: 10:00-11:00 Moscow, Opportunity: 07:00 UTC = 10:00 Moscow
        $this->request->update(['time_from' => '10:00', 'time_to' => '11:00']);
        $this->opportunity->update(['start_time' => Carbon::tomorrow()->setTime(7, 0)]);

        $offer = $this->matcher->matchOpportunity($this->opportunity);

        $this->assertNotNull($offer);
    }

    public function test_full_day_window_eligible(): void
    {
        $this->request->update(['time_from' => '00:00', 'time_to' => '23:59']);

        $offer = $this->matcher->matchOpportunity($this->opportunity);

        $this->assertNotNull($offer);
    }

    public function test_cross_midnight_opportunity_skipped(): void
    {
        // Opportunity at 23:30, duration 60 → ends next day
        $this->opportunity->update(['start_time' => Carbon::tomorrow()->setTime(23, 30)]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        $this->assertSame(SlotRequestStatus::Active, $this->request->fresh()->status);
    }

    public function test_request_timezone_affects_eligibility(): void
    {
        // Request in UTC+5 (Yekaterinburg), time_from 11:00, time_to 18:00
        // Opportunity at 06:00 UTC = 09:00 Moscow (within working hours) = 11:00 Yekaterinburg
        $this->request->update(['timezone' => 'Asia/Yekaterinburg', 'time_from' => '11:00', 'time_to' => '18:00']);
        $this->opportunity->update(['start_time' => Carbon::tomorrow()->setTime(6, 0)]);

        $offer = $this->matcher->matchOpportunity($this->opportunity);

        $this->assertNotNull($offer);
    }

    // ── Client conflict ───────────────────────────────────

    public function test_overlapping_booked_same_client_skipped(): void
    {
        // Client has another booked appointment at 10:00 with different master
        $otherMaster = User::factory()->master()->create();
        $otherMaster->update(['workspace_id' => $this->ws->id]);

        Appointment::factory()
            ->forMaster($otherMaster)
            ->forClient($this->client)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(10, 0),
                'duration' => 60,
            ]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        // Request remains Active
        $this->assertSame(SlotRequestStatus::Active, $this->request->fresh()->status);
    }

    public function test_overlapping_pending_payment_skipped(): void
    {
        $otherMaster = User::factory()->master()->create();
        $otherMaster->update(['workspace_id' => $this->ws->id]);

        Appointment::factory()
            ->forMaster($otherMaster)
            ->forClient($this->client)
            ->create([
                'status' => AppointmentStatus::PendingPayment,
                'start_time' => Carbon::tomorrow()->setTime(10, 0),
                'duration' => 60,
            ]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));
    }

    public function test_overlapping_prepaid_skipped(): void
    {
        $otherMaster = User::factory()->master()->create();
        $otherMaster->update(['workspace_id' => $this->ws->id]);

        Appointment::factory()
            ->forMaster($otherMaster)
            ->forClient($this->client)
            ->create([
                'status' => AppointmentStatus::Prepaid,
                'start_time' => Carbon::tomorrow()->setTime(10, 0),
                'duration' => 60,
            ]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));
    }

    public function test_overlapping_paid_skipped(): void
    {
        $otherMaster = User::factory()->master()->create();
        $otherMaster->update(['workspace_id' => $this->ws->id]);

        Appointment::factory()
            ->forMaster($otherMaster)
            ->forClient($this->client)
            ->create([
                'status' => AppointmentStatus::Paid,
                'start_time' => Carbon::tomorrow()->setTime(10, 0),
                'duration' => 60,
            ]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));
    }

    public function test_overlapping_cancelled_does_not_block(): void
    {
        $otherMaster = User::factory()->master()->create();
        $otherMaster->update(['workspace_id' => $this->ws->id]);

        Appointment::factory()
            ->forMaster($otherMaster)
            ->forClient($this->client)
            ->create([
                'status' => AppointmentStatus::Cancelled,
                'start_time' => Carbon::tomorrow()->setTime(10, 0),
                'duration' => 60,
            ]);

        $offer = $this->matcher->matchOpportunity($this->opportunity);

        $this->assertNotNull($offer);
    }

    public function test_overlapping_no_show_does_not_block(): void
    {
        $otherMaster = User::factory()->master()->create();
        $otherMaster->update(['workspace_id' => $this->ws->id]);

        Appointment::factory()
            ->forMaster($otherMaster)
            ->forClient($this->client)
            ->create([
                'status' => AppointmentStatus::NoShow,
                'start_time' => Carbon::tomorrow()->setTime(10, 0),
                'duration' => 60,
            ]);

        $offer = $this->matcher->matchOpportunity($this->opportunity);

        $this->assertNotNull($offer);
    }

    public function test_overlapping_different_master_still_skipped(): void
    {
        $otherMaster = User::factory()->master()->create();
        $otherMaster->update(['workspace_id' => $this->ws->id]);

        Appointment::factory()
            ->forMaster($otherMaster)
            ->forClient($this->client)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(10, 0),
                'duration' => 60,
            ]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));
    }

    public function test_non_overlapping_does_not_block(): void
    {
        $otherMaster = User::factory()->master()->create();
        $otherMaster->update(['workspace_id' => $this->ws->id]);

        Appointment::factory()
            ->forMaster($otherMaster)
            ->forClient($this->client)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(8, 0),
                'duration' => 60,
            ]);

        $offer = $this->matcher->matchOpportunity($this->opportunity);

        $this->assertNotNull($offer);
    }

    // ── Prior offers / pending ────────────────────────────

    public function test_request_has_pending_for_other_opportunity_skipped(): void
    {
        $otherOpp = SlotOpportunity::create([
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

        SlotOffer::create([
            'slot_request_id' => $this->request->id,
            'slot_opportunity_id' => $otherOpp->id,
            'status' => SlotOfferStatus::Pending,
            'expires_at' => Carbon::tomorrow()->setTime(10, 59),
        ]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));
    }

    public function test_declined_exact_pair_skipped(): void
    {
        $offer = SlotOffer::create([
            'slot_request_id' => $this->request->id,
            'slot_opportunity_id' => $this->opportunity->id,
            'status' => SlotOfferStatus::Declined,
            'expires_at' => Carbon::tomorrow()->setTime(9, 59),
            'declined_at' => now(),
        ]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        // Request remains Active
        $this->assertSame(SlotRequestStatus::Active, $this->request->fresh()->status);
    }

    public function test_expired_exact_pair_skipped(): void
    {
        SlotOffer::create([
            'slot_request_id' => $this->request->id,
            'slot_opportunity_id' => $this->opportunity->id,
            'status' => SlotOfferStatus::Expired,
            'expires_at' => Carbon::yesterday(),
            'expired_at' => now(),
        ]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));
    }

    public function test_invalidated_exact_pair_skipped(): void
    {
        SlotOffer::create([
            'slot_request_id' => $this->request->id,
            'slot_opportunity_id' => $this->opportunity->id,
            'status' => SlotOfferStatus::Invalidated,
            'expires_at' => Carbon::tomorrow()->setTime(9, 59),
            'invalidated_at' => now(),
        ]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));
    }

    public function test_prior_exact_pair_second_candidate_gets_offer(): void
    {
        // Create second client/request
        $client2 = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
        ]);

        $appointment2 = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client2)
            ->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(15, 0),
                'duration' => 60,
            ]);

        $request2 = SlotRequest::create([
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'client_id' => $client2->id,
            'appointment_id' => $appointment2->id,
            'master_service_id' => $this->masterService->id,
            'type' => SlotRequestType::Earlier,
            'request_source' => SlotRequestSource::Web,
            'delivery_channel' => SlotRequestDeliveryChannel::Telegram,
            'date_from' => Carbon::tomorrow()->toDateString(),
            'date_to' => Carbon::tomorrow()->toDateString(),
            'time_from' => '09:00',
            'time_to' => '18:00',
            'timezone' => 'Europe/Moscow',
            'appointment_start_time_snapshot' => $appointment2->start_time,
            'expires_at' => Carbon::tomorrow()->setTime(14, 59),
        ]);

        // First request has prior declined exact pair
        SlotOffer::create([
            'slot_request_id' => $this->request->id,
            'slot_opportunity_id' => $this->opportunity->id,
            'status' => SlotOfferStatus::Declined,
            'expires_at' => Carbon::tomorrow()->setTime(9, 59),
            'declined_at' => now(),
        ]);

        $offer = $this->matcher->matchOpportunity($this->opportunity);

        $this->assertNotNull($offer);
        $this->assertEquals($request2->id, $offer->slot_request_id);
    }

    // ── Ranking ───────────────────────────────────────────

    public function test_closer_source_appointment_wins(): void
    {
        // Client A: source at 11:00 (closer to opp at 10:00)
        $clientA = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
        ]);

        $appointmentA = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($clientA)
            ->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(11, 0),
                'duration' => 60,
            ]);

        $requestA = SlotRequest::create([
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'client_id' => $clientA->id,
            'appointment_id' => $appointmentA->id,
            'master_service_id' => $this->masterService->id,
            'type' => SlotRequestType::Earlier,
            'request_source' => SlotRequestSource::Web,
            'delivery_channel' => SlotRequestDeliveryChannel::Telegram,
            'date_from' => Carbon::tomorrow()->toDateString(),
            'date_to' => Carbon::tomorrow()->toDateString(),
            'time_from' => '09:00',
            'time_to' => '18:00',
            'timezone' => 'Europe/Moscow',
            'appointment_start_time_snapshot' => $appointmentA->start_time,
            'expires_at' => Carbon::tomorrow()->setTime(10, 59),
        ]);

        // Client B: source at 15:00 (farther)
        $clientB = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
        ]);

        $appointmentB = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($clientB)
            ->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(15, 0),
                'duration' => 60,
            ]);

        $requestB = SlotRequest::create([
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'client_id' => $clientB->id,
            'appointment_id' => $appointmentB->id,
            'master_service_id' => $this->masterService->id,
            'type' => SlotRequestType::Earlier,
            'request_source' => SlotRequestSource::Web,
            'delivery_channel' => SlotRequestDeliveryChannel::Telegram,
            'date_from' => Carbon::tomorrow()->toDateString(),
            'date_to' => Carbon::tomorrow()->toDateString(),
            'time_from' => '09:00',
            'time_to' => '18:00',
            'timezone' => 'Europe/Moscow',
            'appointment_start_time_snapshot' => $appointmentB->start_time,
            'expires_at' => Carbon::tomorrow()->setTime(14, 59),
        ]);

        // Remove original request (it has same source as A)
        $this->request->update(['status' => SlotRequestStatus::Cancelled]);

        $offer = $this->matcher->matchOpportunity($this->opportunity);

        $this->assertNotNull($offer);
        $this->assertEquals($requestA->id, $offer->slot_request_id);
    }

    public function test_same_source_start_older_request_wins(): void
    {
        // Use non-overlapping source appointments: 11:00 and 12:00
        // Both are before opportunity (10:00), so both are eligible.
        // Ranking by source start: 11:00 wins over 12:00.

        $client2 = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
        ]);

        $appointment2 = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client2)
            ->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(12, 0),
                'duration' => 60,
            ]);

        $request2 = SlotRequest::create([
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'client_id' => $client2->id,
            'appointment_id' => $appointment2->id,
            'master_service_id' => $this->masterService->id,
            'type' => SlotRequestType::Earlier,
            'request_source' => SlotRequestSource::Web,
            'delivery_channel' => SlotRequestDeliveryChannel::Telegram,
            'date_from' => Carbon::tomorrow()->toDateString(),
            'date_to' => Carbon::tomorrow()->toDateString(),
            'time_from' => '09:00',
            'time_to' => '18:00',
            'timezone' => 'Europe/Moscow',
            'appointment_start_time_snapshot' => $appointment2->start_time,
            'expires_at' => Carbon::tomorrow()->setTime(11, 59),
        ]);

        // Also create a request with source at 11:00 (closer to opportunity at 10:00)
        $client3 = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
        ]);

        $appointment3 = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client3)
            ->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(11, 0),
                'duration' => 60,
            ]);

        $request3 = SlotRequest::create([
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'client_id' => $client3->id,
            'appointment_id' => $appointment3->id,
            'master_service_id' => $this->masterService->id,
            'type' => SlotRequestType::Earlier,
            'request_source' => SlotRequestSource::Web,
            'delivery_channel' => SlotRequestDeliveryChannel::Telegram,
            'date_from' => Carbon::tomorrow()->toDateString(),
            'date_to' => Carbon::tomorrow()->toDateString(),
            'time_from' => '09:00',
            'time_to' => '18:00',
            'timezone' => 'Europe/Moscow',
            'appointment_start_time_snapshot' => $appointment3->start_time,
            'expires_at' => Carbon::tomorrow()->setTime(10, 59),
        ]);

        // Force request3 to have later created_at (so request2 would win if same source)
        \DB::table('slot_requests')
            ->where('id', $request3->id)
            ->update(['created_at' => now()->addMinute()]);

        // Cancel original request
        $this->request->update(['status' => SlotRequestStatus::Cancelled]);

        $offer = $this->matcher->matchOpportunity($this->opportunity);

        // request3 should win: source at 11:00 (closer) vs request2 at 12:00
        $this->assertNotNull($offer);
        $this->assertEquals($request3->id, $offer->slot_request_id);
    }

    // ── Offer TTL ─────────────────────────────────────────

    public function test_normal_ttl_expires_at(): void
    {
        $offer = $this->matcher->matchOpportunity($this->opportunity);

        $this->assertNotNull($offer);

        $ttlMinutes = (int) config('booking.autofill.offer_ttl_minutes', 10);
        $expectedDeadline = now()->addMinutes($ttlMinutes);

        // expires_at should be close to now + TTL (within 1 minute tolerance)
        $this->assertTrue(
            $offer->expires_at->diffInSeconds($expectedDeadline) < 60,
            "expires_at should be approximately now + {$ttlMinutes} minutes"
        );
    }

    public function test_opportunity_starts_in_5_minutes_expires_at_uses_opportunity(): void
    {
        // Use the same setup as test_valid_match_creates_pending_offer (which passes)
        // but with a request expires_at that's shorter than TTL
        $requestExpiry = Carbon::now()->addMinutes(3);
        $this->request->update(['expires_at' => $requestExpiry]);

        $offer = $this->matcher->matchOpportunity($this->opportunity);

        $this->assertNotNull($offer);

        // expires_at should be request.expires_at (shorter than TTL=10min and opportunity.start_time=tomorrow)
        $this->assertEquals(
            $requestExpiry->format('Y-m-d H:i:s'),
            $offer->expires_at->format('Y-m-d H:i:s'),
        );
    }

    public function test_request_expires_in_3_minutes_expires_at_uses_request(): void
    {
        $requestExpiry = Carbon::now()->addMinutes(3);
        $this->request->update(['expires_at' => $requestExpiry]);

        $offer = $this->matcher->matchOpportunity($this->opportunity);

        $this->assertNotNull($offer);

        // expires_at should be request.expires_at (shorter than TTL)
        $this->assertEquals(
            $requestExpiry->format('Y-m-d H:i:s'),
            $offer->expires_at->format('Y-m-d H:i:s'),
        );
    }

    public function test_request_expiry_during_iteration_skips_to_next(): void
    {
        // Create second client/request
        $client2 = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
        ]);

        $appointment2 = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client2)
            ->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(15, 0),
                'duration' => 60,
            ]);

        $request2 = SlotRequest::create([
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'client_id' => $client2->id,
            'appointment_id' => $appointment2->id,
            'master_service_id' => $this->masterService->id,
            'type' => SlotRequestType::Earlier,
            'request_source' => SlotRequestSource::Web,
            'delivery_channel' => SlotRequestDeliveryChannel::Telegram,
            'date_from' => Carbon::tomorrow()->toDateString(),
            'date_to' => Carbon::tomorrow()->toDateString(),
            'time_from' => '09:00',
            'time_to' => '18:00',
            'timezone' => 'Europe/Moscow',
            'appointment_start_time_snapshot' => $appointment2->start_time,
            'expires_at' => Carbon::tomorrow()->setTime(14, 59),
        ]);

        // First request expires soon (but after ranking, before create)
        $this->request->update(['expires_at' => Carbon::now()->addSecond()]);

        $offer = $this->matcher->matchOpportunity($this->opportunity);

        // First request may have been expired, second should get offer
        $this->assertNotNull($offer);
    }

    // ── OPEN ignored ──────────────────────────────────────

    public function test_open_request_ignored(): void
    {
        // Create an OPEN request
        $openRequest = SlotRequest::create([
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'client_id' => $this->client->id,
            'appointment_id' => null,
            'master_service_id' => $this->masterService->id,
            'type' => SlotRequestType::Open,
            'request_source' => SlotRequestSource::Telegram,
            'delivery_channel' => SlotRequestDeliveryChannel::Telegram,
            'date_from' => Carbon::tomorrow()->toDateString(),
            'date_to' => Carbon::tomorrow()->toDateString(),
            'time_from' => '09:00',
            'time_to' => '18:00',
            'timezone' => 'Europe/Moscow',
            'appointment_start_time_snapshot' => null,
            'expires_at' => Carbon::tomorrow()->setTime(13, 59),
        ]);

        // Cancel the EARLIER request so only OPEN remains
        $this->request->update(['status' => SlotRequestStatus::Cancelled]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));

        // OPEN request remains Active
        $this->assertSame(SlotRequestStatus::Active, $openRequest->fresh()->status);
    }

    // ── Concurrency behavior ──────────────────────────────

    public function test_opportunity_gets_pending_offer_returns_null(): void
    {
        // Simulate: another matcher creates pending offer before our attempt
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
            'date_to' => Carbon::tomorrow()->toDateString(),
            'time_from' => '09:00',
            'time_to' => '18:00',
            'timezone' => 'Europe/Moscow',
            'appointment_start_time_snapshot' => $otherAppointment->start_time,
            'expires_at' => Carbon::tomorrow()->setTime(14, 59),
        ]);

        // Create pending offer directly
        SlotOffer::create([
            'slot_request_id' => $otherRequest->id,
            'slot_opportunity_id' => $this->opportunity->id,
            'status' => SlotOfferStatus::Pending,
            'expires_at' => Carbon::tomorrow()->setTime(9, 59),
        ]);

        $this->assertNull($this->matcher->matchOpportunity($this->opportunity));
    }

    public function test_exact_pair_appears_next_candidate_considered(): void
    {
        // Create second candidate
        $client2 = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
        ]);

        $appointment2 = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client2)
            ->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(15, 0),
                'duration' => 60,
            ]);

        $request2 = SlotRequest::create([
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'client_id' => $client2->id,
            'appointment_id' => $appointment2->id,
            'master_service_id' => $this->masterService->id,
            'type' => SlotRequestType::Earlier,
            'request_source' => SlotRequestSource::Web,
            'delivery_channel' => SlotRequestDeliveryChannel::Telegram,
            'date_from' => Carbon::tomorrow()->toDateString(),
            'date_to' => Carbon::tomorrow()->toDateString(),
            'time_from' => '09:00',
            'time_to' => '18:00',
            'timezone' => 'Europe/Moscow',
            'appointment_start_time_snapshot' => $appointment2->start_time,
            'expires_at' => Carbon::tomorrow()->setTime(14, 59),
        ]);

        // First request has prior exact pair (declined)
        SlotOffer::create([
            'slot_request_id' => $this->request->id,
            'slot_opportunity_id' => $this->opportunity->id,
            'status' => SlotOfferStatus::Declined,
            'expires_at' => Carbon::tomorrow()->setTime(9, 59),
            'declined_at' => now(),
        ]);

        $offer = $this->matcher->matchOpportunity($this->opportunity);

        $this->assertNotNull($offer);
        $this->assertEquals($request2->id, $offer->slot_request_id);
    }
}
