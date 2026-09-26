<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\PaymentAttempt;
use App\Models\PlanPrice;
use App\Models\ProviderEvent;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Payment\DTOs\ProviderStatusUpdate;
use App\Services\Payment\PaymentTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentTransitionServiceTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;
    private Workspace $workspace;
    private User $master;
    private PaymentTransitionService $transitionService;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.legacy_mock_webhook_secret' => 'test_secret_123']);
        config(['billing.reconciliation.fresh_grace_seconds' => 0]);

        $this->master = User::factory()->master()->create();
        $this->workspace = Workspace::create([
            'name' => 'ws-'.$this->master->id,
            'owner_id' => $this->master->id,
        ]);
        $this->workspace->ensureSlug();
        $this->master->update(['workspace_id' => $this->workspace->id]);

        $this->proPlan = TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'max_appointments_per_month' => null,
            'max_masters' => 1,
            'features' => ['unlimited_appointments'],
            'is_active' => true,
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

        $this->transitionService = app(PaymentTransitionService::class);
    }

    // ── Lookup Tests ──

    public function test_lookup_by_provider_payment_id(): void
    {
        $attempt = $this->createAttemptWithProviderPaymentId('mock_123', 490);

        $update = new ProviderStatusUpdate(
            provider: 'mock',
            providerPaymentId: 'mock_123',
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: 490,
            currency: 'RUB',
        );

        $result = $this->transitionService->transition($update);

        $this->assertTrue($result['success']);
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
    }

    public function test_lookup_by_internal_order_id(): void
    {
        $attempt = $this->createAttemptWithInternalOrderId('core_test_order', 490);

        $update = new ProviderStatusUpdate(
            provider: 'mock',
            providerPaymentId: 'mock_456',
            internalOrderId: 'core_test_order',
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: 490,
            currency: 'RUB',
        );

        $result = $this->transitionService->transition($update);

        $this->assertTrue($result['success']);
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
        $this->assertSame('mock_456', $attempt->provider_payment_id);
    }

    public function test_wrong_provider_rejected(): void
    {
        $attempt = $this->createAttemptWithProviderPaymentId('mock_123', 490, 'mock');

        $update = new ProviderStatusUpdate(
            provider: 'tbank',
            providerPaymentId: 'mock_123',
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: 490,
            currency: 'RUB',
        );

        $result = $this->transitionService->transition($update);

        $this->assertFalse($result['success']);
        $this->assertSame('wrong_provider', $result['error']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
    }

    // ── Money Validation Tests ──

    public function test_wrong_amount_rejected(): void
    {
        $attempt = $this->createAttemptWithProviderPaymentId('mock_123', 490);

        $update = new ProviderStatusUpdate(
            provider: 'mock',
            providerPaymentId: 'mock_123',
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: 999,
            currency: 'RUB',
        );

        $result = $this->transitionService->transition($update);

        $this->assertFalse($result['success']);
        $this->assertSame('amount_mismatch', $result['error']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
    }

    public function test_wrong_currency_rejected(): void
    {
        $attempt = $this->createAttemptWithProviderPaymentId('mock_123', 490, 'mock', 'USD');

        $update = new ProviderStatusUpdate(
            provider: 'mock',
            providerPaymentId: 'mock_123',
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: 490,
            currency: 'RUB',
        );

        $result = $this->transitionService->transition($update);

        $this->assertFalse($result['success']);
        $this->assertSame('currency_mismatch', $result['error']);
    }

    public function test_amount_not_validated_for_failed_status(): void
    {
        $attempt = $this->createAttemptWithProviderPaymentId('mock_123', 490);

        $update = new ProviderStatusUpdate(
            provider: 'mock',
            providerPaymentId: 'mock_123',
            normalizedStatus: PaymentAttemptStatus::FailedTerminal,
            amount: 999, // Wrong amount, but not validated for failed
            currency: 'RUB',
        );

        $result = $this->transitionService->transition($update);

        $this->assertTrue($result['success']);
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
    }

    // ── State Machine Tests ──

    public function test_unknown_to_succeeded(): void
    {
        $attempt = $this->createAttemptWithStatus(PaymentAttemptStatus::Unknown, 490);

        $update = new ProviderStatusUpdate(
            provider: 'mock',
            providerPaymentId: $attempt->provider_payment_id,
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: 490,
            currency: 'RUB',
        );

        $result = $this->transitionService->transition($update);

        $this->assertTrue($result['success']);
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
    }

    public function test_unknown_to_failed_terminal(): void
    {
        $attempt = $this->createAttemptWithStatus(PaymentAttemptStatus::Unknown, 490);

        $update = new ProviderStatusUpdate(
            provider: 'mock',
            providerPaymentId: $attempt->provider_payment_id,
            normalizedStatus: PaymentAttemptStatus::FailedTerminal,
        );

        $result = $this->transitionService->transition($update);

        $this->assertTrue($result['success']);
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
    }

    public function test_failed_terminal_to_succeeded_late_success(): void
    {
        $attempt = $this->createAttemptWithStatus(PaymentAttemptStatus::FailedTerminal, 490);

        $update = new ProviderStatusUpdate(
            provider: 'mock',
            providerPaymentId: $attempt->provider_payment_id,
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: 490,
            currency: 'RUB',
        );

        $result = $this->transitionService->transition($update);

        $this->assertTrue($result['success']);
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
    }

    public function test_refunded_to_succeeded_rejected(): void
    {
        $attempt = $this->createAttemptWithStatus(PaymentAttemptStatus::Refunded, 490);

        $update = new ProviderStatusUpdate(
            provider: 'mock',
            providerPaymentId: $attempt->provider_payment_id,
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: 490,
            currency: 'RUB',
        );

        $result = $this->transitionService->transition($update);

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_transition', $result['error']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Refunded, $attempt->status);
    }

    public function test_duplicate_succeeded_is_idempotent(): void
    {
        $attempt = $this->createAttemptWithStatus(PaymentAttemptStatus::Succeeded, 490);

        $update = new ProviderStatusUpdate(
            provider: 'mock',
            providerPaymentId: $attempt->provider_payment_id,
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: 490,
            currency: 'RUB',
        );

        $result = $this->transitionService->transition($update);

        $this->assertTrue($result['success']);
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
    }

    public function test_succeeded_to_failed_terminal_rejected(): void
    {
        $attempt = $this->createAttemptWithStatus(PaymentAttemptStatus::Succeeded, 490);

        $update = new ProviderStatusUpdate(
            provider: 'mock',
            providerPaymentId: $attempt->provider_payment_id,
            normalizedStatus: PaymentAttemptStatus::FailedTerminal,
        );

        $result = $this->transitionService->transition($update);

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_transition', $result['error']);
    }

    // ── Legacy Behavior Tests ──

    public function test_success_when_legacy_failed(): void
    {
        $legacy = $this->createLegacySubscription('pending');
        $attempt = $this->createAttemptWithLegacy($legacy, PaymentAttemptStatus::Processing, 490);

        $legacy->update(['status' => 'failed']);

        $update = new ProviderStatusUpdate(
            provider: 'mock',
            providerPaymentId: $attempt->provider_payment_id,
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: 490,
            currency: 'RUB',
        );

        $result = $this->transitionService->transition($update);

        $this->assertTrue($result['success']);
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);

        // Legacy should be mirrored to active
        $legacy->refresh();
        $this->assertSame('active', $legacy->status);
    }

    public function test_success_without_legacy(): void
    {
        $attempt = $this->createAttemptWithStatus(PaymentAttemptStatus::Processing, 490);

        $update = new ProviderStatusUpdate(
            provider: 'mock',
            providerPaymentId: $attempt->provider_payment_id,
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: 490,
            currency: 'RUB',
        );

        $result = $this->transitionService->transition($update);

        $this->assertTrue($result['success']);
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
    }

    public function test_mirror_failure_does_not_rollback_core(): void
    {
        // Create legacy subscription that will fail to update (delete it to trigger failure)
        $legacy = $this->createLegacySubscription('pending');
        $legacyId = $legacy->id;
        $legacy->delete(); // Delete to trigger mirror failure

        $attempt = $this->createAttemptWithLegacyById($legacyId, PaymentAttemptStatus::Processing, 490);

        $update = new ProviderStatusUpdate(
            provider: 'mock',
            providerPaymentId: $attempt->provider_payment_id,
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: 490,
            currency: 'RUB',
        );

        // This should not throw even if mirror fails
        $result = $this->transitionService->transition($update);

        $this->assertTrue($result['success']);
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
    }

    // ── Multiple Attempts Tests ──

    public function test_attempt1_failed_attempt2_succeeded_cycle_paid_once(): void
    {
        $cycle = $this->createCycle();
        $attempt1 = $this->createAttemptInCycle($cycle, PaymentAttemptStatus::FailedTerminal, 490);
        $attempt2 = $this->createAttemptInCycle($cycle, PaymentAttemptStatus::Processing, 490);

        $update = new ProviderStatusUpdate(
            provider: 'mock',
            providerPaymentId: $attempt2->provider_payment_id,
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: 490,
            currency: 'RUB',
        );

        $result = $this->transitionService->transition($update);

        $this->assertTrue($result['success']);

        $cycle->refresh();
        $this->assertSame(BillingCycleStatus::Paid, $cycle->status);

        $attempt1->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt1->status);

        $attempt2->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt2->status);
    }

    public function test_late_success_in_already_paid_cycle_does_not_duplicate_entitlement(): void
    {
        $cycle = $this->createCycle();
        $attempt1 = $this->createAttemptInCycle($cycle, PaymentAttemptStatus::Succeeded, 490);
        $attempt2 = $this->createAttemptInCycle($cycle, PaymentAttemptStatus::FailedTerminal, 490);

        // Mark cycle as paid
        $cycle->update(['status' => BillingCycleStatus::Paid]);

        $update = new ProviderStatusUpdate(
            provider: 'mock',
            providerPaymentId: $attempt2->provider_payment_id,
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: 490,
            currency: 'RUB',
        );

        $result = $this->transitionService->transition($update);

        $this->assertTrue($result['success']);

        $cycle->refresh();
        $this->assertSame(BillingCycleStatus::Paid, $cycle->status);

        // Should log anomaly
        $this->assertDatabaseHas('provider_events', [
            'event_type' => 'succeeded',
        ]);
    }

    // ── Event Tests ──

    public function test_duplicate_provider_event_no_500(): void
    {
        $attempt = $this->createAttemptWithProviderPaymentId('mock_123', 490);

        $update = new ProviderStatusUpdate(
            provider: 'mock',
            providerEventId: 'event_1',
            providerPaymentId: 'mock_123',
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: 490,
            currency: 'RUB',
        );

        // First transition
        $result1 = $this->transitionService->transition($update);
        $this->assertTrue($result1['success']);

        // Duplicate event - should not throw 500
        $result2 = $this->transitionService->transition($update);
        $this->assertTrue($result2['success']);

        // Only one provider event should exist
        $this->assertDatabaseCount('provider_events', 1);
    }

    public function test_same_status_with_new_event_id_recorded(): void
    {
        $attempt = $this->createAttemptWithStatus(PaymentAttemptStatus::Succeeded, 490);

        $update1 = new ProviderStatusUpdate(
            provider: 'mock',
            providerEventId: 'event_1',
            providerPaymentId: 'mock_123',
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: 490,
            currency: 'RUB',
        );

        $update2 = new ProviderStatusUpdate(
            provider: 'mock',
            providerEventId: 'event_2',
            providerPaymentId: 'mock_123',
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: 490,
            currency: 'RUB',
        );

        $this->transitionService->transition($update1);
        $this->transitionService->transition($update2);

        // Both events should be recorded
        $this->assertDatabaseCount('provider_events', 2);

        // But transition should be idempotent
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
    }

    public function test_unmatched_event_journaled(): void
    {
        $update = new ProviderStatusUpdate(
            provider: 'mock',
            providerPaymentId: 'nonexistent_payment',
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: 490,
            currency: 'RUB',
        );

        $result = $this->transitionService->transition($update);

        $this->assertFalse($result['success']);
        $this->assertSame('attempt_not_found', $result['error']);

        // Event should be journaled
        $this->assertDatabaseHas('provider_events', [
            'provider' => 'mock',
            'processing_error' => 'attempt_not_found',
        ]);
    }

    // ── Helpers ──

    private function createAttemptWithProviderPaymentId(
        string $providerPaymentId,
        int $amount,
        string $provider = 'mock',
        string $currency = 'RUB',
    ): PaymentAttempt {
        $cycle = $this->createCycle();

        return PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => $provider,
            'attempt_number' => 1,
            'amount' => $amount,
            'currency' => $currency,
            'internal_order_id' => 'core_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => $providerPaymentId,
            'status' => PaymentAttemptStatus::Processing,
            'initiated_at' => now(),
        ]);
    }

    private function createAttemptWithInternalOrderId(
        string $internalOrderId,
        int $amount,
        string $provider = 'mock',
    ): PaymentAttempt {
        $cycle = $this->createCycle();

        return PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => $provider,
            'attempt_number' => 1,
            'amount' => $amount,
            'currency' => 'RUB',
            'internal_order_id' => $internalOrderId,
            'status' => PaymentAttemptStatus::Processing,
            'initiated_at' => now(),
        ]);
    }

    private function createAttemptWithStatus(
        PaymentAttemptStatus $status,
        int $amount,
        string $provider = 'mock',
    ): PaymentAttempt {
        $cycle = $this->createCycle();

        return PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => $provider,
            'attempt_number' => 1,
            'amount' => $amount,
            'currency' => 'RUB',
            'internal_order_id' => 'core_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => 'mock_'.bin2hex(random_bytes(8)),
            'status' => $status,
            'initiated_at' => now(),
        ]);
    }

    private function createAttemptWithLegacy(
        Subscription $legacy,
        PaymentAttemptStatus $status,
        int $amount,
    ): PaymentAttempt {
        $cycle = $this->createCycle($legacy);

        return PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'mock',
            'attempt_number' => 1,
            'amount' => $amount,
            'currency' => 'RUB',
            'internal_order_id' => 'core_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => 'mock_'.bin2hex(random_bytes(8)),
            'status' => $status,
            'initiated_at' => now(),
            'metadata' => [
                'legacy_subscription_id' => $legacy->id,
            ],
        ]);
    }

    private function createCycle(?Subscription $legacy = null): BillingCycle
    {
        $billingSub = BillingSubscription::create([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::PendingInitial,
        ]);

        return BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now(),
            'period_end' => now()->addMonth(),
            'status' => BillingCycleStatus::Pending,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => 'payment',
            'legacy_subscription_id' => $legacy?->id,
        ]);
    }

    private function createAttemptInCycle(
        BillingCycle $cycle,
        PaymentAttemptStatus $status,
        int $amount,
    ): PaymentAttempt {
        $maxNumber = PaymentAttempt::where('billing_cycle_id', $cycle->id)
            ->max('attempt_number') ?? 0;

        return PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'mock',
            'attempt_number' => $maxNumber + 1,
            'amount' => $amount,
            'currency' => 'RUB',
            'internal_order_id' => 'core_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => 'mock_'.bin2hex(random_bytes(8)),
            'status' => $status,
            'initiated_at' => now(),
        ]);
    }

    private function createLegacySubscription(string $status): Subscription
    {
        return Subscription::create([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => $status,
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
            'payment_id' => 'mock_'.bin2hex(random_bytes(8)),
        ]);
    }

    private function createAttemptWithLegacyById(
        string $legacyId,
        PaymentAttemptStatus $status,
        int $amount,
    ): PaymentAttempt {
        $cycle = $this->createCycle();

        return PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'mock',
            'attempt_number' => 1,
            'amount' => $amount,
            'currency' => 'RUB',
            'internal_order_id' => 'core_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => 'mock_'.bin2hex(random_bytes(8)),
            'status' => $status,
            'initiated_at' => now(),
            'metadata' => [
                'legacy_subscription_id' => $legacyId,
            ],
        ]);
    }
}
