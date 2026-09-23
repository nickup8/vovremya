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
use App\Services\Billing\LegacyProjectionRepairService;
use App\Services\Billing\LegacyProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyProjectionRepairTest extends TestCase
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

    private function projectLegacy(): void
    {
        app(LegacyProjectionService::class)->projectAll(dryRun: false);
    }

    // ── 1. Dry-run performs ZERO writes ──

    public function test_repair_dry_run_writes_nothing(): void
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
            'payment_id' => 'mock_repair_dry',
        ]);

        $this->projectLegacy();

        // Snapshot before
        $attemptBefore = PaymentAttempt::where('internal_order_id', 'mock_repair_dry')->first();
        $numberBefore = $attemptBefore->attempt_number;
        $statusBefore = $attemptBefore->status;

        $service = app(LegacyProjectionRepairService::class);
        $service->repair(dryRun: true);

        // Must be unchanged
        $attemptAfter = PaymentAttempt::where('internal_order_id', 'mock_repair_dry')->first();
        $this->assertSame($numberBefore, $attemptAfter->attempt_number);
        $this->assertSame($statusBefore->value, $attemptAfter->status->value);
    }

    // ── 2. Legacy 1/1/1 → reports desired 1/2/3 ──

    public function test_repair_dry_run_reports_correct_numbering(): void
    {
        $workspace = $this->createWorkspaceWithOwner();

        // Create 3 failed rows with same created_at (all get attempt_number=1 in projection)
        for ($i = 1; $i <= 3; $i++) {
            Subscription::create([
                'workspace_id' => $workspace->id,
                'tariff_plan_id' => $this->proPlan->id,
                'period_months' => 1,
                'amount_paid' => 490,
                'status' => SubscriptionStatus::Failed->value,
                'starts_at' => '2026-08-21 00:00:00',
                'expires_at' => '2026-09-21 00:00:00',
                'payment_id' => "mock_repair_num_{$i}",
                'created_at' => "2026-08-21 10:0{$i}:00",
            ]);
        }

        $this->projectLegacy();

        // Manually corrupt attempt numbers to simulate pre-T25 numbering
        $cycle = BillingCycle::first();
        $attempts = $cycle->paymentAttempts()->orderBy('initiated_at')->get();
        $attempts->each(fn ($a) => $a->update(['attempt_number' => 1]));

        $service = app(LegacyProjectionRepairService::class);
        $plan = $service->repair(dryRun: true);

        $this->assertNotEmpty($plan['numbering_changes']);
        $change = $plan['numbering_changes'][0];
        $this->assertSame([1, 1, 1], $change['current_numbers']);
        $this->assertSame([1, 2, 3], $change['desired_numbers']);
        // 3 attempts, current [1,1,1], desired [1,2,3]: 2 actually differ
        $this->assertSame(2, $plan['numbers_to_change']);
    }

    // ── 3. Actual repair → 1/2/3 ──

    public function test_repair_fixes_numbering(): void
    {
        $workspace = $this->createWorkspaceWithOwner();

        for ($i = 1; $i <= 3; $i++) {
            Subscription::create([
                'workspace_id' => $workspace->id,
                'tariff_plan_id' => $this->proPlan->id,
                'period_months' => 1,
                'amount_paid' => 490,
                'status' => SubscriptionStatus::Failed->value,
                'starts_at' => '2026-08-21 00:00:00',
                'expires_at' => '2026-09-21 00:00:00',
                'payment_id' => "mock_repair_fix_{$i}",
                'created_at' => "2026-08-21 10:0{$i}:00",
            ]);
        }

        $this->projectLegacy();

        $service = app(LegacyProjectionRepairService::class);
        $stats = $service->repair(dryRun: false);

        $this->assertGreaterThan(0, $stats['numbers_to_change']);

        $billingSub = BillingSubscription::where('workspace_id', $workspace->id)->first();
        $cycle = BillingCycle::where('billing_subscription_id', $billingSub->id)->first();

        $attempts = PaymentAttempt::where('billing_cycle_id', $cycle->id)
            ->orderBy('attempt_number')
            ->get();

        $this->assertSame(1, $attempts[0]->attempt_number);
        $this->assertSame(2, $attempts[1]->attempt_number);
        $this->assertSame(3, $attempts[2]->attempt_number);
    }

    // ── 4. Second repair → 0 changes ──

    public function test_repair_idempotent(): void
    {
        $workspace = $this->createWorkspaceWithOwner();

        for ($i = 1; $i <= 3; $i++) {
            Subscription::create([
                'workspace_id' => $workspace->id,
                'tariff_plan_id' => $this->proPlan->id,
                'period_months' => 1,
                'amount_paid' => 490,
                'status' => SubscriptionStatus::Failed->value,
                'starts_at' => '2026-08-21 00:00:00',
                'expires_at' => '2026-09-21 00:00:00',
                'payment_id' => "mock_repair_idem_{$i}",
                'created_at' => "2026-08-21 10:0{$i}:00",
            ]);
        }

        $this->projectLegacy();

        $service = app(LegacyProjectionRepairService::class);

        $stats1 = $service->repair(dryRun: false);
        $stats2 = $service->repair(dryRun: false);

        $this->assertGreaterThan(0, $stats1['numbers_to_change']);
        $this->assertSame(0, $stats2['numbers_to_change']);
        $this->assertSame(0, $stats2['statuses_fixed']);
        $this->assertSame(0, $stats2['metadata_enriched']);
    }

    // ── 5. failed_terminal legacy → unknown ──

    public function test_repair_fixes_failed_terminal_to_unknown(): void
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
            'payment_id' => 'mock_repair_ft',
        ]);

        $this->projectLegacy();

        // Manually set to failed_terminal to simulate pre-repair state
        PaymentAttempt::where('internal_order_id', 'mock_repair_ft')
            ->update(['status' => PaymentAttemptStatus::FailedTerminal]);

        $service = app(LegacyProjectionRepairService::class);
        $stats = $service->repair(dryRun: false);

        $this->assertGreaterThan(0, $stats['statuses_fixed']);

        $attempt = PaymentAttempt::where('internal_order_id', 'mock_repair_ft')->first();
        $this->assertSame(PaymentAttemptStatus::Unknown, $attempt->status);
    }

    // ── 6. Succeeded attempts unaffected ──

    public function test_repair_does_not_touch_succeeded(): void
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
            'payment_id' => 'mock_repair_suc',
        ]);

        $this->projectLegacy();

        $service = app(LegacyProjectionRepairService::class);
        $stats = $service->repair(dryRun: false);

        $this->assertSame(0, $stats['statuses_fixed']);

        $attempt = PaymentAttempt::where('internal_order_id', 'mock_repair_suc')->first();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
    }

    // ── 7. Non-legacy attempt unaffected ──

    public function test_repair_does_not_touch_non_legacy(): void
    {
        $workspace = $this->createWorkspaceWithOwner();

        $billingSub = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::Active,
        ]);

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => '2026-07-21 00:00:00',
            'period_end' => '2026-08-21 00:00:00',
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'origin' => BillingCycleOrigin::Payment,
        ]);

        // Non-legacy attempt (no metadata.legacy, provider != mock)
        $attempt = PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'tbank',
            'attempt_number' => 1,
            'amount' => 490,
            'internal_order_id' => 'real_payment_123',
            'status' => PaymentAttemptStatus::FailedTerminal,
            'failure_code' => 'insufficient_funds',
            'failure_category' => 'card_declined',
            'failure_message' => 'Недостаточно средств',
        ]);

        $service = app(LegacyProjectionRepairService::class);
        $stats = $service->repair(dryRun: false);

        $this->assertSame(0, $stats['statuses_fixed']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('insufficient_funds', $attempt->failure_code);
    }

    // ── 8. Canonical subscription fields unaffected ──

    public function test_repair_does_not_change_canonical_sub(): void
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
            'payment_id' => 'mock_repair_canonical',
        ]);

        $this->projectLegacy();

        $subBefore = BillingSubscription::where('workspace_id', $workspace->id)->first();
        $statusBefore = $subBefore->status;
        $periodEndBefore = $subBefore->current_period_end;

        $service = app(LegacyProjectionRepairService::class);
        $service->repair(dryRun: false);

        $subAfter = BillingSubscription::where('workspace_id', $workspace->id)->first();
        $this->assertSame($statusBefore->value, $subAfter->status->value);
        $this->assertSame($periodEndBefore->format('Y-m-d H:i:s'), $subAfter->current_period_end->format('Y-m-d H:i:s'));
    }

    // ── 9. Production fixture: current_period_end preserved ──

    public function test_repair_preserves_entitlement_horizon(): void
    {
        $workspace = $this->createWorkspaceWithOwner();

        // Grant period
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
            'expires_at' => '2027-08-21 15:57:16',
            'payment_id' => 'mock_horizon_ok',
        ]);

        // Failed period
        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Failed->value,
            'starts_at' => '2027-08-21 00:00:00',
            'expires_at' => '2027-09-21 00:00:00',
            'payment_id' => 'mock_horizon_fail',
        ]);

        $this->projectLegacy();

        $service = app(LegacyProjectionRepairService::class);
        $service->repair(dryRun: false);

        $billingSub = BillingSubscription::where('workspace_id', $workspace->id)->first();
        $this->assertSame('2027-08-21 15:57:16', $billingSub->current_period_end->format('Y-m-d H:i:s'));
    }

    // ── 10. Production-shaped regression: 3 attempts 1/1/1 without legacy=true ──

    public function test_production_shaped_fixture_numbering_and_status(): void
    {
        $workspace = $this->createWorkspaceWithOwner();

        $billingSub = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::Active,
            'current_period_start' => '2026-08-21 00:00:00',
            'current_period_end' => '2027-08-21 15:57:16',
        ]);

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => '2027-08-21 00:00:00',
            'period_end' => '2027-09-21 00:00:00',
            'status' => BillingCycleStatus::Failed,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Payment,
        ]);

        // 3 attempts: provider=mock, metadata has legacy_subscription_id but NOT legacy=true
        // attempt_number=1,1,1, status=failed_terminal, no failure_code/category/message
        for ($i = 1; $i <= 3; $i++) {
            PaymentAttempt::create([
                'billing_cycle_id' => $cycle->id,
                'provider' => 'mock',
                'attempt_number' => 1,
                'amount' => 490,
                'currency' => 'RUB',
                'internal_order_id' => "mock_prod_regression_{$i}",
                'provider_payment_id' => "mock_prod_regression_{$i}",
                'status' => PaymentAttemptStatus::FailedTerminal,
                'initiated_at' => "2027-08-21 10:0{$i}:00",
                'metadata' => [
                    'legacy_subscription_id' => "sub_row_{$i}",
                    // Note: NO 'legacy' => true here — tests third detection marker
                ],
            ]);
        }

        // Dry-run
        $service = app(LegacyProjectionRepairService::class);
        $plan = $service->repair(dryRun: true);

        $this->assertSame(2, $plan['numbers_to_change']);
        $this->assertSame(3, $plan['statuses_fixed']);
        $this->assertSame(3, $plan['metadata_enriched']);

        // DB unchanged after dry-run
        $attemptAfterDry = PaymentAttempt::where('internal_order_id', 'mock_prod_regression_1')->first();
        $this->assertSame(1, $attemptAfterDry->attempt_number);
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attemptAfterDry->status);
    }

    // ── 11. CLI outputs correct numbers_to_change ──

    public function test_cli_shows_correct_numbering_metric(): void
    {
        $workspace = $this->createWorkspaceWithOwner();

        $billingSub = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::Active,
        ]);

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => '2027-08-21 00:00:00',
            'period_end' => '2027-09-21 00:00:00',
            'status' => BillingCycleStatus::Failed,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Payment,
        ]);

        for ($i = 1; $i <= 3; $i++) {
            PaymentAttempt::create([
                'billing_cycle_id' => $cycle->id,
                'provider' => 'mock',
                'attempt_number' => 1,
                'amount' => 490,
                'currency' => 'RUB',
                'internal_order_id' => "mock_cli_{$i}",
                'status' => PaymentAttemptStatus::FailedTerminal,
                'initiated_at' => "2027-08-21 10:0{$i}:00",
                'metadata' => [
                    'legacy_subscription_id' => "cli_sub_{$i}",
                ],
            ]);
        }

        $this->artisan('billing:repair-legacy-projection', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsTable(['Metric', 'Count'], [
                ['Legacy cycles scanned', 1],
                ['Legacy attempts scanned', 3],
                ['Attempt numbers to change', 2],
                ['failed_terminal → unknown', 3],
                ['Metadata enrichments', 3],
                ['Cycles unchanged', 0],
            ]);
    }

    // ── 12. Actual repair on production fixture + second repair = 0 ──

    public function test_production_fixture_actual_repair_and_idempotent(): void
    {
        $workspace = $this->createWorkspaceWithOwner();

        $billingSub = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::Active,
            'current_period_start' => '2026-08-21 00:00:00',
            'current_period_end' => '2027-08-21 15:57:16',
        ]);

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => '2027-08-21 00:00:00',
            'period_end' => '2027-09-21 00:00:00',
            'status' => BillingCycleStatus::Failed,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Payment,
        ]);

        for ($i = 1; $i <= 3; $i++) {
            PaymentAttempt::create([
                'billing_cycle_id' => $cycle->id,
                'provider' => 'mock',
                'attempt_number' => 1,
                'amount' => 490,
                'currency' => 'RUB',
                'internal_order_id' => "mock_actual_{$i}",
                'status' => PaymentAttemptStatus::FailedTerminal,
                'initiated_at' => "2027-08-21 10:0{$i}:00",
                'metadata' => [
                    'legacy_subscription_id' => "actual_sub_{$i}",
                ],
            ]);
        }

        $service = app(LegacyProjectionRepairService::class);

        // First repair
        $stats1 = $service->repair(dryRun: false);
        $this->assertSame(2, $stats1['numbers_to_change']);
        $this->assertSame(3, $stats1['statuses_fixed']);
        $this->assertSame(3, $stats1['metadata_enriched']);

        // Verify result
        $attempts = PaymentAttempt::where('billing_cycle_id', $cycle->id)
            ->orderBy('attempt_number')
            ->get();
        $this->assertSame(1, $attempts[0]->attempt_number);
        $this->assertSame(2, $attempts[1]->attempt_number);
        $this->assertSame(3, $attempts[2]->attempt_number);
        $attempts->each(fn ($a) => $this->assertSame(PaymentAttemptStatus::Unknown, $a->status));

        // Second repair — idempotent
        $stats2 = $service->repair(dryRun: false);
        $this->assertSame(0, $stats2['numbers_to_change']);
        $this->assertSame(0, $stats2['statuses_fixed']);
        $this->assertSame(0, $stats2['metadata_enriched']);

        // Canonical sub unchanged
        $subAfter = BillingSubscription::where('workspace_id', $workspace->id)->first();
        $this->assertSame('2027-08-21 15:57:16', $subAfter->current_period_end->format('Y-m-d H:i:s'));
    }
}
