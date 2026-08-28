<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutofillAnalyticsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $master;
    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();

        $proPlan = TariffPlan::create([
            'code' => 'pro', 'name' => 'Профи', 'price_monthly' => 490,
            'features' => ['unlimited_appointments', 'client_management', 'channel_analytics', 'slot_autofill'],
            'is_active' => true,
        ]);

        $this->master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
            'autofill_enabled' => true,
        ]);
        $this->ws = Workspace::create(['name' => 'WS Test', 'owner_id' => $this->master->id]);
        $this->master->update(['workspace_id' => $this->ws->id]);

        Subscription::create([
            'workspace_id' => $this->ws->id,
            'tariff_plan_id' => $proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);
    }

    // ── Entitlement present → autofill data in props ──────

    public function test_slot_autofill_entitlement_returns_autofill_props(): void
    {
        $this->actingAs($this->master)
            ->get(route('admin.analytics', ['period' => 'month']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('autofill_feature', true)
                ->has('autofill')
                ->where('autofill.requests_created', 0)
                ->where('autofill.offers_sent', 0)
                ->where('autofill.offers_accepted', 0)
                ->where('autofill.acceptance_rate', 0)
                ->where('autofill.median_time_to_accept_seconds', null));
    }

    // ── Entitlement missing → no autofill data ────────────

    public function test_start_plan_no_autofill_feature_or_data(): void
    {
        $startPlan = TariffPlan::create([
            'code' => 'start', 'name' => 'Старт', 'price_monthly' => 0,
            'features' => ['calendar', 'basic_client_management'],
            'is_active' => true,
        ]);

        Subscription::where('workspace_id', $this->ws->id)->update([
            'status' => SubscriptionStatus::Expired->value,
            'expires_at' => now()->subDay(),
        ]);

        Subscription::create([
            'workspace_id' => $this->ws->id,
            'tariff_plan_id' => $startPlan->id,
            'period_months' => 1,
            'amount_paid' => 0,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);

        $this->actingAs($this->master)
            ->get(route('admin.analytics', ['period' => 'month']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('autofill_feature', false)
                ->where('autofill', null));
    }

    // ── autofill_enabled=false with entitlement → stats still shown ──

    public function test_autofill_disabled_but_entitlement_shows_historical_stats(): void
    {
        $this->master->update(['autofill_enabled' => false]);

        $this->actingAs($this->master)
            ->get(route('admin.analytics', ['period' => 'month']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('autofill_feature', true)
                ->has('autofill'));
    }

    // ── Period correctly passed to service ────────────────

    public function test_period_passed_correctly_filters_data(): void
    {
        $client = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'max_id' => 'max_123',
        ]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $this->ws->id,
            'title' => 'Массаж', 'base_price' => 2000, 'base_duration' => 60,
        ]);
        $ms = MasterService::create([
            'master_id' => $this->master->id,
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);

        for ($d = 0; $d <= 6; $d++) {
            WorkingHour::updateOrCreate(
                ['user_id' => $this->master->id, 'day_of_week' => $d],
                ['is_working' => true, 'start_time' => '09:00', 'end_time' => '18:00',
                    'break_start_time' => null, 'break_end_time' => null],
            );
        }

        $appt = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client)
            ->withMasterService($ms)
            ->create([
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(14, 0),
                'duration' => 60,
            ]);

        // Create request + opportunity + accepted offer in current month
        $req = SlotRequest::create([
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'client_id' => $client->id,
            'appointment_id' => $appt->id,
            'master_service_id' => $ms->id,
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
        ]);

        $opp = SlotOpportunity::create([
            'origin_event_id' => (string) Str::uuid(),
            'chain_id' => (string) Str::uuid(),
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'master_service_id' => $ms->id,
            'source_type' => SlotOpportunitySourceType::Cancellation,
            'status' => SlotOpportunityStatus::Filled,
            'start_time' => Carbon::tomorrow()->setTime(10, 0),
            'duration' => 60,
            'filled_by_appointment_id' => $appt->id,
            'filled_at' => now(),
        ]);

        SlotOffer::create([
            'slot_request_id' => $req->id,
            'slot_opportunity_id' => $opp->id,
            'status' => SlotOfferStatus::Accepted,
            'expires_at' => Carbon::tomorrow()->setTime(9, 59),
            'accepted_at' => now(),
            'sent_at' => now()->subSeconds(120),
            'delivery_mid' => 'mid_123',
        ]);

        $this->actingAs($this->master)
            ->get(route('admin.analytics', ['period' => 'month']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('autofill.requests_created', 1)
                ->where('autofill.offers_sent', 1)
                ->where('autofill.offers_accepted', 1)
                ->where('autofill.acceptance_rate', 100));
    }

    // ── Zero state ────────────────────────────────────────

    public function test_zero_state_returns_zeros_and_null_timing(): void
    {
        $this->actingAs($this->master)
            ->get(route('admin.analytics', ['period' => 'day']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('autofill.requests_created', 0)
                ->where('autofill.offers_sent', 0)
                ->where('autofill.offers_accepted', 0)
                ->where('autofill.acceptance_rate', 0)
                ->where('autofill.median_time_to_accept_seconds', null));
    }

    // ── Internal metrics NOT exposed ──────────────────────

    public function test_internal_metrics_not_in_autofill_prop(): void
    {
        $response = $this->actingAs($this->master)
            ->get(route('admin.analytics', ['period' => 'month']))
            ->assertOk();

        $autofill = $response->viewData('page')['props']['autofill'] ?? [];
        $keys = array_keys($autofill);

        $this->assertContains('requests_created', $keys);
        $this->assertContains('offers_sent', $keys);
        $this->assertContains('offers_accepted', $keys);
        $this->assertContains('acceptance_rate', $keys);
        $this->assertContains('median_time_to_accept_seconds', $keys);

        $this->assertNotContains('filled_window_count', $keys);
        $this->assertNotContains('opportunities_created', $keys);
        $this->assertNotContains('invalidations_by_reason', $keys);
        $this->assertNotContains('opportunities_invalidated', $keys);
        $this->assertNotContains('chain_count', $keys);
        $this->assertNotContains('opportunity_to_offer_median_seconds', $keys);
        $this->assertNotContains('recovered_revenue', $keys);
        $this->assertNotContains('incremental_booking_count', $keys);
    }

    // ── Existing analytics tests still pass ───────────────

    public function test_existing_overview_metrics_still_present(): void
    {
        $this->actingAs($this->master)
            ->get(route('admin.analytics', ['period' => 'month']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('metrics')
                ->has('trends')
                ->has('chartData')
                ->where('activePeriod', 'month'));
    }
}
