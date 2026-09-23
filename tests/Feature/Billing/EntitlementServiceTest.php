<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\PaymentAttemptStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\PaymentAttempt;
use App\Models\TariffPlan;
use App\Models\Workspace;
use App\Services\Billing\EntitlementService;
use App\Support\PlanDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntitlementServiceTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;
    private TariffPlan $startPlan;

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

        $this->startPlan = TariffPlan::create([
            'code' => 'start',
            'name' => 'Старт',
            'price_monthly' => 0,
            'max_appointments_per_month' => 30,
            'max_masters' => 1,
            'features' => ['calendar', 'basic_client_management'],
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

    // ── Granting rules ──

    public function test_legacy_grant_paid_entitles(): void
    {
        $ws = $this->createWorkspace();
        $sub = $this->createBillingSub($ws, $this->proPlan);

        BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => '2026-07-21 00:00:00',
            'period_end' => '2027-07-21 00:00:00',
            'status' => BillingCycleStatus::Paid,
            'amount' => 0,
            'origin' => BillingCycleOrigin::LegacyGrant,
        ]);

        $service = app(EntitlementService::class);
        $this->assertTrue($service->hasPlan($ws, 'pro'));
    }

    public function test_admin_grant_paid_entitles(): void
    {
        $ws = $this->createWorkspace();
        $sub = $this->createBillingSub($ws, $this->proPlan);

        BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => '2026-07-21 00:00:00',
            'period_end' => '2027-07-21 00:00:00',
            'status' => BillingCycleStatus::Paid,
            'amount' => 0,
            'origin' => BillingCycleOrigin::AdminGrant,
        ]);

        $service = app(EntitlementService::class);
        $this->assertTrue($service->hasPlan($ws, 'pro'));
    }

    public function test_payment_succeeded_entitles(): void
    {
        $ws = $this->createWorkspace();
        $sub = $this->createBillingSub($ws, $this->proPlan);
        $cycle = BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => '2026-07-21 00:00:00',
            'period_end' => now()->addMonth()->toDateTimeString(),
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'origin' => BillingCycleOrigin::Payment,
        ]);

        PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'mock',
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'mock_ent_suc',
            'status' => PaymentAttemptStatus::Succeeded,
            'initiated_at' => now(),
        ]);

        $service = app(EntitlementService::class);
        $this->assertTrue($service->hasPlan($ws, 'pro'));
    }

    public function test_payment_without_success_not_entitled(): void
    {
        $ws = $this->createWorkspace();
        $sub = $this->createBillingSub($ws, $this->proPlan);
        $cycle = BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => '2026-07-21 00:00:00',
            'period_end' => '2026-08-21 00:00:00',
            'status' => BillingCycleStatus::Failed,
            'amount' => 490,
            'origin' => BillingCycleOrigin::Payment,
        ]);

        PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'mock',
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'mock_ent_fail',
            'status' => PaymentAttemptStatus::Unknown,
            'initiated_at' => now(),
        ]);

        $service = app(EntitlementService::class);
        $this->assertFalse($service->hasPlan($ws, 'pro'));
    }

    public function test_refunded_cycle_not_entitled(): void
    {
        $ws = $this->createWorkspace();
        $sub = $this->createBillingSub($ws, $this->proPlan);

        BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => '2026-07-21 00:00:00',
            'period_end' => '2026-08-21 00:00:00',
            'status' => BillingCycleStatus::Refunded,
            'amount' => 490,
            'origin' => BillingCycleOrigin::Payment,
        ]);

        $service = app(EntitlementService::class);
        $this->assertFalse($service->hasPlan($ws, 'pro'));
    }

    // ── Timing rules ──

    public function test_future_start_entitled(): void
    {
        $ws = $this->createWorkspace();
        $sub = $this->createBillingSub($ws, $this->proPlan);

        BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now()->addDays(1)->toDateTimeString(),
            'period_end' => now()->addMonths(1)->toDateTimeString(),
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'origin' => BillingCycleOrigin::LegacyGrant,
        ]);

        // A1.2 parity behavior — period_start is intentionally not gated.
        $service = app(EntitlementService::class);
        $this->assertTrue($service->hasPlan($ws, 'pro'));
    }

    public function test_exact_end_not_entitled(): void
    {
        $ws = $this->createWorkspace();
        $sub = $this->createBillingSub($ws, $this->proPlan);
        $end = now()->addMonth();

        BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now()->toDateTimeString(),
            'period_end' => $end->toDateTimeString(),
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'origin' => BillingCycleOrigin::LegacyGrant,
        ]);

        $service = app(EntitlementService::class);
        // At exactly period_end, entitlement does NOT hold (strict >)
        $this->assertFalse($service->hasPlan($ws, 'pro', $end));
    }

    // ── Feature / limit delegation ──

    public function test_has_feature_from_granting_cycle(): void
    {
        $ws = $this->createWorkspace();
        $sub = $this->createBillingSub($ws, $this->proPlan);

        BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => '2026-07-21 00:00:00',
            'period_end' => '2027-07-21 00:00:00',
            'status' => BillingCycleStatus::Paid,
            'amount' => 0,
            'origin' => BillingCycleOrigin::LegacyGrant,
        ]);

        $service = app(EntitlementService::class);
        $this->assertTrue($service->hasFeature($ws, 'unlimited_appointments'));
        $this->assertFalse($service->hasFeature($ws, 'nonexistent_feature'));
    }

    public function test_start_fallback_features(): void
    {
        $ws = $this->createWorkspace();

        $service = app(EntitlementService::class);
        $this->assertTrue($service->hasFeature($ws, 'calendar'));
        $this->assertTrue($service->hasFeature($ws, 'basic_client_management'));
        $this->assertFalse($service->hasFeature($ws, 'unlimited_appointments'));
    }

    public function test_max_masters_from_granting_cycle(): void
    {
        $ws = $this->createWorkspace();
        $sub = $this->createBillingSub($ws, $this->proPlan);

        BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => '2026-07-21 00:00:00',
            'period_end' => '2027-07-21 00:00:00',
            'status' => BillingCycleStatus::Paid,
            'amount' => 0,
            'origin' => BillingCycleOrigin::LegacyGrant,
        ]);

        $service = app(EntitlementService::class);
        $this->assertSame(1, $service->maxMasters($ws));
    }

    public function test_start_fallback_max_masters(): void
    {
        $ws = $this->createWorkspace();

        $service = app(EntitlementService::class);
        $this->assertSame(PlanDefaults::START_MAX_MASTERS, $service->maxMasters($ws));
    }

    public function test_monthly_limit_start_fallback(): void
    {
        $ws = $this->createWorkspace();

        $service = app(EntitlementService::class);
        $this->assertSame(PlanDefaults::START_MAX_APPOINTMENTS, $service->monthlyLimit($ws));
    }

    // ── Gap: multiple cycles ──

    public function test_gap_between_cycles_uses_only_granting_facts(): void
    {
        $ws = $this->createWorkspace();
        $sub = $this->createBillingSub($ws, $this->proPlan);

        // Past cycle — expired
        BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => '2025-01-01 00:00:00',
            'period_end' => '2025-02-01 00:00:00',
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'origin' => BillingCycleOrigin::Payment,
        ]);

        // Future cycle — not yet started
        BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now()->addMonths(3)->toDateTimeString(),
            'period_end' => now()->addMonths(4)->toDateTimeString(),
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'origin' => BillingCycleOrigin::Payment,
        ]);

        $service = app(EntitlementService::class);
        // Between cycles: not entitled based on granting facts alone
        $this->assertFalse($service->hasPlan($ws, 'pro'));
    }

    // ── Entitlement end ──

    public function test_entitlement_end_returns_period_end(): void
    {
        $ws = $this->createWorkspace();
        $sub = $this->createBillingSub($ws, $this->proPlan);
        $end = '2027-07-21 00:00:00';

        BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => '2026-07-21 00:00:00',
            'period_end' => $end,
            'status' => BillingCycleStatus::Paid,
            'amount' => 0,
            'origin' => BillingCycleOrigin::LegacyGrant,
        ]);

        $service = app(EntitlementService::class);
        $this->assertSame('2027-07-21 00:00:00', $service->entitlementEnd($ws, 'pro')?->format('Y-m-d H:i:s'));
    }

    public function test_entitlement_end_null_for_start(): void
    {
        $ws = $this->createWorkspace();

        $service = app(EntitlementService::class);
        $this->assertNull($service->entitlementEnd($ws, 'start'));
    }

    // ── currentPlan ──

    public function test_current_plan_returns_descriptor(): void
    {
        $ws = $this->createWorkspace();
        $sub = $this->createBillingSub($ws, $this->proPlan);

        BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => '2026-07-21 00:00:00',
            'period_end' => '2027-07-21 00:00:00',
            'status' => BillingCycleStatus::Paid,
            'amount' => 0,
            'origin' => BillingCycleOrigin::LegacyGrant,
        ]);

        $service = app(EntitlementService::class);
        $plan = $service->currentPlan($ws);

        $this->assertNotNull($plan);
        $this->assertSame('pro', $plan->code);
        $this->assertSame('Профи', $plan->name);
        $this->assertSame(1, $plan->maxMasters);
        $this->assertNull($plan->monthlyLimit); // null = unlimited
        $this->assertSame(['unlimited_appointments'], $plan->features);
    }

    public function test_current_plan_null_when_no_granting_cycle(): void
    {
        $ws = $this->createWorkspace();

        $service = app(EntitlementService::class);
        $this->assertNull($service->currentPlan($ws));
    }

    public function test_failed_granting_status_not_entitled(): void
    {
        $ws = $this->createWorkspace();
        $sub = $this->createBillingSub($ws, $this->proPlan);

        // legacy_grant with Failed status — should NOT grant
        BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => '2026-07-21 00:00:00',
            'period_end' => '2027-07-21 00:00:00',
            'status' => BillingCycleStatus::Failed,
            'amount' => 0,
            'origin' => BillingCycleOrigin::LegacyGrant,
        ]);

        $service = app(EntitlementService::class);
        $this->assertFalse($service->hasPlan($ws, 'pro'));
    }
}
