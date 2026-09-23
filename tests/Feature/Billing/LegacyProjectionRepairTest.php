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

        $attemptBefore = PaymentAttempt::where('internal_order_id', 'mock_repair_dry')->first();
        $numberBefore = $attemptBefore->attempt_number;
        $statusBefore = $attemptBefore->status;

        $service = app(LegacyProjectionRepairService::class);
        $service->repair(dryRun: true);

        $attemptAfter = PaymentAttempt::where('internal_order_id', 'mock_repair_dry')->first();
        $this->assertSame($numberBefore, $attemptAfter->attempt_number);
        $this->assertSame($statusBefore->value, $attemptAfter->status->value);
    }

    // ── 2. Legacy 1/1/1 → reports desired 1/2/3 ──

    public function test_repair_dry_run_reports_correct_numbering(): void
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
                'payment_id' => "mock_repair_num_{$i}",
                'created_at' => "2026-08-21 10:0{$i}:00",
            ]);
        }

        $this->projectLegacy();

        // Corrupt attempt numbers
        $cycle = BillingCycle::first();
        $attempts = $cycle->paymentAttempts()->orderBy('initiated_at')->get();
        $attempts->each(fn ($a) => $a->update(['attempt_number' => 1]));

        $service = app(LegacyProjectionRepairService::class);
        $plan = $service->repair(dryRun: true);

        $this->assertNotEmpty($plan['numbering_changes']);
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

        // Corrupt numbering to simulate pre-T25 state
        $cycle = BillingCycle::first();
        $cycle->paymentAttempts()->update(['attempt_number' => 1]);

        $service = app(LegacyProjectionRepairService::class);
        $stats = $service->repair(dryRun: false);

        $this->assertSame(2, $stats['numbers_to_change']);

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

        // Corrupt numbering
        $cycle = BillingCycle::first();
        $cycle->paymentAttempts()->update(['attempt_number' => 1]);

        $service = app(LegacyProjectionRepairService::class);

        $stats1 = $service->repair(dryRun: false);
        $stats2 = $service->repair(dryRun: false);

        $this->assertSame(2, $stats1['numbers_to_change']);
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
            'expires_at' => '2027-08-21 15:57:16',
            'payment_id' => 'mock_horizon_ok',
        ]);

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

    // ── 10. Out-of-order timestamps: A(11:28), B(16:36), C(14:27) inserted A,B,C ──

    public function test_repair_respects_chronological_order_not_insertion_order(): void
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
            'period_start' => '2026-08-01 00:00:00',
            'period_end' => '2026-09-01 00:00:00',
            'status' => BillingCycleStatus::Failed,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Payment,
        ]);

        // Insert in order A, B, C — but chronological is A, C, B
        $a = PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'mock',
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'mock_13_insertion1',
            'status' => PaymentAttemptStatus::Unknown,
            'initiated_at' => '2026-08-10 11:28:31',
            'metadata' => ['legacy' => true, 'legacy_subscription_id' => 'sub_13', 'failure_source' => 'legacy_unknown'],
        ]);

        $b = PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'mock',
            'attempt_number' => 2,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'mock_94_insertion2',
            'status' => PaymentAttemptStatus::Unknown,
            'initiated_at' => '2026-09-02 16:36:17',
            'metadata' => ['legacy' => true, 'legacy_subscription_id' => 'sub_94', 'failure_source' => 'legacy_unknown'],
        ]);

        $c = PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'mock',
            'attempt_number' => 3,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'mock_d5_insertion3',
            'status' => PaymentAttemptStatus::Unknown,
            'initiated_at' => '2026-08-10 14:27:13',
            'metadata' => ['legacy' => true, 'legacy_subscription_id' => 'sub_d5', 'failure_source' => 'legacy_unknown'],
        ]);

        $service = app(LegacyProjectionRepairService::class);
        $stats = $service->repair(dryRun: false);

        // 2 numbers change: B was #2, should be #3; C was #3, should be #2
        $this->assertSame(2, $stats['numbers_to_change']);

        $a->refresh();
        $b->refresh();
        $c->refresh();

        // Chronological order: A(11:28) → C(14:27) → B(16:36)
        $this->assertSame(1, $a->attempt_number);
        $this->assertSame(3, $b->attempt_number);
        $this->assertSame(2, $c->attempt_number);
    }

    // ── 11. Successful legacy attempt: no failure_source enrichment ──

    public function test_successful_legacy_no_failure_source_enrichment(): void
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
            'period_start' => '2026-07-01 00:00:00',
            'period_end' => '2026-08-01 00:00:00',
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Payment,
        ]);

        // Successful attempt with legacy=true but no failure_source — this is correct
        $attempt = PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'mock',
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'mock_success_meta',
            'status' => PaymentAttemptStatus::Succeeded,
            'initiated_at' => '2026-07-15 10:00:00',
            'metadata' => [
                'legacy' => true,
                'legacy_subscription_id' => 'sub_success',
                // No failure_source — correct for succeeded
            ],
        ]);

        $service = app(LegacyProjectionRepairService::class);
        $plan = $service->repair(dryRun: true);

        $this->assertSame(0, $plan['metadata_enriched']);
        $this->assertSame(0, $plan['numbers_to_change']);
        $this->assertSame(0, $plan['statuses_fixed']);

        // Actual repair doesn't touch metadata
        $service->repair(dryRun: false);

        $attempt->refresh();
        $this->assertArrayNotHasKey('failure_source', $attempt->metadata ?? []);
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
    }

    // ── 12. Production shape: out-of-order swap 13→1, 94→3, d5→2 ──

    public function test_production_shape_reorder_and_idempotent(): void
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

        // Production shape: wrong numbering 13=#1, 94=#2, d5=#3
        // Chronological should be: 13(11:28)=#1, d5(14:27)=#2, 94(16:36)=#3
        $attempts = [
            ['id' => '13', 'ts' => '2026-08-10 11:28:31', 'num' => 1],
            ['id' => '94', 'ts' => '2026-09-02 16:36:17', 'num' => 2],
            ['id' => 'd5', 'ts' => '2026-08-10 14:27:13', 'num' => 3],
        ];

        foreach ($attempts as $a) {
            PaymentAttempt::create([
                'billing_cycle_id' => $cycle->id,
                'provider' => 'mock',
                'attempt_number' => $a['num'],
                'amount' => 490,
                'currency' => 'RUB',
                'internal_order_id' => "mock_{$a['id']}",
                'status' => PaymentAttemptStatus::Unknown,
                'initiated_at' => $a['ts'],
                'metadata' => [
                    'legacy' => true,
                    'legacy_subscription_id' => "sub_{$a['id']}",
                    'failure_source' => 'legacy_unknown',
                ],
            ]);
        }

        $service = app(LegacyProjectionRepairService::class);

        // First repair
        $stats1 = $service->repair(dryRun: false);
        $this->assertSame(2, $stats1['numbers_to_change']);
        $this->assertSame(0, $stats1['statuses_fixed']);
        $this->assertSame(0, $stats1['metadata_enriched']);

        // Verify chronological mapping
        $attempt13 = PaymentAttempt::where('internal_order_id', 'mock_13')->first();
        $attempt94 = PaymentAttempt::where('internal_order_id', 'mock_94')->first();
        $attemptd5 = PaymentAttempt::where('internal_order_id', 'mock_d5')->first();

        $this->assertSame(1, $attempt13->attempt_number);
        $this->assertSame(3, $attempt94->attempt_number);
        $this->assertSame(2, $attemptd5->attempt_number);

        // Second dry-run → all zeros
        $plan = $service->repair(dryRun: true);
        $this->assertSame(0, $plan['numbers_to_change']);
        $this->assertSame(0, $plan['statuses_fixed']);
        $this->assertSame(0, $plan['metadata_enriched']);
        $this->assertSame(1, $plan['cycles_unchanged']);

        // Canonical sub unchanged
        $billingSub->refresh();
        $this->assertSame('2027-08-21 15:57:16', $billingSub->current_period_end->format('Y-m-d H:i:s'));
    }

    // ── 13. CLI outputs correct numbers_to_change ──

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
}
