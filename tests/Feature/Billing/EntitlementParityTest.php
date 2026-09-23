<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\SubscriptionStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\Workspace;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\PlanAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntitlementParityTest extends TestCase
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
            'max_appointments_per_month' => null,
            'max_masters' => 1,
            'features' => ['unlimited_appointments'],
            'is_active' => true,
        ]);
    }

    private function createWorkspace(): Workspace
    {
        $user = \App\Models\User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'ws-'.$user->id,
            'owner_id' => $user->id,
        ]);
        $workspace->ensureSlug();
        $user->update(['workspace_id' => $workspace->id]);

        return $workspace;
    }

    private function createBillingSub(Workspace $ws, TariffPlan $plan): BillingSubscription
    {
        return BillingSubscription::create([
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $plan->id,
            'status' => \App\Enums\BillingSubscriptionStatus::Active,
        ]);
    }

    // ── 1. Start == Start ──

    public function test_start_workspace_matches_core(): void
    {
        $ws = $this->createWorkspace();

        $legacy = app(PlanAccessService::class);
        $core = app(EntitlementService::class);

        $this->assertSame('start', $legacy->currentPlanCode($ws));
        $this->assertNull($core->currentPlan($ws));
    }

    // ── 2. Active legacy Pro projected to Core == match ──

    public function test_active_pro_matches_core(): void
    {
        $ws = $this->createWorkspace();

        Subscription::create([
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subMonth()->toDateTimeString(),
            'expires_at' => now()->addMonth()->toDateTimeString(),
            'payment_id' => 'mock_parity_pro',
        ]);

        // Mirror to core
        $sub = $this->createBillingSub($ws, $this->proPlan);
        BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now()->subMonth()->toDateTimeString(),
            'period_end' => now()->addMonth()->toDateTimeString(),
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'origin' => BillingCycleOrigin::LegacyGrant,
        ]);

        $legacy = app(PlanAccessService::class);
        $core = app(EntitlementService::class);

        $this->assertSame('pro', $legacy->currentPlanCode($ws));
        $this->assertTrue($core->hasPlan($ws, 'pro'));
        $this->assertSame(
            $ws->activeSubscription()->expires_at->format('Y-m-d H:i:s'),
            $core->entitlementEnd($ws, 'pro')?->format('Y-m-d H:i:s')
        );
    }

    // ── 3. Stacked future-active legacy row == match ──

    public function test_future_active_legacy_matches_core(): void
    {
        $ws = $this->createWorkspace();

        Subscription::create([
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->addDays(1)->toDateTimeString(),
            'expires_at' => now()->addMonth()->toDateTimeString(),
            'payment_id' => 'mock_parity_future',
        ]);

        // Core mirrors the future row
        $sub = $this->createBillingSub($ws, $this->proPlan);
        BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now()->addDays(1)->toDateTimeString(),
            'period_end' => now()->addMonth()->toDateTimeString(),
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'origin' => BillingCycleOrigin::LegacyGrant,
        ]);

        $legacy = app(PlanAccessService::class);
        $core = app(EntitlementService::class);

        $this->assertSame('pro', $legacy->currentPlanCode($ws));
        $this->assertTrue($core->hasPlan($ws, 'pro'));
    }

    // ── 4. Intentional mismatch → verifier reports ──

    public function test_verifier_detects_mismatch(): void
    {
        $ws = $this->createWorkspace();

        // Legacy: active Pro with future expiry
        Subscription::create([
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subMonth()->toDateTimeString(),
            'expires_at' => now()->addMonth()->toDateTimeString(),
            'payment_id' => 'mock_parity_mismatch',
        ]);

        // Core: no granting cycle for this workspace → mismatch

        $this->artisan('billing:verify-entitlement')
            ->assertExitCode(1);
    }

    // ── 5. Verifier performs zero writes ──

    public function test_verifier_performs_zero_writes(): void
    {
        $ws = $this->createWorkspace();

        Subscription::create([
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subMonth()->toDateTimeString(),
            'expires_at' => now()->addMonth()->toDateTimeString(),
            'payment_id' => 'mock_parity_nowrite',
        ]);

        // Mirror to core so they match
        $sub = $this->createBillingSub($ws, $this->proPlan);
        BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now()->subMonth()->toDateTimeString(),
            'period_end' => now()->addMonth()->toDateTimeString(),
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'origin' => BillingCycleOrigin::LegacyGrant,
        ]);

        $beforeCycleCount = BillingCycle::count();
        $beforeSubCount = BillingSubscription::count();

        $this->artisan('billing:verify-entitlement')->assertExitCode(0);

        $this->assertSame($beforeCycleCount, BillingCycle::count());
        $this->assertSame($beforeSubCount, BillingSubscription::count());
    }
}
