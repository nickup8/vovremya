<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\NotificationLog;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Models\PlanPrice;
use App\Models\ProviderEvent;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\RenewalInsufficientFundsNotification;
use App\Services\Notification\MasterNotificationService;
use App\Services\Payment\DTOs\ProviderStatusUpdate;
use App\Services\Payment\PaymentTransitionService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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
        // No failure details in the DTO — keep the legacy default category
        $this->assertNull($attempt->failure_code);
        $this->assertSame('provider_failed', $attempt->failure_category);
        $this->assertNull($attempt->failure_message);
    }

    public function test_failed_terminal_persists_failure_details_from_dto(): void
    {
        $attempt = $this->createAttemptWithStatus(PaymentAttemptStatus::Processing, 490);

        $update = new ProviderStatusUpdate(
            provider: 'mock',
            providerPaymentId: $attempt->provider_payment_id,
            normalizedStatus: PaymentAttemptStatus::FailedTerminal,
            failureCode: '103',
            failureCategory: 'insufficient_funds',
            failureMessage: 'Недостаточно средств на карте',
        );

        $result = $this->transitionService->transition($update);

        $this->assertTrue($result['success']);
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('103', $attempt->failure_code);
        $this->assertSame('insufficient_funds', $attempt->failure_category);
        $this->assertSame('Недостаточно средств на карте', $attempt->failure_message);
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
        $legacy = $this->createLegacySubscription('pending');
        $legacyId = $legacy->id;

        $attempt = $this->createAttemptWithLegacyById($legacyId, PaymentAttemptStatus::Processing, 490);

        $update = new ProviderStatusUpdate(
            provider: 'mock',
            providerPaymentId: $attempt->provider_payment_id,
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: 490,
            currency: 'RUB',
        );

        // Register model event that throws a real QueryException during the mirror UPDATE.
        // This simulates a genuine SQL failure (NOT NULL, connection error, etc.)
        // inside the nested DB::transaction (savepoint).
        $exception = new \Illuminate\Database\QueryException(
            'pgsql',
            'UPDATE subscriptions SET status = ? WHERE id = ?',
            [],
            new \PDOException('test mirror SQL failure'),
        );

        Subscription::updating(function ($model) use ($exception) {
            if ($model->status === 'active') {
                throw $exception;
            }
        });

        // Core transition must succeed despite mirror SQL failure
        $result = $this->transitionService->transition($update);

        $this->assertTrue($result['success']);
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);

        // BillingCycle still committed as paid
        $cycle = $attempt->billingCycle;
        $this->assertNotNull($cycle);
        $cycle->refresh();
        $this->assertSame(BillingCycleStatus::Paid, $cycle->status);

        // ProviderEvent linked and processed
        $event = ProviderEvent::where('payment_attempt_id', $attempt->id)->first();
        $this->assertNotNull($event);
        $this->assertNull($event->processing_error);
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

    // ── Identity Conflict Tests (§9) ──

    public function test_conflicting_provider_payment_id_and_order_id_rejected(): void
    {
        // Two DIFFERENT attempts in SEPARATE workspaces to avoid billing_subscriptions unique
        $attemptA = $this->createAttemptWithProviderPaymentId('mock_A', 490);

        // Create attempt B in a different cycle (different workspace)
        $master2 = User::factory()->master()->create();
        $workspace2 = Workspace::create([
            'name' => 'ws2-'.$master2->id,
            'owner_id' => $master2->id,
        ]);
        $workspace2->ensureSlug();
        $master2->update(['workspace_id' => $workspace2->id]);

        $billingSub2 = BillingSubscription::create([
            'workspace_id' => $workspace2->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::PendingInitial,
        ]);
        $cycle2 = BillingCycle::create([
            'billing_subscription_id' => $billingSub2->id,
            'workspace_id' => $workspace2->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now(),
            'period_end' => now()->addMonth(),
            'status' => BillingCycleStatus::Pending,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => 'payment',
        ]);
        $attemptB = PaymentAttempt::create([
            'billing_cycle_id' => $cycle2->id,
            'provider' => 'mock',
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'core_order_B',
            'status' => PaymentAttemptStatus::Processing,
            'initiated_at' => now(),
        ]);

        $update = new ProviderStatusUpdate(
            provider: 'mock',
            providerPaymentId: 'mock_A',
            internalOrderId: 'core_order_B',
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: 490,
            currency: 'RUB',
        );

        $result = $this->transitionService->transition($update);

        $this->assertFalse($result['success']);
        $this->assertSame('identity_conflict', $result['error']);

        // Neither attempt mutated
        $attemptA->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attemptA->status);
        $attemptB->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attemptB->status);
    }

    public function test_matching_identities_same_attempt_succeeds(): void
    {
        // Both IDs point to the SAME attempt
        $attempt = $this->createAttemptWithProviderPaymentId('mock_match', 490);
        // Update the attempt to also have the internal_order_id
        $attempt->update(['internal_order_id' => 'core_match_order']);

        $update = new ProviderStatusUpdate(
            provider: 'mock',
            providerPaymentId: 'mock_match',
            internalOrderId: 'core_match_order',
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: 490,
            currency: 'RUB',
        );

        $result = $this->transitionService->transition($update);

        $this->assertTrue($result['success']);
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
    }

    public function test_attach_provider_id_when_found_by_order_id(): void
    {
        // Attempt with no provider_payment_id
        $cycle = $this->createCycle();
        $attempt = PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'mock',
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'core_attach_order',
            'status' => PaymentAttemptStatus::Processing,
            'initiated_at' => now(),
        ]);

        $this->assertNull($attempt->provider_payment_id);

        $update = new ProviderStatusUpdate(
            provider: 'mock',
            providerPaymentId: 'mock_new_payment_id',
            internalOrderId: 'core_attach_order',
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: 490,
            currency: 'RUB',
        );

        $result = $this->transitionService->transition($update);

        $this->assertTrue($result['success']);
        $attempt->refresh();
        $this->assertSame('mock_new_payment_id', $attempt->provider_payment_id);
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
    }

    // ── Event Concurrency Test (§10) ──

    public function test_atomic_claim_duplicate_insert_ignored(): void
    {
        $attempt = $this->createAttemptWithProviderPaymentId('mock_atomic', 490);

        $update = new ProviderStatusUpdate(
            provider: 'mock',
            providerEventId: 'concurrent_event_1',
            providerPaymentId: 'mock_atomic',
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: 490,
            currency: 'RUB',
        );

        // First claim — inserts
        $event1 = $this->transitionService->transition($update);
        $this->assertTrue($event1['success']);

        // Second identical claim — insertOrIgnore returns 0, no exception
        $event2 = $this->transitionService->transition($update);
        $this->assertTrue($event2['success']);

        // Only one event row
        $this->assertDatabaseCount('provider_events', 1);

        // Transaction was NOT aborted — we can still write a new attempt in the same cycle
        $maxNumber = PaymentAttempt::where('billing_cycle_id', $attempt->billing_cycle_id)
            ->max('attempt_number') ?? 0;
        $attempt2 = PaymentAttempt::create([
            'billing_cycle_id' => $attempt->billing_cycle_id,
            'provider' => 'mock',
            'attempt_number' => $maxNumber + 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'core_after_dedup',
            'status' => PaymentAttemptStatus::Processing,
            'initiated_at' => now(),
        ]);

        $this->assertNotNull($attempt2->id);
    }

    // ── Same Status / Different Payload Test (§11) ──

    public function test_same_status_different_payload_creates_two_events(): void
    {
        $attempt = $this->createAttemptWithProviderPaymentId('mock_diff_payload', 490);

        // Same payment, same status, no provider_event_id, different payload
        $update1 = new ProviderStatusUpdate(
            provider: 'mock',
            providerPaymentId: 'mock_diff_payload',
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: 490,
            currency: 'RUB',
            raw: ['status' => 'succeeded', 'amount' => 490, 'extra' => 'first'],
        );

        $update2 = new ProviderStatusUpdate(
            provider: 'mock',
            providerPaymentId: 'mock_diff_payload',
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: 490,
            currency: 'RUB',
            raw: ['status' => 'succeeded', 'amount' => 490, 'extra' => 'second'],
        );

        $result1 = $this->transitionService->transition($update1);
        $this->assertTrue($result1['success']);

        $result2 = $this->transitionService->transition($update2);
        $this->assertTrue($result2['success']);

        // Two events because different payload → different fingerprint → different dedup_key
        $this->assertDatabaseCount('provider_events', 2);

        // But only one business state transition (idempotent)
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
    }

    // ── Partial Unique Index Test (§12) ──

    public function test_partial_unique_indexes_exist_with_predicate(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL index predicate test requires pgsql driver');
        }

        // Verify payment_attempts partial index
        $paymentIndex = DB::select("
            SELECT indexdef FROM pg_indexes
            WHERE indexname = 'payment_attempts_provider_payment_id_unique'
        ");
        $this->assertNotEmpty($paymentIndex, 'payment_attempts partial index should exist');
        $this->assertStringContainsString('provider_payment_id IS NOT NULL', $paymentIndex[0]->indexdef);

        // Verify provider_events partial index
        $eventIndex = DB::select("
            SELECT indexdef FROM pg_indexes
            WHERE indexname = 'provider_events_provider_event_id_unique'
        ");
        $this->assertNotEmpty($eventIndex, 'provider_events partial index should exist');
        $this->assertStringContainsString('provider_event_id IS NOT NULL', $eventIndex[0]->indexdef);
    }

    // ── Auto-renew scheduling (next_charge_at from Core horizon) ──

    public function test_confirmed_success_schedules_next_charge_at_period_end(): void
    {
        $sub = $this->createConsentedSubscription();
        $cycle = $this->createCycleForSubscription($sub, now(), now()->addMonth());
        $attempt = $this->createAttemptOnCycle($cycle, PaymentAttemptStatus::Processing);

        $result = $this->transitionService->transition($this->confirmedUpdate($attempt));

        $this->assertTrue($result['success']);

        $sub->refresh();
        $this->assertSame(BillingSubscriptionStatus::Active, $sub->status);
        $this->assertNotNull($sub->current_period_end);
        $this->assertNotNull($sub->next_charge_at);
        $this->assertTrue($sub->next_charge_at->equalTo($sub->current_period_end));
    }

    public function test_authorized_does_not_schedule_next_charge(): void
    {
        $sub = $this->createConsentedSubscription();
        $cycle = $this->createCycleForSubscription($sub, now(), now()->addMonth());
        $attempt = $this->createAttemptOnCycle($cycle, PaymentAttemptStatus::Processing);

        $result = $this->transitionService->transition(new ProviderStatusUpdate(
            provider: 'tbank',
            providerPaymentId: $attempt->provider_payment_id,
            internalOrderId: $attempt->internal_order_id,
            normalizedStatus: PaymentAttemptStatus::Processing,
            amount: 490,
            currency: 'RUB',
            raw: [
                'Status' => 'AUTHORIZED',
                'PaymentId' => $attempt->provider_payment_id,
                'Amount' => 49000,
            ],
        ));

        $this->assertTrue($result['success']);

        $sub->refresh();
        $this->assertNull($sub->next_charge_at);
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
    }

    public function test_confirmed_with_auto_renew_disabled_does_not_schedule(): void
    {
        $sub = $this->createConsentedSubscription(['cancel_at_period_end' => true]);
        $cycle = $this->createCycleForSubscription($sub, now(), now()->addMonth());
        $attempt = $this->createAttemptOnCycle($cycle, PaymentAttemptStatus::Processing);

        $result = $this->transitionService->transition($this->confirmedUpdate($attempt));

        $this->assertTrue($result['success']);

        $sub->refresh();
        $this->assertNull($sub->next_charge_at);
        $this->assertTrue($sub->cancel_at_period_end);
    }

    public function test_stacked_success_moves_next_charge_to_new_horizon_end(): void
    {
        $sub = $this->createConsentedSubscription();

        $cycle1 = $this->createCycleForSubscription($sub, now(), now()->addMonth());
        $attempt1 = $this->createAttemptOnCycle($cycle1, PaymentAttemptStatus::Processing);
        $this->assertTrue($this->transitionService->transition($this->confirmedUpdate($attempt1))['success']);

        $sub->refresh();
        $firstEnd = $sub->current_period_end;
        $this->assertNotNull($sub->next_charge_at);
        $this->assertTrue($sub->next_charge_at->equalTo($firstEnd));

        $cycle2 = $this->createCycleForSubscription($sub, now()->addMonth(), now()->addMonths(2));
        $attempt2 = $this->createAttemptOnCycle($cycle2, PaymentAttemptStatus::Processing);
        $this->assertTrue($this->transitionService->transition($this->confirmedUpdate($attempt2))['success']);

        $sub->refresh();
        $cycle2->refresh();
        $this->assertSame(BillingSubscriptionStatus::Active, $sub->status);
        $this->assertTrue($sub->current_period_end->equalTo($cycle2->period_end));
        $this->assertTrue($sub->next_charge_at->equalTo($sub->current_period_end));
        $this->assertTrue($sub->next_charge_at->greaterThan($firstEnd));
    }

    public function test_repeated_confirmed_is_idempotent_for_next_charge(): void
    {
        $sub = $this->createConsentedSubscription();
        $cycle = $this->createCycleForSubscription($sub, now(), now()->addMonth());
        $attempt = $this->createAttemptOnCycle($cycle, PaymentAttemptStatus::Processing);

        $update = $this->confirmedUpdate($attempt);
        $this->assertTrue($this->transitionService->transition($update)['success']);

        $sub->refresh();
        $scheduled = $sub->next_charge_at;
        $this->assertNotNull($scheduled);

        // Same payload → duplicate event → idempotent success
        $result2 = $this->transitionService->transition($this->confirmedUpdate($attempt));
        $this->assertTrue($result2['success']);

        $sub->refresh();
        $attempt->refresh();
        $this->assertTrue($sub->next_charge_at->equalTo($scheduled));
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
    }

    public function test_refund_of_last_granting_cycle_clears_next_charge(): void
    {
        $sub = $this->createConsentedSubscription();
        $cycle = $this->createCycleForSubscription($sub, now(), now()->addMonth());
        $attempt1 = $this->createAttemptOnCycle($cycle, PaymentAttemptStatus::Processing);
        $this->assertTrue($this->transitionService->transition($this->confirmedUpdate($attempt1))['success']);

        $sub->refresh();
        $this->assertNotNull($sub->next_charge_at);

        $attempt2 = $this->createAttemptOnCycle($cycle, PaymentAttemptStatus::Processing);
        $result = $this->transitionService->transition(new ProviderStatusUpdate(
            provider: 'tbank',
            providerPaymentId: $attempt2->provider_payment_id,
            internalOrderId: $attempt2->internal_order_id,
            normalizedStatus: PaymentAttemptStatus::Refunded,
            amount: 490,
            currency: 'RUB',
            raw: [
                'Status' => 'REFUNDED',
                'PaymentId' => $attempt2->provider_payment_id,
                'Amount' => 49000,
            ],
        ));

        $this->assertTrue($result['success']);

        $sub->refresh();
        $this->assertSame(BillingSubscriptionStatus::Canceled, $sub->status);
        $this->assertNull($sub->next_charge_at);

        // Consent fields untouched
        $this->assertNotNull($sub->auto_renew_consent_at);
        $this->assertSame(1, $sub->renewal_period_months);
    }

    // ── Renewal insufficient-funds lifecycle ──

    public function test_renewal_insufficient_funds_at_period_end_expires_subscription(): void
    {
        Notification::fake();
        $this->spy(MasterNotificationService::class);

        [$sub, $renewalCycle, , $attempt] = $this->prepareRenewalFailure(now()->subDay());
        $originalEnd = $sub->current_period_end;

        $result = $this->transitionService->transition($this->insufficientFundsUpdate($attempt));

        $this->assertTrue($result['success']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('insufficient_funds', $attempt->failure_category);

        $renewalCycle->refresh();
        $this->assertSame(BillingCycleStatus::Failed, $renewalCycle->status);

        $sub->refresh();
        $this->assertSame(BillingSubscriptionStatus::Expired, $sub->status);
        $this->assertNull($sub->next_charge_at);
        $this->assertTrue($sub->cancel_at_period_end);
        $this->assertNull($sub->grace_until);
        $this->assertTrue($sub->current_period_end->equalTo($originalEnd));
        $this->assertNotNull($sub->auto_renew_consent_at);
    }

    public function test_renewal_insufficient_funds_before_period_end_keeps_subscription_active(): void
    {
        Notification::fake();
        $this->spy(MasterNotificationService::class);

        [$sub, , , $attempt] = $this->prepareRenewalFailure(now()->addDays(10));
        $originalEnd = $sub->current_period_end;

        $result = $this->transitionService->transition($this->insufficientFundsUpdate($attempt));

        $this->assertTrue($result['success']);

        $sub->refresh();
        $this->assertSame(BillingSubscriptionStatus::Active, $sub->status);
        $this->assertTrue($sub->current_period_end->equalTo($originalEnd));
        $this->assertNull($sub->next_charge_at);
        $this->assertTrue($sub->cancel_at_period_end);
        $this->assertNull($sub->grace_until);
    }

    public function test_renewal_insufficient_funds_keeps_payment_method_active(): void
    {
        Notification::fake();
        $this->spy(MasterNotificationService::class);

        [, , $method, $attempt] = $this->prepareRenewalFailure(now()->addDays(10));

        $this->transitionService->transition($this->insufficientFundsUpdate($attempt));

        $method->refresh();
        $this->assertSame('active', $method->status);
        $this->assertTrue($method->is_default);
        $this->assertNull($method->revoked_at);
    }

    public function test_renewal_insufficient_funds_sends_notification_once(): void
    {
        Notification::fake();
        $notifier = $this->spy(MasterNotificationService::class);

        [, , , $attempt] = $this->prepareRenewalFailure(now()->addDays(10));

        $this->transitionService->transition($this->insufficientFundsUpdate($attempt));

        Notification::assertSentToTimes($this->master, RenewalInsufficientFundsNotification::class, 1);
        $notifier->shouldHaveReceived('sendToMaster')
            ->once()
            ->withArgs(fn ($master, $text) => $master->is($this->master) && $text === RenewalInsufficientFundsNotification::TEXT);
        // Committed transition → exactly one row, written after the send.
        $this->assertSame(1, NotificationLog::where('type', 'renewal_insufficient_funds')
            ->where('period_key', $attempt->id)
            ->count());
        $this->assertTrue(
            NotificationLog::hasBeenSent($this->workspace->id, 'renewal_insufficient_funds', $attempt->id)
        );
    }

    public function test_rolled_back_transition_sends_no_notification_and_writes_no_log(): void
    {
        Notification::fake();
        $notifier = $this->spy(MasterNotificationService::class);

        [, , , $attempt] = $this->prepareRenewalFailure(now()->addDays(10));

        // Outer transaction: the payment transition commits its inner savepoint
        // but its DB::afterCommit callback is staged on the outer level —
        // a rollback must discard both the send and any NotificationLog row.
        DB::beginTransaction();
        $result = $this->transitionService->transition($this->insufficientFundsUpdate($attempt));
        $this->assertTrue($result['success']);
        DB::rollBack();

        Notification::assertNothingSent();
        $notifier->shouldNotHaveReceived('sendToMaster');
        $this->assertSame(0, NotificationLog::where('type', 'renewal_insufficient_funds')->count());
    }

    public function test_failed_notification_send_does_not_record_notification_log(): void
    {
        Notification::fake();
        $notifier = $this->mock(MasterNotificationService::class);
        $notifier->shouldReceive('sendToMaster')->once()->andThrow(new \RuntimeException('MAX down'));

        [, , , $attempt] = $this->prepareRenewalFailure(now()->addDays(10));

        $this->transitionService->transition($this->insufficientFundsUpdate($attempt));

        // The log row must mean a completed post-commit send attempt, not a
        // pre-commit claim: a failed send leaves no NotificationLog row.
        Notification::assertNothingSent();
        $this->assertSame(0, NotificationLog::where('type', 'renewal_insufficient_funds')
            ->where('period_key', $attempt->id)
            ->count());
    }

    public function test_duplicate_renewal_failure_does_not_duplicate_notification(): void
    {
        Notification::fake();
        $notifier = $this->spy(MasterNotificationService::class);

        [, , , $attempt] = $this->prepareRenewalFailure(now()->addDays(10));
        $update = $this->insufficientFundsUpdate($attempt);

        $this->assertTrue($this->transitionService->transition($update)['success']);

        // Exact duplicate payload — dropped at provider event claim.
        $this->assertTrue($this->transitionService->transition($update)['success']);

        // Different payload, same terminal status — idempotent same-status no-op.
        $this->assertTrue($this->transitionService->transition(new ProviderStatusUpdate(
            provider: 'tbank',
            providerPaymentId: $attempt->provider_payment_id,
            internalOrderId: $attempt->internal_order_id,
            normalizedStatus: PaymentAttemptStatus::FailedTerminal,
            raw: [
                'Status' => 'REJECTED',
                'ErrorCode' => '103',
                'PaymentId' => $attempt->provider_payment_id,
                'TerminalKey' => self::class, // different fingerprint
            ],
            failureCode: '103',
            failureCategory: 'insufficient_funds',
        ))['success']);

        Notification::assertSentToTimes($this->master, RenewalInsufficientFundsNotification::class, 1);
        $notifier->shouldHaveReceived('sendToMaster')->once();
        $this->assertSame(1, NotificationLog::where('type', 'renewal_insufficient_funds')
            ->where('period_key', $attempt->id)
            ->count());
    }

    public function test_ordinary_provider_failed_terminal_failure_does_not_run_renewal_lifecycle(): void
    {
        Notification::fake();
        $notifier = $this->spy(MasterNotificationService::class);

        [$sub, , , $attempt] = $this->prepareRenewalFailure(now()->addDays(10));

        $result = $this->transitionService->transition(new ProviderStatusUpdate(
            provider: 'tbank',
            providerPaymentId: $attempt->provider_payment_id,
            internalOrderId: $attempt->internal_order_id,
            normalizedStatus: PaymentAttemptStatus::FailedTerminal,
            raw: [
                'Status' => 'REJECTED',
                'ErrorCode' => '5106',
                'PaymentId' => $attempt->provider_payment_id,
            ],
            failureCode: '5106',
            // no failureCategory → provider_failed
        ));

        $this->assertTrue($result['success']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('provider_failed', $attempt->failure_category);

        $sub->refresh();
        $this->assertFalse($sub->cancel_at_period_end);
        $this->assertNotNull($sub->next_charge_at);
        $this->assertSame(BillingSubscriptionStatus::Active, $sub->status);

        Notification::assertNothingSent();
        $notifier->shouldNotHaveReceived('sendToMaster');
        $this->assertFalse(
            NotificationLog::hasBeenSent($this->workspace->id, 'renewal_insufficient_funds', $attempt->id)
        );
    }

    public function test_non_renewal_insufficient_funds_does_not_run_renewal_lifecycle(): void
    {
        Notification::fake();
        $notifier = $this->spy(MasterNotificationService::class);

        [$sub, , , $attempt] = $this->prepareRenewalFailure(now()->addDays(10));
        $attempt->update(['metadata' => []]); // initial / non-renewal attempt

        $this->transitionService->transition($this->insufficientFundsUpdate($attempt));

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('insufficient_funds', $attempt->failure_category);

        $sub->refresh();
        $this->assertFalse($sub->cancel_at_period_end);
        $this->assertNotNull($sub->next_charge_at);
        $this->assertSame(BillingSubscriptionStatus::Active, $sub->status);

        Notification::assertNothingSent();
        $notifier->shouldNotHaveReceived('sendToMaster');
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

    private function createConsentedSubscription(array $overrides = []): BillingSubscription
    {
        return BillingSubscription::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::Active,
            'renewal_period_months' => 1,
            'auto_renew_consent_at' => now(),
            'auto_renew_consent_version' => config('billing.recurring_terms_version'),
            'cancel_at_period_end' => false,
        ], $overrides));
    }

    private function createCycleForSubscription(
        BillingSubscription $sub,
        \DateTimeInterface $start,
        \DateTimeInterface $end,
    ): BillingCycle {
        return BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => $start,
            'period_end' => $end,
            'status' => BillingCycleStatus::Pending,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => 'payment',
        ]);
    }

    private function createAttemptOnCycle(
        BillingCycle $cycle,
        PaymentAttemptStatus $status,
    ): PaymentAttempt {
        $maxNumber = PaymentAttempt::where('billing_cycle_id', $cycle->id)
            ->max('attempt_number') ?? 0;

        return PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'tbank',
            'attempt_number' => $maxNumber + 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'core_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => 'tbank_'.bin2hex(random_bytes(8)),
            'status' => $status,
            'initiated_at' => now(),
        ]);
    }

    private function confirmedUpdate(PaymentAttempt $attempt): ProviderStatusUpdate
    {
        return new ProviderStatusUpdate(
            provider: 'tbank',
            providerPaymentId: $attempt->provider_payment_id,
            internalOrderId: $attempt->internal_order_id,
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: 490,
            currency: 'RUB',
            raw: [
                'Status' => 'CONFIRMED',
                'PaymentId' => $attempt->provider_payment_id,
                'Amount' => 49000,
            ],
        );
    }

    /**
     * Consented subscription whose current period is already paid (Paid cycle
     * with period_end === current_period_end) plus a pending renewal cycle,
     * an active default payment method and a Processing renewal attempt.
     *
     * @return array{0: BillingSubscription, 1: BillingCycle, 2: PaymentMethod, 3: PaymentAttempt}
     */
    private function prepareRenewalFailure(CarbonInterface $periodEnd): array
    {
        $sub = $this->createConsentedSubscription([
            'current_period_start' => $periodEnd->copy()->subMonth(),
            'current_period_end' => $periodEnd,
            'next_charge_at' => $periodEnd,
            'grace_until' => now()->addDays(3),
        ]);

        $paidCycle = $this->createCycleForSubscription($sub, $sub->current_period_start, $periodEnd);
        $paidCycle->update(['status' => BillingCycleStatus::Paid]);

        $renewalCycle = BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => $periodEnd,
            'period_end' => $periodEnd->copy()->addMonth(),
            'status' => BillingCycleStatus::Pending,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Renewal,
        ]);

        $method = PaymentMethod::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'tbank',
            'type' => 'card',
            'provider_reference' => 'RebillId123',
            'status' => 'active',
            'is_default' => true,
        ]);

        $attempt = PaymentAttempt::create([
            'billing_cycle_id' => $renewalCycle->id,
            'payment_method_id' => $method->id,
            'provider' => 'tbank',
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'renew_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => 'tbank_'.bin2hex(random_bytes(8)),
            'status' => PaymentAttemptStatus::Processing,
            'initiated_at' => now(),
            'metadata' => [
                'renewal' => true,
                'billing_subscription_id' => $sub->id,
            ],
        ]);

        return [$sub, $renewalCycle, $method, $attempt];
    }

    /**
     * Normalized T-Bank Charge decline for insufficient funds, exactly as
     * chargeRecurringPayment() would produce it.
     */
    private function insufficientFundsUpdate(PaymentAttempt $attempt): ProviderStatusUpdate
    {
        return new ProviderStatusUpdate(
            provider: 'tbank',
            providerPaymentId: $attempt->provider_payment_id,
            internalOrderId: $attempt->internal_order_id,
            normalizedStatus: PaymentAttemptStatus::FailedTerminal,
            raw: [
                'Status' => 'REJECTED',
                'ErrorCode' => '103',
                'PaymentId' => $attempt->provider_payment_id,
                'Amount' => 49000,
            ],
            failureCode: '103',
            failureCategory: 'insufficient_funds',
            failureMessage: 'Недостаточно средств на карте',
        );
    }
}
