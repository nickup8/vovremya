<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\PaymentAttempt;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CleanupPendingSubscriptionGuardTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'booking.cleanup_pending_subscription_hours' => 2,
            'billing.legacy_mock_webhook_secret' => 'test_secret_123',
        ]);

        $master = User::factory()->master()->create();
        $this->workspace = Workspace::create([
            'name' => 'ws-'.$master->id,
            'owner_id' => $master->id,
        ]);
        $this->workspace->ensureSlug();
        $master->update(['workspace_id' => $this->workspace->id]);

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

    private function createPendingLegacy(?string $paymentId = null, int $hoursAgo = 3): Subscription
    {
        $legacy = Subscription::create([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => 'pending',
            'starts_at' => now()->subHours($hoursAgo),
            'expires_at' => now()->addMonth(),
            'payment_id' => $paymentId,
        ]);

        DB::table('subscriptions')
            ->where('id', $legacy->id)
            ->update(['created_at' => now()->subHours($hoursAgo)]);

        return $legacy->fresh();
    }

    private function createBillingSubAndCycle(Subscription $legacy): array
    {
        $billingSub = BillingSubscription::create([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::PendingInitial,
        ]);

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => $legacy->starts_at,
            'period_end' => $legacy->expires_at,
            'status' => BillingCycleStatus::Pending,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Payment,
            'legacy_subscription_id' => $legacy->id,
        ]);

        return [$billingSub, $cycle];
    }

    private function createAttempt(BillingCycle $cycle, PaymentAttemptStatus $status, ?string $providerPaymentId = null, ?string $internalOrderId = null, ?string $legacySubscriptionId = null): PaymentAttempt
    {
        return PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'mock',
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => $internalOrderId ?? 'core_'.uniqid(),
            'provider_payment_id' => $providerPaymentId,
            'status' => $status,
            'initiated_at' => now()->subHours(3),
            'metadata' => $legacySubscriptionId !== null
                ? ['legacy' => true, 'legacy_subscription_id' => $legacySubscriptionId]
                : null,
        ]);
    }

    private function runCleanup(): void
    {
        $this->artisan('subscriptions:cleanup-pending');
    }

    // ── 1. processing → skip ──

    public function test_processing_attempt_skips_legacy(): void
    {
        $legacy = $this->createPendingLegacy('provider_x');
        [$billingSub, $cycle] = $this->createBillingSubAndCycle($legacy);
        $attempt = $this->createAttempt($cycle, PaymentAttemptStatus::Processing, 'provider_x', null, $legacy->id);

        $this->runCleanup();

        $legacy->refresh();
        $this->assertSame('pending', $legacy->status);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
    }

    // ── 2. unknown → skip ──

    public function test_unknown_attempt_skips_legacy(): void
    {
        $legacy = $this->createPendingLegacy('provider_unknown');
        [$billingSub, $cycle] = $this->createBillingSubAndCycle($legacy);
        $attempt = $this->createAttempt($cycle, PaymentAttemptStatus::Unknown, null, 'core_unknown', $legacy->id);

        $this->runCleanup();

        $legacy->refresh();
        $this->assertSame('pending', $legacy->status);
    }

    // ── 3. created → skip ──

    public function test_created_attempt_skips_legacy(): void
    {
        $legacy = $this->createPendingLegacy('provider_created');
        [$billingSub, $cycle] = $this->createBillingSubAndCycle($legacy);
        $attempt = $this->createAttempt($cycle, PaymentAttemptStatus::Created, null, 'core_created', $legacy->id);

        $this->runCleanup();

        $legacy->refresh();
        $this->assertSame('pending', $legacy->status);
    }

    // ── 4. failed_terminal → legacy failed ──

    public function test_failed_terminal_attempt_fails_legacy(): void
    {
        $legacy = $this->createPendingLegacy('provider_ft');
        [$billingSub, $cycle] = $this->createBillingSubAndCycle($legacy);
        $attempt = $this->createAttempt($cycle, PaymentAttemptStatus::FailedTerminal, 'provider_ft', null, $legacy->id);

        $this->runCleanup();

        $legacy->refresh();
        $this->assertSame('failed', $legacy->status);

        // Core rows unchanged
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);

        $cycle->refresh();
        $this->assertSame(BillingCycleStatus::Pending, $cycle->status);
    }

    // ── 5. succeeded → skip anomaly ──

    public function test_succeeded_attempt_skips_as_anomaly(): void
    {
        $legacy = $this->createPendingLegacy('provider_ok');
        [$billingSub, $cycle] = $this->createBillingSubAndCycle($legacy);
        $attempt = $this->createAttempt($cycle, PaymentAttemptStatus::Succeeded, 'provider_ok', null, $legacy->id);

        $this->runCleanup();

        $legacy->refresh();
        $this->assertSame('pending', $legacy->status);
    }

    // ── 6. refunded → skip ──

    public function test_refunded_attempt_skips_legacy(): void
    {
        $legacy = $this->createPendingLegacy('provider_ref');
        [$billingSub, $cycle] = $this->createBillingSubAndCycle($legacy);
        $attempt = $this->createAttempt($cycle, PaymentAttemptStatus::Refunded, 'provider_ref', null, $legacy->id);

        $this->runCleanup();

        $legacy->refresh();
        $this->assertSame('pending', $legacy->status);
    }

    // ── 7. failed_retryable → skip ──

    public function test_failed_retryable_attempt_skips_legacy(): void
    {
        $legacy = $this->createPendingLegacy('provider_retry');
        [$billingSub, $cycle] = $this->createBillingSubAndCycle($legacy);
        $attempt = $this->createAttempt($cycle, PaymentAttemptStatus::FailedRetryable, 'provider_retry', null, $legacy->id);

        $this->runCleanup();

        $legacy->refresh();
        $this->assertSame('pending', $legacy->status);
    }

    // ── 8. no attempt + payment_id null → failed ──

    public function test_no_attempt_no_payment_id_fails_legacy(): void
    {
        $legacy = $this->createPendingLegacy(null);

        $this->runCleanup();

        $legacy->refresh();
        $this->assertSame('failed', $legacy->status);
    }

    // ── 9. no attempt + payment_id exists → skip ──

    public function test_no_attempt_with_payment_id_skips_legacy(): void
    {
        $legacy = $this->createPendingLegacy('orphan_provider_id');

        $this->runCleanup();

        $legacy->refresh();
        $this->assertSame('pending', $legacy->status);
    }

    // ── 10. late success after processing-skip → works ──

    public function test_late_success_after_processing_skip(): void
    {
        config(['billing.legacy_mock_webhook_secret' => 'test_secret_123']);

        $legacy = $this->createPendingLegacy('provider_late_1');
        $internalOrderId = 'core_late_processing';
        [$billingSub, $cycle] = $this->createBillingSubAndCycle($legacy);
        $attempt = $this->createAttempt($cycle, PaymentAttemptStatus::Processing, 'provider_late_1', $internalOrderId, $legacy->id);

        // Cleanup should skip
        $this->runCleanup();

        $legacy->refresh();
        $this->assertSame('pending', $legacy->status);
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);

        // Late success webhook arrives
        $payload = [
            'payment_id' => 'provider_late_1',
            'order_id' => $internalOrderId,
            'status' => 'paid',
            'amount' => 490,
        ];

        $this->postJson('/webhooks/payment', $payload, [
            'X-Webhook-Signature' => 'test_secret_123',
        ])->assertOk();

        $legacy->refresh();
        $this->assertSame('active', $legacy->status);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);

        $cycle->refresh();
        $this->assertSame(BillingCycleStatus::Paid, $cycle->status);
    }

    // ── 11. late success after unknown-skip → works ──

    public function test_late_success_after_unknown_skip(): void
    {
        config(['billing.legacy_mock_webhook_secret' => 'test_secret_123']);

        $legacy = $this->createPendingLegacy(null);
        $internalOrderId = 'core_late_unknown';
        [$billingSub, $cycle] = $this->createBillingSubAndCycle($legacy);
        $attempt = $this->createAttempt($cycle, PaymentAttemptStatus::Unknown, null, $internalOrderId, $legacy->id);

        // Cleanup should skip (unknown + no payment_id = still skip via attempt lookup)
        $this->runCleanup();

        $legacy->refresh();
        $this->assertSame('pending', $legacy->status);

        // Late webhook with order_id
        $payload = [
            'payment_id' => 'provider_late_unknown',
            'order_id' => $internalOrderId,
            'status' => 'paid',
            'amount' => 490,
        ];

        $this->postJson('/webhooks/payment', $payload, [
            'X-Webhook-Signature' => 'test_secret_123',
        ])->assertOk();

        $legacy->refresh();
        $this->assertSame('active', $legacy->status);
        $this->assertSame('provider_late_unknown', $legacy->payment_id);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);

        $cycle->refresh();
        $this->assertSame(BillingCycleStatus::Paid, $cycle->status);
    }

    // ── 12. command leaves Core rows unchanged ──

    public function test_command_leaves_core_rows_unchanged_for_in_flight(): void
    {
        $legacy = $this->createPendingLegacy('provider_core');
        [$billingSub, $cycle] = $this->createBillingSubAndCycle($legacy);
        $attempt = $this->createAttempt($cycle, PaymentAttemptStatus::Processing, 'provider_core', 'core_unchanged', $legacy->id);

        $this->runCleanup();

        $legacy->refresh();
        $this->assertSame('pending', $legacy->status);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        $this->assertSame('core_unchanged', $attempt->internal_order_id);

        $cycle->refresh();
        $this->assertSame(BillingCycleStatus::Pending, $cycle->status);

        $billingSub->refresh();
        $this->assertSame(BillingSubscriptionStatus::PendingInitial, $billingSub->status);
    }

    // ── 13. threshold not reached → untouched ──

    public function test_recent_pending_not_touched(): void
    {
        $legacy = $this->createPendingLegacy('provider_recent', 1); // 1h ago, threshold is 2h

        $this->runCleanup();

        $legacy->refresh();
        $this->assertSame('pending', $legacy->status);
    }
}
