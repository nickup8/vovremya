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

    // ── 1. Fresh dry-run shows to-create counts, DB unchanged ──

    public function test_dry_run_shows_plan_without_writing(): void
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
            'payment_id' => 'mock_dry_1',
        ]);

        $service = app(LegacyProjectionService::class);
        $stats = $service->projectAll(dryRun: true);

        $this->assertSame(1, $stats['found']['workspaces']);
        $this->assertSame(1, $stats['found']['legacy_period_groups']);
        $this->assertSame(1, $stats['found']['legacy_payment_rows']);

        $this->assertSame(1, $stats['to_create']['canonical_subscriptions']);
        $this->assertSame(1, $stats['to_create']['billing_cycles']);
        $this->assertSame(1, $stats['to_create']['payment_attempts']);

        // DB must be completely empty
        $this->assertDatabaseCount('billing_subscriptions', 0);
        $this->assertDatabaseCount('billing_cycles', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    // ── 2. Actual projection creates expected records ──

    public function test_actual_projection_creates_records(): void
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
            'payment_id' => 'mock_actual_1',
        ]);

        $service = app(LegacyProjectionService::class);
        $stats = $service->projectAll(dryRun: false);

        $this->assertSame(1, $stats['created']['canonical_subscriptions']);
        $this->assertSame(1, $stats['created']['billing_cycles']);
        $this->assertSame(1, $stats['created']['payment_attempts']);

        $this->assertDatabaseCount('billing_subscriptions', 1);
        $this->assertDatabaseCount('billing_cycles', 1);
        $this->assertDatabaseCount('payment_attempts', 1);
    }

    // ── 3. Dry-run after projection: to-create 0/0/0, DB unchanged ──

    public function test_dry_run_after_projection_shows_zero_to_create(): void
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
            'payment_id' => 'mock_post_dry',
        ]);

        $service = app(LegacyProjectionService::class);

        // First: actual projection
        $service->projectAll(dryRun: false);

        // Then: dry-run should show 0 to-create
        $stats = $service->projectAll(dryRun: true);

        $this->assertSame(0, $stats['to_create']['canonical_subscriptions']);
        $this->assertSame(0, $stats['to_create']['billing_cycles']);
        $this->assertSame(0, $stats['to_create']['payment_attempts']);

        $this->assertSame(1, $stats['already_projected']['canonical_subscriptions']);
        $this->assertSame(1, $stats['already_projected']['billing_cycles']);
        $this->assertSame(1, $stats['already_projected']['payment_attempts']);
    }

    // ── 4. Actual rerun: 0 new rows ──

    public function test_rerun_creates_no_duplicates(): void
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
            'payment_id' => 'mock_rerun_1',
        ]);

        $service = app(LegacyProjectionService::class);

        $stats1 = $service->projectAll(dryRun: false);
        $stats2 = $service->projectAll(dryRun: false);

        $this->assertSame(1, $stats1['created']['payment_attempts']);
        $this->assertSame(0, $stats2['created']['payment_attempts']);

        $this->assertDatabaseCount('payment_attempts', 1);
    }

    // ── 5. Three failed rows: ONE cycle, THREE attempts, numbers 1/2/3 ──

    public function test_three_failed_rows_correct_numbering(): void
    {
        $workspace = $this->createWorkspaceWithOwner();

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Failed->value,
            'starts_at' => '2026-08-21 00:00:00',
            'expires_at' => '2026-09-21 00:00:00',
            'payment_id' => 'mock_fail_a',
            'created_at' => '2026-08-21 10:00:00',
        ]);

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Failed->value,
            'starts_at' => '2026-08-21 00:00:00',
            'expires_at' => '2026-09-21 00:00:00',
            'payment_id' => 'mock_fail_b',
            'created_at' => '2026-08-21 11:00:00',
        ]);

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Failed->value,
            'starts_at' => '2026-08-21 00:00:00',
            'expires_at' => '2026-09-21 00:00:00',
            'payment_id' => 'mock_fail_c',
            'created_at' => '2026-08-21 12:00:00',
        ]);

        $service = app(LegacyProjectionService::class);
        $service->projectAll(dryRun: false);

        $billingSub = BillingSubscription::where('workspace_id', $workspace->id)->first();
        $cycle = BillingCycle::where('billing_subscription_id', $billingSub->id)->first();
        $this->assertSame(BillingCycleStatus::Failed, $cycle->status);

        $attempts = PaymentAttempt::where('billing_cycle_id', $cycle->id)
            ->orderBy('attempt_number')
            ->get();

        $this->assertCount(3, $attempts);
        $this->assertSame(1, $attempts[0]->attempt_number);
        $this->assertSame(2, $attempts[1]->attempt_number);
        $this->assertSame(3, $attempts[2]->attempt_number);
    }

    // ── 6. Numbering stable on rerun ──

    public function test_numbering_stable_on_rerun(): void
    {
        $workspace = $this->createWorkspaceWithOwner();

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Failed->value,
            'starts_at' => '2026-08-21 00:00:00',
            'expires_at' => '2026-09-21 00:00:00',
            'payment_id' => 'mock_stable_1',
            'created_at' => '2026-08-21 10:00:00',
        ]);

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Failed->value,
            'starts_at' => '2026-08-21 00:00:00',
            'expires_at' => '2026-09-21 00:00:00',
            'payment_id' => 'mock_stable_2',
            'created_at' => '2026-08-21 11:00:00',
        ]);

        $service = app(LegacyProjectionService::class);

        $service->projectAll(dryRun: false);
        $service->projectAll(dryRun: false);

        $billingSub = BillingSubscription::where('workspace_id', $workspace->id)->first();
        $cycle = BillingCycle::where('billing_subscription_id', $billingSub->id)->first();

        $attempts = PaymentAttempt::where('billing_cycle_id', $cycle->id)
            ->orderBy('attempt_number')
            ->get();

        $this->assertSame(1, $attempts[0]->attempt_number);
        $this->assertSame(2, $attempts[1]->attempt_number);
    }

    // ── 7. Legacy failed: status Unknown, metadata correct ──

    public function test_legacy_failed_maps_to_unknown(): void
    {
        $workspace = $this->createWorkspaceWithOwner();

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Failed->value,
            'starts_at' => '2026-08-21 00:00:00',
            'expires_at' => '2026-09-21 00:00:00',
            'payment_id' => 'mock_unknown_1',
        ]);

        $service = app(LegacyProjectionService::class);
        $service->projectAll(dryRun: false);

        $attempt = PaymentAttempt::where('internal_order_id', 'mock_unknown_1')->first();
        $this->assertSame(PaymentAttemptStatus::Unknown, $attempt->status);
        $this->assertNull($attempt->failure_code);
        $this->assertNull($attempt->failure_category);
        $this->assertNull($attempt->failure_message);

        $metadata = $attempt->metadata;
        $this->assertTrue($metadata['legacy']);
        $this->assertSame('legacy_unknown', $metadata['failure_source']);
        $this->assertNotNull($metadata['legacy_subscription_id']);
    }

    // ── 8. Successful legacy: status Succeeded ──

    public function test_legacy_succeeded_maps_to_succeeded(): void
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
            'payment_id' => 'mock_succeeded_1',
        ]);

        $service = app(LegacyProjectionService::class);
        $service->projectAll(dryRun: false);

        $attempt = PaymentAttempt::where('internal_order_id', 'mock_succeeded_1')->first();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
    }

    // ── 9. Legacy grant: paid, origin legacy_grant, amount 0, 0 attempts ──

    public function test_legacy_grant_correct_semantics(): void
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
        $service->projectAll(dryRun: false);

        $billingSub = BillingSubscription::where('workspace_id', $workspace->id)->first();
        $cycle = BillingCycle::where('billing_subscription_id', $billingSub->id)->first();

        $this->assertSame(BillingCycleStatus::Paid, $cycle->status);
        $this->assertSame(BillingCycleOrigin::LegacyGrant, $cycle->origin);
        $this->assertSame(0, $cycle->amount);

        $this->assertDatabaseCount('payment_attempts', 0);
    }

    // ── 10. Failed + succeeded same period: NOT ambiguous ──

    public function test_failed_plus_succeeded_not_ambiguous(): void
    {
        $workspace = $this->createWorkspaceWithOwner();

        // Failed attempt first
        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Failed->value,
            'starts_at' => '2026-07-21 00:00:00',
            'expires_at' => '2026-08-21 00:00:00',
            'payment_id' => 'mock_retry_fail',
            'created_at' => '2026-07-21 10:00:00',
        ]);

        // Then successful attempt (retry succeeded)
        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => '2026-07-21 00:00:00',
            'expires_at' => '2026-08-21 00:00:00',
            'payment_id' => 'mock_retry_ok',
            'created_at' => '2026-07-21 11:00:00',
        ]);

        $service = app(LegacyProjectionService::class);
        $stats = $service->projectAll(dryRun: false);

        $this->assertEmpty($stats['ambiguous_groups']);

        $billingSub = BillingSubscription::where('workspace_id', $workspace->id)->first();
        $cycle = BillingCycle::where('billing_subscription_id', $billingSub->id)->first();
        $this->assertSame(BillingCycleStatus::Paid, $cycle->status);

        $attempts = PaymentAttempt::where('billing_cycle_id', $cycle->id)
            ->orderBy('attempt_number')
            ->get();
        $this->assertCount(2, $attempts);
    }

    // ── 11. Genuinely ambiguous: multiple active rows → skipped ──

    public function test_genuinely_ambiguous_group_skipped(): void
    {
        $workspace = $this->createWorkspaceWithOwner();

        // Two active rows for same period = ambiguous
        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => '2026-07-21 00:00:00',
            'expires_at' => '2026-08-21 00:00:00',
            'payment_id' => 'mock_ambig_1',
        ]);

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => '2026-07-21 00:00:00',
            'expires_at' => '2026-08-21 00:00:00',
            'payment_id' => 'mock_ambig_2',
        ]);

        $service = app(LegacyProjectionService::class);
        $stats = $service->projectAll(dryRun: false);

        $this->assertNotEmpty($stats['ambiguous_groups']);
        // The ambiguous cycle should not be created
        $this->assertDatabaseCount('billing_cycles', 0);
    }

    // ── 12. Inconsistent amounts: ambiguous ──

    public function test_inconsistent_amounts_ambiguous(): void
    {
        $workspace = $this->createWorkspaceWithOwner();

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Failed->value,
            'starts_at' => '2026-07-21 00:00:00',
            'expires_at' => '2026-08-21 00:00:00',
            'payment_id' => 'mock_amt_1',
        ]);

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 690,
            'status' => SubscriptionStatus::Failed->value,
            'starts_at' => '2026-07-21 00:00:00',
            'expires_at' => '2026-08-21 00:00:00',
            'payment_id' => 'mock_amt_2',
        ]);

        $service = app(LegacyProjectionService::class);
        $stats = $service->projectAll(dryRun: false);

        $this->assertNotEmpty($stats['ambiguous_groups']);
        $this->assertDatabaseCount('billing_cycles', 0);
    }

    // ── 13. Start plan: no billing subscription created ──

    public function test_start_workspace_gets_no_billing_subscription(): void
    {
        $workspace = $this->createWorkspaceWithOwner();
        $startPlan = $this->createStartPlan();

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
        $stats = $service->projectAll(dryRun: false);

        $this->assertSame(0, $stats['created']['canonical_subscriptions']);
        $this->assertDatabaseCount('billing_subscriptions', 0);
    }

    // ── 14. Failed attempts do not extend entitlement ──

    public function test_failed_attempts_do_not_extend_entitlement(): void
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
            'payment_id' => 'mock_ent_ok',
        ]);

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Failed->value,
            'starts_at' => '2026-08-21 00:00:00',
            'expires_at' => '2026-09-21 00:00:00',
            'payment_id' => 'mock_ent_fail',
        ]);

        $service = app(LegacyProjectionService::class);
        $service->projectAll(dryRun: false);

        $billingSub = BillingSubscription::where('workspace_id', $workspace->id)->first();
        $this->assertSame('2026-08-21 00:00:00', $billingSub->current_period_end->format('Y-m-d H:i:s'));
    }

    // ── 15. Mixed grant + paid periods ──

    public function test_mixed_grant_and_paid_periods(): void
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

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => '2027-07-21 00:00:00',
            'expires_at' => '2027-08-21 00:00:00',
            'payment_id' => 'mock_mixed_paid',
        ]);

        $service = app(LegacyProjectionService::class);
        $stats = $service->projectAll(dryRun: false);

        $this->assertSame(1, $stats['created']['canonical_subscriptions']);
        $this->assertSame(2, $stats['created']['billing_cycles']);
        $this->assertSame(1, $stats['created']['payment_attempts']);

        $billingSub = BillingSubscription::where('workspace_id', $workspace->id)->first();
        $cycles = BillingCycle::where('billing_subscription_id', $billingSub->id)
            ->orderBy('period_start')
            ->get();

        $this->assertCount(2, $cycles);
        $this->assertSame(BillingCycleOrigin::LegacyGrant, $cycles[0]->origin);
        $this->assertSame(0, $cycles[0]->amount);
        $this->assertSame(BillingCycleOrigin::Payment, $cycles[1]->origin);
        $this->assertSame(490, $cycles[1]->amount);
    }
}
