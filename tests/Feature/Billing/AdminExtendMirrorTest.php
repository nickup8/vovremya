<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\SubscriptionStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingCoreWriter;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\LegacyProjectionService;
use App\Services\Billing\PlanAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminExtendMirrorTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->proPlan = TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'max_appointments_per_month' => null,
            'max_masters' => 1,
            'features' => ['unlimited_appointments'],
            'is_active' => true,
        ]);

        $this->admin = User::factory()->master()->create(['is_super_admin' => true]);

        config(['billing.core_entitlement' => true]);
    }

    private function createMasterWithWorkspace(): array
    {
        $master = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'ws-'.$master->id,
            'owner_id' => $master->id,
        ]);
        $workspace->ensureSlug();
        $master->update(['workspace_id' => $workspace->id]);

        return [$master, $workspace];
    }

    // ── 1. No active → new legacy + Core admin_grant ──

    public function test_no_active_creates_new_legacy_and_core_grant(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();

        $this->actingAs($this->admin)
            ->post(route('super_admin.extend', $master), ['days' => 30]);

        // Legacy created
        $legacy = Subscription::where('workspace_id', $workspace->id)->first();
        $this->assertNotNull($legacy);
        $this->assertEquals(SubscriptionStatus::Active->value, $legacy->status);
        $this->assertEquals(0, $legacy->amount_paid);
        $this->assertNull($legacy->payment_id);

        // Core sub created
        $coreSub = BillingSubscription::where('workspace_id', $workspace->id)
            ->where('tariff_plan_id', $this->proPlan->id)
            ->first();
        $this->assertNotNull($coreSub);

        // Core cycle: admin_grant, paid, amount=0
        $cycle = BillingCycle::where('billing_subscription_id', $coreSub->id)->first();
        $this->assertNotNull($cycle);
        $this->assertEquals(BillingCycleOrigin::AdminGrant, $cycle->origin);
        $this->assertEquals(BillingCycleStatus::Paid, $cycle->status);
        $this->assertEquals(0, $cycle->amount);
        $this->assertEquals($legacy->id, $cycle->legacy_subscription_id);
        $this->assertEquals($legacy->starts_at->toDateTimeString(), $cycle->period_start->toDateTimeString());
        $this->assertEquals($legacy->expires_at->toDateTimeString(), $cycle->period_end->toDateTimeString());

        // No payment attempts
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    // ── 2. Existing active (no Core horizon) → new grant [now, now+30] ──

    public function test_existing_active_creates_delta_cycle(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();

        // Create existing active subscription (but NO Core entitlement)
        $oldExpiry = now()->addDays(10);
        $legacy = Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subMonth(),
            'expires_at' => $oldExpiry,
        ]);

        $this->actingAs($this->admin)
            ->post(route('super_admin.extend', $master), ['days' => 30]);

        // Legacy updated to now+30 (Core-first: no Core horizon → grant from now)
        $legacy->refresh();
        $expectedNewExpiry = now()->addDays(30);
        $this->assertEqualsWithDelta($expectedNewExpiry->timestamp, $legacy->expires_at->timestamp, 5);

        // Core cycle: full grant [now, now+30] (no prior Core horizon)
        $coreSub = BillingSubscription::where('workspace_id', $workspace->id)->first();
        $this->assertNotNull($coreSub);

        $cycle = BillingCycle::where('billing_subscription_id', $coreSub->id)
            ->where('origin', BillingCycleOrigin::AdminGrant)
            ->first();
        $this->assertNotNull($cycle);
        $this->assertEquals(BillingCycleStatus::Paid, $cycle->status);
        $this->assertEquals(0, $cycle->amount);

        // No payment attempts
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    // ── 3. Repeated extends → X→Y→Z via Core horizon chain ──

    public function test_repeated_extends_produce_chain_XYZ(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();

        // First extend: creates new Core grant [now, now+30]
        $this->actingAs($this->admin)
            ->post(route('super_admin.extend', $master), ['days' => 30]);

        $legacy = Subscription::where('workspace_id', $workspace->id)->first();
        $firstExpiry = $legacy->expires_at->toDateTimeString();

        // Advance time so the second extend produces a different expiry
        \Carbon\Carbon::setTestNow(now()->addHour());

        // Second extend: Core horizon exists → delta [firstExpiry, firstExpiry+30]
        $this->actingAs($this->admin)
            ->post(route('super_admin.extend', $master), ['days' => 30]);

        $legacy->refresh();
        $secondExpiry = $legacy->expires_at->toDateTimeString();
        $this->assertNotEquals($firstExpiry, $secondExpiry);

        // Core should have 2 admin_grant cycles
        $coreSub = BillingSubscription::where('workspace_id', $workspace->id)->first();
        $adminCycles = BillingCycle::where('billing_subscription_id', $coreSub->id)
            ->where('origin', BillingCycleOrigin::AdminGrant)
            ->orderBy('period_start')
            ->get();

        $this->assertCount(2, $adminCycles);

        // Chain: first cycle ends where second starts
        $this->assertEquals(
            $adminCycles[0]->period_end->toDateTimeString(),
            $adminCycles[1]->period_start->toDateTimeString(),
        );

        // Final cycle ends at the secondExpiry
        $this->assertEquals(
            $secondExpiry,
            $adminCycles[1]->period_end->toDateTimeString(),
        );

        // Entitlement end = Z
        $core = app(EntitlementService::class);
        $end = $core->entitlementEnd($workspace, 'pro');
        $this->assertNotNull($end);
        $this->assertEquals($secondExpiry, $end->toDateTimeString());
    }

    // ── 4. Paid/payment history not mutated ──

    public function test_paid_payment_history_not_mutated(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();

        // Create existing paid cycle
        $coreSub = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::Active,
        ]);

        $oldCycle = BillingCycle::create([
            'billing_subscription_id' => $coreSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now()->subMonth(),
            'period_end' => now(),
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Payment,
        ]);

        // Create legacy sub with future expiry
        $legacy = Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addDays(5),
        ]);

        $this->actingAs($this->admin)
            ->post(route('super_admin.extend', $master), ['days' => 30]);

        // Old cycle unchanged
        $oldCycle->refresh();
        $this->assertEquals(BillingCycleOrigin::Payment, $oldCycle->origin);
        $this->assertEquals(BillingCycleStatus::Paid, $oldCycle->status);
        $this->assertEquals(490, $oldCycle->amount);
    }

    // ── 5. Legacy_grant history not mutated ──

    public function test_legacy_grant_history_not_mutated(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();

        // Create existing legacy_grant cycle
        $coreSub = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::Active,
        ]);

        $oldCycle = BillingCycle::create([
            'billing_subscription_id' => $coreSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now()->subMonth(),
            'period_end' => now(),
            'status' => BillingCycleStatus::Paid,
            'amount' => 0,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::LegacyGrant,
        ]);

        $legacy = Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 0,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addDays(5),
        ]);

        $this->actingAs($this->admin)
            ->post(route('super_admin.extend', $master), ['days' => 30]);

        $oldCycle->refresh();
        $this->assertEquals(BillingCycleOrigin::LegacyGrant, $oldCycle->origin);
        $this->assertEquals(BillingCycleStatus::Paid, $oldCycle->status);
    }

    // ── 6. Core exception → full rollback ──

    public function test_core_exception_rolls_back_legacy(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();

        // Create legacy sub to extend
        $legacy = Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addDays(5),
        ]);

        $oldExpiry = $legacy->expires_at->toDateTimeString();

        // Mock BillingCoreWriter to throw
        $this->app->bind(BillingCoreWriter::class, function () {
            return new class extends BillingCoreWriter {
                public function adminGrant(string $workspaceId, TariffPlan $plan, Subscription $legacy, string $periodStart, string $periodEnd): void
                {
                    throw new \RuntimeException('Core write failed');
                }
            };
        });

        $this->actingAs($this->admin)
            ->post(route('super_admin.extend', $master), ['days' => 30]);

        // Legacy NOT changed (rollback)
        $legacy->refresh();
        $this->assertEquals($oldExpiry, $legacy->expires_at->toDateTimeString());

        // No core cycles created
        $this->assertDatabaseCount('billing_cycles', 0);
    }

    // ── 7. Chain projection = 0 create ──

    public function test_chain_projection_creates_zero(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();

        // First extend: [now, now+30]
        $this->actingAs($this->admin)
            ->post(route('super_admin.extend', $master), ['days' => 30]);

        $legacy = Subscription::where('workspace_id', $workspace->id)->first();
        $firstExpiry = $legacy->expires_at->copy();

        // Manually extend legacy row (simulating second admin extend)
        $secondExpiry = $firstExpiry->addDays(30);
        $legacy->update(['expires_at' => $secondExpiry]);

        // Add delta admin_grant cycle
        $coreSub = BillingSubscription::where('workspace_id', $workspace->id)->first();
        BillingCycle::create([
            'billing_subscription_id' => $coreSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => $firstExpiry,
            'period_end' => $secondExpiry,
            'status' => BillingCycleStatus::Paid,
            'amount' => 0,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::AdminGrant,
            'legacy_subscription_id' => $legacy->id,
        ]);

        // Projection dry-run should see chain coverage
        $service = app(LegacyProjectionService::class);
        $dry = $service->projectAll(dryRun: true);

        $this->assertSame(0, $dry['to_create']['billing_cycles']);
        $this->assertSame(0, $dry['to_create']['payment_attempts']);

        // Actual projection should also create 0
        $actual = $service->projectAll(dryRun: false);

        $this->assertSame(0, $actual['created']['billing_cycles']);
        $this->assertSame(0, $actual['created']['payment_attempts']);
    }

    // ── 8. Foreign legacy ID does not cover ──

    public function test_foreign_legacy_id_does_not_cover(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();

        // Create two legacy rows with DIFFERENT periods
        $legacyA = Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 0,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDays(60),
            'expires_at' => now()->addDays(30),
        ]);

        $legacyB = Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 0,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDays(30),
            'expires_at' => now()->addDays(60),
        ]);

        // Core: cycle covering B's period with legacy_subscription_id = B
        $coreSub = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::Active,
        ]);

        BillingCycle::create([
            'billing_subscription_id' => $coreSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now()->subDays(30),
            'period_end' => now()->addDays(60),
            'status' => BillingCycleStatus::Paid,
            'amount' => 0,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::AdminGrant,
            'legacy_subscription_id' => $legacyB->id,
        ]);

        // Projection should NOT consider A's period as covered (foreign ID)
        $service = app(LegacyProjectionService::class);
        $dry = $service->projectAll(dryRun: true);

        // A's period has no exact match and no chain with A's ID → needs creation
        // B's period IS covered → no creation
        // So cycles_to_create = 1 (for A)
        $this->assertSame(1, $dry['to_create']['billing_cycles']);
    }

    // ── 9. Gap does not cover ──

    public function test_gap_in_chain_does_not_cover(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();

        $legacy = Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 0,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDays(60),
            'expires_at' => now()->addDays(30),
        ]);

        $coreSub = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::Active,
        ]);

        // Two cycles with a gap (1-day gap)
        BillingCycle::create([
            'billing_subscription_id' => $coreSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now()->subDays(60),
            'period_end' => now()->subDays(10),
            'status' => BillingCycleStatus::Paid,
            'amount' => 0,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::AdminGrant,
            'legacy_subscription_id' => $legacy->id,
        ]);

        BillingCycle::create([
            'billing_subscription_id' => $coreSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now()->subDays(8), // Gap: -10 to -8
            'period_end' => now()->addDays(30),
            'status' => BillingCycleStatus::Paid,
            'amount' => 0,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::AdminGrant,
            'legacy_subscription_id' => $legacy->id,
        ]);

        $service = app(LegacyProjectionService::class);
        $dry = $service->projectAll(dryRun: true);

        // Gap → not covered → 1 cycle to create
        $this->assertSame(1, $dry['to_create']['billing_cycles']);
    }

    // ── 10. Overlap does not cover ──

    public function test_overlap_in_chain_does_not_cover(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();

        $legacy = Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 0,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDays(60),
            'expires_at' => now()->addDays(30),
        ]);

        $coreSub = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::Active,
        ]);

        // Two cycles with overlap
        BillingCycle::create([
            'billing_subscription_id' => $coreSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now()->subDays(60),
            'period_end' => now()->addDays(5), // overlaps
            'status' => BillingCycleStatus::Paid,
            'amount' => 0,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::AdminGrant,
            'legacy_subscription_id' => $legacy->id,
        ]);

        BillingCycle::create([
            'billing_subscription_id' => $coreSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now()->subDays(30), // starts before first ends
            'period_end' => now()->addDays(30),
            'status' => BillingCycleStatus::Paid,
            'amount' => 0,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::AdminGrant,
            'legacy_subscription_id' => $legacy->id,
        ]);

        $service = app(LegacyProjectionService::class);
        $dry = $service->projectAll(dryRun: true);

        // Overlap → not covered → 1 cycle to create
        $this->assertSame(1, $dry['to_create']['billing_cycles']);
    }

    // ── 11. Dry-run == actual projection ──

    public function test_dry_run_equals_actual_projection(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();

        // First extend
        $this->actingAs($this->admin)
            ->post(route('super_admin.extend', $master), ['days' => 30]);

        $legacy = Subscription::where('workspace_id', $workspace->id)->first();
        $firstExpiry = $legacy->expires_at->copy();

        // Manually extend + add delta cycle
        $secondExpiry = $firstExpiry->addDays(30);
        $legacy->update(['expires_at' => $secondExpiry]);

        $coreSub = BillingSubscription::where('workspace_id', $workspace->id)->first();
        BillingCycle::create([
            'billing_subscription_id' => $coreSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => $firstExpiry,
            'period_end' => $secondExpiry,
            'status' => BillingCycleStatus::Paid,
            'amount' => 0,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::AdminGrant,
            'legacy_subscription_id' => $legacy->id,
        ]);

        $service = app(LegacyProjectionService::class);

        // Dry-run
        $dry = $service->projectAll(dryRun: true);

        // Actual
        $actual = $service->projectAll(dryRun: false);

        // Both should show 0 creates
        $this->assertSame($dry['to_create']['billing_cycles'], $actual['created']['billing_cycles']);
        $this->assertSame($dry['to_create']['payment_attempts'], $actual['created']['payment_attempts']);
        $this->assertSame(0, $dry['to_create']['billing_cycles']);
        $this->assertSame(0, $dry['to_create']['payment_attempts']);
    }

    // ── 12. Parity after first grant ──

    public function test_parity_after_first_grant(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();

        $this->actingAs($this->admin)
            ->post(route('super_admin.extend', $master), ['days' => 30]);

        $legacy = app(PlanAccessService::class);
        $core = app(EntitlementService::class);

        $this->assertEquals('pro', $legacy->currentPlanCode($workspace));

        $corePlan = $core->currentPlan($workspace);
        $this->assertNotNull($corePlan);
        $this->assertEquals('pro', $corePlan->code);
    }

    // ── 13. Parity after extend (Core-first) ──

    public function test_parity_after_extend(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();

        // First grant
        $this->actingAs($this->admin)
            ->post(route('super_admin.extend', $master), ['days' => 30]);

        // Extend
        $this->actingAs($this->admin)
            ->post(route('super_admin.extend', $master), ['days' => 30]);

        $core = app(EntitlementService::class);

        $corePlan = $core->currentPlan($workspace);
        $this->assertNotNull($corePlan);
        $this->assertEquals('pro', $corePlan->code);

        // Core entitlement end should be valid
        $coreEnd = $core->entitlementEnd($workspace, 'pro');
        $this->assertNotNull($coreEnd);
        $this->assertTrue($coreEnd->isFuture());
    }

    // ── 14. Parity after repeated extends (Core-first) ──

    public function test_parity_after_repeated_extends(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();

        // Three extends
        $this->actingAs($this->admin)
            ->post(route('super_admin.extend', $master), ['days' => 30]);
        $this->actingAs($this->admin)
            ->post(route('super_admin.extend', $master), ['days' => 30]);
        $this->actingAs($this->admin)
            ->post(route('super_admin.extend', $master), ['days' => 30]);

        $core = app(EntitlementService::class);

        $corePlan = $core->currentPlan($workspace);
        $this->assertNotNull($corePlan);
        $this->assertEquals('pro', $corePlan->code);

        $coreEnd = $core->entitlementEnd($workspace, 'pro');
        $this->assertNotNull($coreEnd);
        $this->assertTrue($coreEnd->isFuture());
    }

    // ── 15. AuditLog contract preserved ──

    public function test_audit_log_contract_preserved(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();

        // Create existing subscription
        $legacy = Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addDays(10),
        ]);

        $this->actingAs($this->admin)
            ->post(route('super_admin.extend', $master), ['days' => 30]);

        $log = \App\Models\SuperAdminAuditLog::where('action', 'subscription.extended')->first();
        $this->assertNotNull($log);
        $this->assertEquals($this->admin->id, $log->super_admin_id);
        $this->assertEquals(Subscription::class, $log->target_type);
        $this->assertEquals($legacy->id, $log->target_id);
        $this->assertEquals(30, $log->metadata['days_added']);
        $this->assertEquals($workspace->id, $log->metadata['workspace_id']);
    }

    // ── 16. BillingSubscription horizon synced after grant ──

    public function test_billing_subscription_horizon_synced(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();

        $this->actingAs($this->admin)
            ->post(route('super_admin.extend', $master), ['days' => 30]);

        $coreSub = BillingSubscription::where('workspace_id', $workspace->id)->first();
        $this->assertNotNull($coreSub);
        $this->assertEquals(BillingSubscriptionStatus::Active, $coreSub->status);

        $legacy = Subscription::where('workspace_id', $workspace->id)->first();
        $this->assertEquals(
            $legacy->expires_at->toDateTimeString(),
            $coreSub->current_period_end->toDateTimeString(),
        );
    }
}
