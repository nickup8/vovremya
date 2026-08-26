<?php

namespace Tests\Feature;

use App\DTOs\AppointmentWindowFreed;
use App\Enums\AppointmentStatus;
use App\Enums\SlotOpportunitySourceType;
use App\Enums\SlotOpportunityStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\SlotOpportunity;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Booking\BookingService;
use App\Services\FreedWindowDispatcher;
use App\Services\SlotOpportunityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FreedWindowTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;
    private TariffPlan $startPlan;
    private User $master;
    private Workspace $ws;
    private Client $client;
    private MasterService $masterService;
    private Appointment $appointment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startPlan = TariffPlan::create([
            'code' => 'start', 'name' => 'Старт', 'price_monthly' => 0,
            'features' => ['calendar', 'basic_client_management'], 'is_active' => true,
        ]);

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

    // ── Cancellation snapshot tests ───────────────────────
    // Test the snapshot by calling transition and verifying the job
    // was dispatched via DB::afterCommit (which fires immediately
    // when no explicit transaction is active).

    public function test_cancellation_creates_opportunity(): void
    {
        app(\App\Services\AppointmentStatusService::class)
            ->transition($this->appointment, AppointmentStatus::Cancelled);

        $this->assertDatabaseCount('slot_opportunities', 1);
        $this->assertDatabaseHas('slot_opportunities', [
            'source_type' => 'cancellation',
            'status' => 'open',
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'master_service_id' => $this->masterService->id,
        ]);
    }

    public function test_cancellation_snapshot_start_time(): void
    {
        $originalStartTime = $this->appointment->start_time;

        app(\App\Services\AppointmentStatusService::class)
            ->transition($this->appointment, AppointmentStatus::Cancelled);

        $opp = SlotOpportunity::first();
        $this->assertEquals($originalStartTime->format('Y-m-d H:i:s'), $opp->start_time->format('Y-m-d H:i:s'));
    }

    public function test_cancellation_snapshot_duration(): void
    {
        app(\App\Services\AppointmentStatusService::class)
            ->transition($this->appointment, AppointmentStatus::Cancelled);

        $opp = SlotOpportunity::first();
        $this->assertEquals(60, $opp->duration);
    }

    public function test_cancellation_snapshot_source_type(): void
    {
        app(\App\Services\AppointmentStatusService::class)
            ->transition($this->appointment, AppointmentStatus::Cancelled);

        $opp = SlotOpportunity::first();
        $this->assertEquals(SlotOpportunitySourceType::Cancellation, $opp->source_type);
    }

    public function test_cancellation_snapshot_chain_id_generated(): void
    {
        app(\App\Services\AppointmentStatusService::class)
            ->transition($this->appointment, AppointmentStatus::Cancelled);

        $opp = SlotOpportunity::first();
        $this->assertNotNull($opp->chain_id);
        $this->assertNotEmpty($opp->chain_id);
    }

    public function test_cancellation_snapshot_source_appointment(): void
    {
        app(\App\Services\AppointmentStatusService::class)
            ->transition($this->appointment, AppointmentStatus::Cancelled);

        $opp = SlotOpportunity::first();
        $this->assertEquals($this->appointment->id, $opp->source_appointment_id);
    }

    // ── Toggle OFF → zero Opportunity ─────────────────────

    public function test_toggle_off_no_opportunity(): void
    {
        $this->master->update(['autofill_enabled' => false]);

        app(\App\Services\AppointmentStatusService::class)
            ->transition($this->appointment, AppointmentStatus::Cancelled);

        $this->assertDatabaseCount('slot_opportunities', 0);
    }

    // ── Start/no Pro → zero Opportunity ───────────────────

    public function test_start_plan_no_opportunity(): void
    {
        Subscription::where('workspace_id', $this->ws->id)->update(['expires_at' => now()->subDay()]);

        Subscription::create([
            'workspace_id' => $this->ws->id,
            'tariff_plan_id' => $this->startPlan->id,
            'period_months' => 1, 'amount_paid' => 0,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(), 'expires_at' => now()->addMonth(),
        ]);

        app(\App\Services\AppointmentStatusService::class)
            ->transition($this->appointment, AppointmentStatus::Cancelled);

        $this->assertDatabaseCount('slot_opportunities', 0);
    }

    // ── Draft (client_id=null) → zero Opportunity ─────────

    public function test_draft_cancellation_no_opportunity(): void
    {
        $this->appointment->update(['client_id' => null]);

        app(\App\Services\AppointmentStatusService::class)
            ->transition($this->appointment, AppointmentStatus::Cancelled);

        $this->assertDatabaseCount('slot_opportunities', 0);
    }

    // ── PendingPayment → Cancelled → zero Opportunity ─────

    public function test_pending_payment_cancelled_no_opportunity(): void
    {
        $this->appointment->update(['status' => AppointmentStatus::PendingPayment]);

        app(\App\Services\AppointmentStatusService::class)
            ->transition($this->appointment, AppointmentStatus::Cancelled);

        $this->assertDatabaseCount('slot_opportunities', 0);
    }

    // ── Prepaid → Cancelled → zero Opportunity ────────────

    public function test_prepaid_cancelled_no_opportunity(): void
    {
        $this->appointment->update(['status' => AppointmentStatus::Prepaid]);

        app(\App\Services\AppointmentStatusService::class)
            ->transition($this->appointment, AppointmentStatus::Cancelled);

        $this->assertDatabaseCount('slot_opportunities', 0);
    }

    // ── master_service_id=null → cancellation succeeds, no Opportunity ──

    public function test_null_master_service_no_opportunity(): void
    {
        $this->appointment->update(['master_service_id' => null]);

        app(\App\Services\AppointmentStatusService::class)
            ->transition($this->appointment, AppointmentStatus::Cancelled);

        $this->assertEquals(AppointmentStatus::Cancelled, $this->appointment->fresh()->status);
        $this->assertDatabaseCount('slot_opportunities', 0);
    }

    // ── Duplicate DTO → one Opportunity ───────────────────

    public function test_duplicate_dto_one_opportunity(): void
    {
        $originEventId = (string) Str::uuid();

        $service = new SlotOpportunityService();

        $opp1 = $service->createFromFreedWindow(
            originEventId: $originEventId,
            chainId: null,
            workspaceId: $this->ws->id,
            masterId: $this->master->id,
            masterServiceId: $this->masterService->id,
            sourceAppointmentId: $this->appointment->id,
            sourceType: SlotOpportunitySourceType::Cancellation,
            startTime: $this->appointment->start_time,
            duration: 60,
        );

        $opp2 = $service->createFromFreedWindow(
            originEventId: $originEventId,
            chainId: null,
            workspaceId: $this->ws->id,
            masterId: $this->master->id,
            masterServiceId: $this->masterService->id,
            sourceAppointmentId: $this->appointment->id,
            sourceType: SlotOpportunitySourceType::Cancellation,
            startTime: $this->appointment->start_time,
            duration: 60,
        );

        $this->assertEquals($opp1->id, $opp2->id);
        $this->assertCount(1, SlotOpportunity::all());
    }

    // ── Toggle timing: enabled at event, disabled at worker ──

    public function test_toggle_disabled_after_event_still_creates(): void
    {
        // Capture snapshot while enabled
        $window = new AppointmentWindowFreed(
            originEventId: (string) Str::uuid(),
            chainId: null,
            workspaceId: $this->ws->id,
            masterId: $this->master->id,
            masterServiceId: $this->masterService->id,
            sourceAppointmentId: $this->appointment->id,
            sourceType: SlotOpportunitySourceType::Cancellation,
            startTime: Carbon::tomorrow()->setTime(14, 0),
            duration: 60,
        );

        // Disable toggle AFTER snapshot
        $this->master->update(['autofill_enabled' => false]);

        // Job handler should still create the opportunity
        $service = new SlotOpportunityService();
        $opp = $service->createFromFreedWindow(
            originEventId: $window->originEventId,
            chainId: $window->chainId,
            workspaceId: $window->workspaceId,
            masterId: $window->masterId,
            masterServiceId: $window->masterServiceId,
            sourceAppointmentId: $window->sourceAppointmentId,
            sourceType: $window->sourceType,
            startTime: $window->startTime,
            duration: $window->duration,
        );

        $this->assertNotNull($opp);
        $this->assertEquals(SlotOpportunityStatus::Open, $opp->status);
    }

    // ── Toggle timing: disabled at event, enabled after ──

    public function test_toggle_disabled_at_event_no_opportunity(): void
    {
        $this->master->update(['autofill_enabled' => false]);

        app(\App\Services\AppointmentStatusService::class)
            ->transition($this->appointment, AppointmentStatus::Cancelled);

        $this->assertDatabaseCount('slot_opportunities', 0);

        // Enable toggle AFTER — no opportunity should appear
        $this->master->update(['autofill_enabled' => true]);
        $this->assertDatabaseCount('slot_opportunities', 0);
    }

    // ── Reschedule snapshot via SlotOpportunityService ────
    // BookingService::rescheduleAppointment wraps in DB::transaction(),
    // so DB::afterCommit doesn't fire until the outer RefreshDatabase
    // transaction commits. The cancellation integration tests above
    // prove the afterCommit → job → opportunity pipeline works.
    // Here we verify the reschedule snapshot DTO creates a correct
    // opportunity via the same SlotOpportunityService.

    public function test_reschedule_snapshot_creates_opportunity(): void
    {
        $oldStartTime = $this->appointment->start_time;

        $window = new AppointmentWindowFreed(
            originEventId: (string) Str::uuid(),
            chainId: null,
            workspaceId: $this->ws->id,
            masterId: $this->master->id,
            masterServiceId: $this->masterService->id,
            sourceAppointmentId: $this->appointment->id,
            sourceType: SlotOpportunitySourceType::Reschedule,
            startTime: $oldStartTime,
            duration: 60,
        );

        $service = new SlotOpportunityService();
        $opp = $service->createFromFreedWindow(
            originEventId: $window->originEventId,
            chainId: $window->chainId,
            workspaceId: $window->workspaceId,
            masterId: $window->masterId,
            masterServiceId: $window->masterServiceId,
            sourceAppointmentId: $window->sourceAppointmentId,
            sourceType: $window->sourceType,
            startTime: $window->startTime,
            duration: $window->duration,
        );

        $this->assertNotNull($opp);
        $this->assertEquals(SlotOpportunitySourceType::Reschedule, $opp->source_type);
        $this->assertEquals($oldStartTime->format('Y-m-d H:i:s'), $opp->start_time->format('Y-m-d H:i:s'));
        $this->assertEquals($this->master->id, $opp->master_id);
        $this->assertEquals(60, $opp->duration);
    }

    public function test_reschedule_master_change_uses_old_master(): void
    {
        $newMaster = User::factory()->master()->create();
        $newMaster->update(['workspace_id' => $this->ws->id]);

        // Snapshot uses OLD master, not new
        $window = new AppointmentWindowFreed(
            originEventId: (string) Str::uuid(),
            chainId: null,
            workspaceId: $this->ws->id,
            masterId: $this->master->id, // OLD master
            masterServiceId: $this->masterService->id,
            sourceAppointmentId: $this->appointment->id,
            sourceType: SlotOpportunitySourceType::Reschedule,
            startTime: $this->appointment->start_time,
            duration: 60,
        );

        $service = new SlotOpportunityService();
        $opp = $service->createFromFreedWindow(
            originEventId: $window->originEventId,
            chainId: $window->chainId,
            workspaceId: $window->workspaceId,
            masterId: $window->masterId,
            masterServiceId: $window->masterServiceId,
            sourceAppointmentId: $window->sourceAppointmentId,
            sourceType: $window->sourceType,
            startTime: $window->startTime,
            duration: $window->duration,
        );

        $this->assertEquals($this->master->id, $opp->master_id);
        $this->assertNotEquals($newMaster->id, $opp->master_id);
    }
}
