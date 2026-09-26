<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingHorizon;
use App\Services\Billing\BillingService;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\PlanPriceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanPriceResolverTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->proPlan = TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'max_masters' => null,
            'features' => ['unlimited_appointments', 'client_management', 'channel_analytics', 'slot_autofill', 'recurring_blocked_times', 'recurring_appointments'],
            'is_active' => true,
        ]);
    }

    // ═══════════════════════════════════════════
    // PlanPriceResolver: resolve
    // ═══════════════════════════════════════════

    public function test_resolves_active_plan_price(): void
    {
        $price = PlanPrice::create([
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'base_amount' => 490,
            'discount_percent' => 0,
            'final_amount' => 490,
            'currency' => 'RUB',
            'version' => 1,
            'valid_from' => now()->subDay(),
            'is_active' => true,
        ]);

        $resolver = app(PlanPriceResolver::class);
        $result = $resolver->resolve($this->proPlan, 1);

        $this->assertNotNull($result);
        $this->assertSame($price->id, $result->id);
    }

    public function test_returns_latest_version(): void
    {
        PlanPrice::create([
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'base_amount' => 490,
            'discount_percent' => 0,
            'final_amount' => 490,
            'currency' => 'RUB',
            'version' => 1,
            'valid_from' => now()->subDays(10),
            'is_active' => true,
        ]);

        $v2 = PlanPrice::create([
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'base_amount' => 590,
            'discount_percent' => 0,
            'final_amount' => 590,
            'currency' => 'RUB',
            'version' => 2,
            'valid_from' => now()->subDay(),
            'is_active' => true,
        ]);

        $resolver = app(PlanPriceResolver::class);
        $result = $resolver->resolve($this->proPlan, 1);

        $this->assertNotNull($result);
        $this->assertSame(2, $result->version);
        $this->assertSame($v2->id, $result->id);
    }

    public function test_expired_price_not_selected(): void
    {
        PlanPrice::create([
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'base_amount' => 490,
            'discount_percent' => 0,
            'final_amount' => 490,
            'currency' => 'RUB',
            'version' => 1,
            'valid_from' => now()->subDays(10),
            'valid_to' => now()->subDay(),
            'is_active' => true,
        ]);

        $resolver = app(PlanPriceResolver::class);
        $result = $resolver->resolve($this->proPlan, 1);

        $this->assertNull($result);
    }

    public function test_future_price_not_selected(): void
    {
        PlanPrice::create([
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'base_amount' => 490,
            'discount_percent' => 0,
            'final_amount' => 490,
            'currency' => 'RUB',
            'version' => 1,
            'valid_from' => now()->addDays(5),
            'is_active' => true,
        ]);

        $resolver = app(PlanPriceResolver::class);
        $result = $resolver->resolve($this->proPlan, 1);

        $this->assertNull($result);
    }

    public function test_inactive_price_not_selected(): void
    {
        PlanPrice::create([
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'base_amount' => 490,
            'discount_percent' => 0,
            'final_amount' => 490,
            'currency' => 'RUB',
            'version' => 1,
            'valid_from' => now()->subDay(),
            'is_active' => false,
        ]);

        $resolver = app(PlanPriceResolver::class);
        $result = $resolver->resolve($this->proPlan, 1);

        $this->assertNull($result);
    }

    public function test_calculate_price_returns_plan_price_id(): void
    {
        PlanPrice::create([
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'base_amount' => 490,
            'discount_percent' => 0,
            'final_amount' => 490,
            'currency' => 'RUB',
            'version' => 1,
            'valid_from' => now(),
            'is_active' => true,
        ]);

        $resolver = app(PlanPriceResolver::class);
        $result = $resolver->calculatePrice($this->proPlan, 1);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('plan_price_id', $result);
        $this->assertSame(490, $result['final']);
        $this->assertSame('RUB', $result['currency']);
        $this->assertSame(1, $result['version']);
    }

    // ═══════════════════════════════════════════
    // Checkout writes plan_price_id
    // ═══════════════════════════════════════════

    public function test_checkout_writes_plan_price_id_to_cycle(): void
    {
        $planPrice = PlanPrice::create([
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'base_amount' => 490,
            'discount_percent' => 0,
            'final_amount' => 490,
            'currency' => 'RUB',
            'version' => 1,
            'valid_from' => now(),
            'is_active' => true,
        ]);

        $master = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'ws-'.$master->id,
            'owner_id' => $master->id,
        ]);
        $workspace->ensureSlug();
        $master->update(['workspace_id' => $workspace->id]);

        $service = app(BillingService::class);
        $result = $service->subscribe($master, $this->proPlan, 1);

        $cycle = \App\Models\BillingCycle::where('legacy_subscription_id', $result['subscription']->id)->first();
        $this->assertNotNull($cycle);
        $this->assertSame($planPrice->id, $cycle->plan_price_id);

        // Verify price_snapshot contains required fields
        $snapshot = $cycle->price_snapshot;
        $this->assertArrayHasKey('plan_price_id', $snapshot);
        $this->assertArrayHasKey('base_amount', $snapshot);
        $this->assertArrayHasKey('discount_percent', $snapshot);
        $this->assertArrayHasKey('final_amount', $snapshot);
        $this->assertArrayHasKey('currency', $snapshot);
        $this->assertArrayHasKey('version', $snapshot);
        $this->assertArrayHasKey('period_months', $snapshot);
    }

    public function test_checkout_amount_equals_final_amount(): void
    {
        PlanPrice::create([
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 3,
            'base_amount' => 1470,
            'discount_percent' => 5,
            'final_amount' => 1397,
            'currency' => 'RUB',
            'version' => 1,
            'valid_from' => now(),
            'is_active' => true,
        ]);

        $master = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'ws-'.$master->id,
            'owner_id' => $master->id,
        ]);
        $workspace->ensureSlug();
        $master->update(['workspace_id' => $workspace->id]);

        $service = app(BillingService::class);
        $result = $service->subscribe($master, $this->proPlan, 3);

        $this->assertSame(1397, $result['subscription']->amount_paid);
    }

    // ═══════════════════════════════════════════
    // BillingHorizon: stacking
    // ═══════════════════════════════════════════

    public function test_active_core_horizon_stacks_next_period(): void
    {
        $master = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'ws-'.$master->id,
            'owner_id' => $master->id,
        ]);
        $workspace->ensureSlug();
        $master->update(['workspace_id' => $workspace->id]);
        $master->refresh(); // Ensure workspace relationship is loaded

        // Create existing entitlement ending in 30 days
        $horizonEnd = now()->addDays(30);
        $billingSub = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::Active,
        ]);

        BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now()->subDays(30),
            'period_end' => $horizonEnd,
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'origin' => BillingCycleOrigin::AdminGrant,
        ]);

        PlanPrice::create([
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'base_amount' => 490,
            'discount_percent' => 0,
            'final_amount' => 490,
            'currency' => 'RUB',
            'version' => 1,
            'valid_from' => now(),
            'is_active' => true,
        ]);

        // Verify horizon is readable
        $horizon = app(BillingHorizon::class);
        $foundEnd = $horizon->currentGrantingEnd($workspace, 'pro');
        $this->assertNotNull($foundEnd, 'BillingHorizon should find existing granting cycle');
        $this->assertEquals($horizonEnd->timestamp, $foundEnd->timestamp);

        $service = app(BillingService::class);
        $result = $service->subscribe($master, $this->proPlan, 1);

        // Starts after existing horizon, not from now
        $this->assertEqualsWithDelta($horizonEnd->timestamp, $result['subscription']->starts_at->timestamp, 2);
        $this->assertTrue($result['subscription']->expires_at->greaterThan($horizonEnd));
    }

    public function test_expired_core_horizon_starts_from_now(): void
    {
        $master = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'ws-'.$master->id,
            'owner_id' => $master->id,
        ]);
        $workspace->ensureSlug();
        $master->update(['workspace_id' => $workspace->id]);

        // Create expired entitlement
        $horizonEnd = now()->subDays(10);
        $billingSub = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::Active,
        ]);

        BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now()->subDays(40),
            'period_end' => $horizonEnd,
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'origin' => BillingCycleOrigin::AdminGrant,
        ]);

        PlanPrice::create([
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'base_amount' => 490,
            'discount_percent' => 0,
            'final_amount' => 490,
            'currency' => 'RUB',
            'version' => 1,
            'valid_from' => now(),
            'is_active' => true,
        ]);

        $service = app(BillingService::class);
        $result = $service->subscribe($master, $this->proPlan, 1);

        // Starts from approximately now (within a few seconds)
        $this->assertTrue($result['subscription']->starts_at->diffInSeconds(now()) <= 2);
    }

    // ═══════════════════════════════════════════
    // Downgrade: Core-first
    // ═══════════════════════════════════════════

    public function test_downgrade_uses_core_plan_not_legacy(): void
    {
        $billingService = app(BillingService::class);

        $proPlan = $this->proPlan;

        $startPlan = TariffPlan::create([
            'code' => 'start',
            'name' => 'Старт',
            'price_monthly' => 290,
            'max_masters' => 3,
            'features' => [],
            'is_active' => true,
        ]);

        $owner = User::factory()->master()->create(['is_service_provider' => true]);
        \Illuminate\Support\Facades\DB::table('users')->where('id', $owner->id)->update(['role' => 'owner']);
        $workspace = Workspace::create([
            'name' => 'Studio',
            'owner_id' => $owner->id,
        ]);
        $workspace->ensureSlug();
        $owner->update(['workspace_id' => $workspace->id]);
        $owner->refresh();

        // Core entitlement says "pro" (unlimited masters)
        $billingSub = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $proPlan->id,
            'status' => BillingSubscriptionStatus::Active,
        ]);

        BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $proPlan->id,
            'period_start' => now()->subMonth(),
            'period_end' => now()->addMonth(),
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'origin' => BillingCycleOrigin::AdminGrant,
        ]);

        // Legacy subscription says something different (should be ignored)
        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $startPlan->id,
            'period_months' => 1,
            'amount_paid' => 290,
            'status' => 'active',
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addMonth(),
        ]);

        // 4 providers: blocks downgrade to start (max_masters=3)
        User::factory()->master()->create([
            'workspace_id' => $workspace->id,
            'is_service_provider' => true,
        ]);
        User::factory()->master()->create([
            'workspace_id' => $workspace->id,
            'is_service_provider' => true,
        ]);
        User::factory()->master()->create([
            'workspace_id' => $workspace->id,
            'is_service_provider' => true,
        ]);

        // Refresh workspace to clear cached relationship
        $workspace->refresh();
        $owner->refresh();

        $this->assertEquals(4, $workspace->providersCount());

        // Verify Core entitlement exists
        $entitlement = app(EntitlementService::class);
        $plan = $entitlement->currentPlan($workspace);
        $this->assertNotNull($plan, 'Core entitlement should exist for workspace');

        // Reload owner fresh from DB to avoid stale relationships
        $freshOwner = User::find($owner->id);
        $this->assertNotNull($freshOwner->workspace_id, 'Owner should have workspace_id');

        // Should block because Core says pro (unlimited) → start (3), and we have 4 providers
        $reason = $billingService->downgradeBlockReason($freshOwner, $startPlan);
        $this->assertIsString($reason);
        $this->assertStringContainsString('4 провайдеров', $reason);
    }

    // ═══════════════════════════════════════════
    // Admin grant: Core-first + legacy mirror
    // ═══════════════════════════════════════════

    public function test_admin_grant_creates_core_cycle(): void
    {
        $master = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'ws-'.$master->id,
            'owner_id' => $master->id,
        ]);
        $workspace->ensureSlug();
        $master->update(['workspace_id' => $workspace->id]);

        $legacy = Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 0,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);

        $coreWriter = app(\App\Services\Billing\BillingCoreWriter::class);
        $coreWriter->adminGrant(
            $workspace->id,
            $this->proPlan,
            $legacy,
            now()->toDateTimeString(),
            now()->addDays(30)->toDateTimeString(),
        );

        $cycle = BillingCycle::where('workspace_id', $workspace->id)
            ->where('origin', BillingCycleOrigin::AdminGrant)
            ->first();

        $this->assertNotNull($cycle);
        $this->assertSame(BillingCycleStatus::Paid, $cycle->status);
        $this->assertSame($legacy->id, $cycle->legacy_subscription_id);

        // Core entitlement is valid
        $entitlement = app(EntitlementService::class);
        $this->assertTrue($entitlement->hasPlan($workspace, 'pro'));
    }

    // ═══════════════════════════════════════════
    // Expiration reminders: Core horizon
    // ═══════════════════════════════════════════

    public function test_reminder_uses_core_horizon_not_legacy(): void
    {
        $master = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'ws-'.$master->id,
            'owner_id' => $master->id,
        ]);
        $workspace->ensureSlug();
        $master->update(['workspace_id' => $workspace->id]);

        // Core horizon ends in exactly 5 days
        $coreEnd = now()->addDays(5)->startOfDay();

        $billingSub = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::Active,
        ]);

        BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => $coreEnd->copy()->subMonth(),
            'period_end' => $coreEnd,
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'origin' => BillingCycleOrigin::AdminGrant,
        ]);

        // Legacy says something different (20 days away) — should be ignored
        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => 'active',
            'starts_at' => now()->subDays(10),
            'expires_at' => now()->addDays(20),
        ]);

        // Verify Core sees the correct expiry
        $entitlement = app(EntitlementService::class);
        $plan = $entitlement->currentPlan($workspace);
        $this->assertNotNull($plan);
        $this->assertTrue($plan->expiresAt->startOfDay()->eq($coreEnd));
    }

    // ═══════════════════════════════════════════
    // Legacy rollback (flag=false)
    // ═══════════════════════════════════════════

    public function test_legacy_read_works_when_flag_false(): void
    {
        config(['billing.core_entitlement' => false]);

        $master = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'ws-'.$master->id,
            'owner_id' => $master->id,
        ]);
        $workspace->ensureSlug();
        $master->update(['workspace_id' => $workspace->id]);
        $master->refresh();

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => 'active',
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addMonth(),
        ]);

        // Legacy still works
        $active = $workspace->activeSubscription();
        $this->assertNotNull($active);
        $this->assertSame($this->proPlan->id, $active->tariff_plan_id);

        // hasFeature falls back to legacy
        $this->assertTrue($workspace->hasFeature('unlimited_appointments'));
    }
}
