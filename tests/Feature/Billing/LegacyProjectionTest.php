<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\SubscriptionStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\PaymentAttempt;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\LegacyProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyProjectionTest extends TestCase
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

    private function createWorkspaceWithOwner(): Workspace
    {
        $master = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'ws-'.$master->id,
            'owner_id' => $master->id,
        ]);
        $workspace->ensureSlug();
        $master->update(['workspace_id' => $workspace->id]);

        return $workspace;
    }

    private function createStartPlan(): TariffPlan
    {
        return TariffPlan::create([
            'code' => 'start',
            'name' => 'Старт',
            'price_monthly' => 0,
            'max_appointments_per_month' => 30,
            'max_masters' => 1,
            'features' => ['calendar'],
            'is_active' => true,
        ]);
    }

    // ── 1. Admin grant: one cycle, amount 0, no payment attempt ──

    public function test_admin_grant_projects_one_cycle_no_attempt(): void
    {
        $workspace = $this->createWorkspaceWithOwner();

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 12,
            'amount_paid' => 0,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => '2026-07-21 00:00:00',
            'expires_at' => '2027-07-21 00:00:00',
            'payment_id' => null,
        ]);

        $service = app(LegacyProjectionService::class);
        $stats = $service->projectAll();

        $this->assertSame(1, $stats['canonical_subscriptions_to_create']);
        $this->assertSame(1, $stats['billing_cycles_to_create']);
        $this->assertSame(0, $stats['payment_attempts_to_create']);
        $this->assertSame(1, $stats['admin_grants']);

        $billingSub = BillingSubscription::where('workspace_id', $workspace->id)->first();
        $this->assertNotNull($billingSub);
        $this->assertSame(BillingSubscriptionStatus::Active, $billingSub->status);

        $cycle = BillingCycle::where('billing_subscription_id', $billingSub->id)->first();
        $this->assertNotNull($cycle);
        $this->assertSame(0, $cycle->amount);
        $this->assertSame(BillingCycleOrigin::LegacyGrant, $cycle->origin);
        $this->assertSame(BillingCycleStatus::Paid, $cycle->status);

        $this->assertDatabaseCount('payment_attempts', 0);
    }

    // ── 2. Paid cycle: one cycle, one succeeded attempt ──

    public function test_paid_cycle_projects_one_cycle_one_attempt(): void
    {
        $workspace = $this->createWorkspaceWithOwner();

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => '2026-07-21 00:00:00',
            'expires_at' => '2026-08-21 00:00:00',
            'payment_id' => 'mock_paid_cycle_1',
        ]);

        $service = app(LegacyProjectionService::class);
        $stats = $service->projectAll();

        $this->assertSame(1, $stats['canonical_subscriptions_to_create']);
        $this->assertSame(1, $stats['billing_cycles_to_create']);
        $this->assertSame(1, $stats['payment_attempts_to_create']);

        $billingSub = BillingSubscription::where('workspace_id', $workspace->id)->first();
        $cycle = BillingCycle::where('billing_subscription_id', $billingSub->id)->first();
        $this->assertSame(490, $cycle->amount);
        $this->assertSame(BillingCycleOrigin::Payment, $cycle->origin);

        $attempt = PaymentAttempt::where('internal_order_id', 'mock_paid_cycle_1')->first();
        $this->assertNotNull($attempt);
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
        $this->assertSame(490, $attempt->amount);
        $this->assertSame('mock', $attempt->provider);
    }

    // ── 3. Three failed legacy rows same period: ONE cycle, THREE attempts ──

    public function test_three_failed_rows_same_period_one_cycle_three_attempts(): void
    {
        $workspace = $this->createWorkspaceWithOwner();

        // Create three failed subscription rows for the same period
        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Failed->value,
            'starts_at' => '2026-08-21 00:00:00',
            'expires_at' => '2026-09-21 00:00:00',
            'payment_id' => 'mock_attempt_1',
        ]);

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Failed->value,
            'starts_at' => '2026-08-21 00:00:00',
            'expires_at' => '2026-09-21 00:00:00',
            'payment_id' => 'mock_attempt_2',
        ]);

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Failed->value,
            'starts_at' => '2026-08-21 00:00:00',
            'expires_at' => '2026-09-21 00:00:00',
            'payment_id' => 'mock_attempt_3',
        ]);

        $service = app(LegacyProjectionService::class);
        $stats = $service->projectAll();

        $this->assertSame(1, $stats['canonical_subscriptions_to_create']);
        $this->assertSame(1, $stats['billing_cycles_to_create']);
        $this->assertSame(3, $stats['payment_attempts_to_create']);

        $billingSub = BillingSubscription::where('workspace_id', $workspace->id)->first();
        $cycles = BillingCycle::where('billing_subscription_id', $billingSub->id)->get();
        $this->assertCount(1, $cycles);
        $this->assertSame(BillingCycleStatus::Failed, $cycles->first()->status);

        $attempts = PaymentAttempt::where('billing_cycle_id', $cycles->first()->id)->get();
        $this->assertCount(3, $attempts);
        foreach ($attempts as $attempt) {
            $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        }
    }

    // ── 4. Failed attempts do not extend entitlement horizon ──

    public function test_failed_attempts_do_not_extend_entitlement(): void
    {
        $workspace = $this->createWorkspaceWithOwner();

        // Successful paid period
        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => '2026-07-21 00:00:00',
            'expires_at' => '2026-08-21 00:00:00',
            'payment_id' => 'mock_good_period',
        ]);

        // Failed period (should NOT extend entitlement)
        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Failed->value,
            'starts_at' => '2026-08-21 00:00:00',
            'expires_at' => '2026-09-21 00:00:00',
            'payment_id' => 'mock_failed_period',
        ]);

        $service = app(LegacyProjectionService::class);
        $stats = $service->projectAll();

        $billingSub = BillingSubscription::where('workspace_id', $workspace->id)->first();
        // Entitlement horizon should be 2026-08-21, NOT 2026-09-21
        $this->assertSame('2026-08-21 00:00:00', $billingSub->current_period_end->format('Y-m-d H:i:s'));
    }

    // ── 5. Command executed twice: no duplicates ──

    public function test_projection_idempotent_no_duplicates(): void
    {
        $workspace = $this->createWorkspaceWithOwner();

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => '2026-07-21 00:00:00',
            'expires_at' => '2026-08-21 00:00:00',
            'payment_id' => 'mock_idempotent_1',
        ]);

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Failed->value,
            'starts_at' => '2026-08-21 00:00:00',
            'expires_at' => '2026-09-21 00:00:00',
            'payment_id' => 'mock_idempotent_2',
        ]);

        $service = app(LegacyProjectionService::class);

        // Run twice
        $stats1 = $service->projectAll();
        $stats2 = $service->projectAll();

        $this->assertSame(1, $stats1['canonical_subscriptions_to_create']);
        $this->assertSame(2, $stats1['billing_cycles_to_create']);
        $this->assertSame(2, $stats1['payment_attempts_to_create']);

        // Second run should still report same counts
        // (idempotent: firstOrCreate won't create duplicates)
        $this->assertSame(1, $stats2['canonical_subscriptions_to_create']);
        $this->assertSame(2, $stats2['billing_cycles_to_create']);
        $this->assertSame(2, $stats2['payment_attempts_to_create']);

        // Verify no duplicates in DB
        $billingSubs = BillingSubscription::where('workspace_id', $workspace->id)->get();
        $this->assertCount(1, $billingSubs);

        $cycles = BillingCycle::where('billing_subscription_id', $billingSubs->first()->id)->get();
        $this->assertCount(2, $cycles);

        $attempts = PaymentAttempt::where('billing_cycle_id', $cycles->first()->id)
            ->orWhere('billing_cycle_id', $cycles->last()->id)
            ->get();
        $this->assertCount(2, $attempts);
    }

    // ── 6. Start/no-subscription workspace: no fake Pro subscription ──

    public function test_start_workspace_gets_no_billing_subscription(): void
    {
        $workspace = $this->createWorkspaceWithOwner();
        $startPlan = $this->createStartPlan();

        // Create a Start subscription (free tier)
        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $startPlan->id,
            'period_months' => 1,
            'amount_paid' => 0,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => '2026-07-21 00:00:00',
            'expires_at' => '2026-08-21 00:00:00',
        ]);

        $service = app(LegacyProjectionService::class);
        $stats = $service->projectAll();

        // No canonical subscription should be created for Start plan
        $this->assertSame(0, $stats['canonical_subscriptions_to_create']);
        $this->assertDatabaseCount('billing_subscriptions', 0);
        $this->assertDatabaseCount('billing_cycles', 0);
    }

    // ── Extra: mixed admin grant + paid periods ──

    public function test_mixed_grant_and_paid_periods(): void
    {
        $workspace = $this->createWorkspaceWithOwner();

        // Admin grant period (no payment)
        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 12,
            'amount_paid' => 0,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => '2026-07-21 00:00:00',
            'expires_at' => '2027-07-21 00:00:00',
            'payment_id' => null,
        ]);

        // Paid period
        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => '2027-07-21 00:00:00',
            'expires_at' => '2027-08-21 00:00:00',
            'payment_id' => 'mock_extended_period',
        ]);

        $service = app(LegacyProjectionService::class);
        $stats = $service->projectAll();

        $this->assertSame(1, $stats['canonical_subscriptions_to_create']);
        $this->assertSame(2, $stats['billing_cycles_to_create']);
        $this->assertSame(1, $stats['payment_attempts_to_create']);
        $this->assertSame(1, $stats['admin_grants']);

        $billingSub = BillingSubscription::where('workspace_id', $workspace->id)->first();
        $cycles = BillingCycle::where('billing_subscription_id', $billingSub->id)
            ->orderBy('period_start')
            ->get();

        $this->assertCount(2, $cycles);

        // First cycle: admin grant
        $this->assertSame(BillingCycleOrigin::LegacyGrant, $cycles[0]->origin);
        $this->assertSame(0, $cycles[0]->amount);

        // Second cycle: payment
        $this->assertSame(BillingCycleOrigin::Payment, $cycles[1]->origin);
        $this->assertSame(490, $cycles[1]->amount);
    }
}
