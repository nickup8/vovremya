<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Models\PlanPrice;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\RenewalService;
use App\Services\Payment\DTOs\ProviderStatusUpdate;
use App\Services\Payment\PaymentTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RenewalTechnicalRetryTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;

    private Workspace $workspace;

    private RenewalService $renewalService;

    private PaymentTransitionService $transitionService;

    protected function setUp(): void
    {
        parent::setUp();

        // DB timestamps have second precision — freeze at a whole second
        // so equalTo() comparisons against persisted values are exact.
        $this->freezeSecond();

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

        PlanPrice::create([
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

        $this->renewalService = app(RenewalService::class);
        $this->transitionService = app(PaymentTransitionService::class);
    }

    // ── Happy path ──

    public function test_past_due_with_active_grace_creates_attempt_number_two(): void
    {
        Http::fake();
        [$sub, $cycle, $method, $first] = $this->technicalFailureState();

        $retry = $this->renewalService->prepareTechnicalRetry($sub);

        $this->assertNotNull($retry);
        $this->assertSame($first->billing_cycle_id, $retry->billing_cycle_id);
        $this->assertSame($cycle->id, $retry->billing_cycle_id);
        $this->assertSame(2, $retry->attempt_number);
        $this->assertSame($cycle->amount, $retry->amount);
        $this->assertSame($cycle->currency, $retry->currency);
        $this->assertSame($method->id, $retry->payment_method_id);
        $this->assertSame('tbank', $retry->provider);
        $this->assertSame(PaymentAttemptStatus::Created, $retry->status);
        $this->assertNull($retry->provider_payment_id);
        $this->assertNotNull($retry->initiated_at);
        $this->assertStringStartsWith('renew_', $retry->internal_order_id);
        $this->assertNotSame($first->internal_order_id, $retry->internal_order_id);

        // Metadata marks the retry lineage.
        $this->assertTrue($retry->metadata['renewal'] ?? false);
        $this->assertSame($sub->id, $retry->metadata['billing_subscription_id']);
        $this->assertTrue($retry->metadata['technical_retry'] ?? false);
        $this->assertSame($first->id, $retry->metadata['retry_of_attempt_id']);

        // Preparing never calls the bank.
        Http::assertNothingSent();
    }

    public function test_retry_uses_current_default_payment_method(): void
    {
        [$sub, , , $first] = $this->technicalFailureState();

        // A new card becomes the current default.
        PaymentMethod::whereKey($first->payment_method_id)->update(['is_default' => false]);

        $stale = PaymentMethod::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'tbank',
            'type' => 'card',
            'provider_reference' => 'stale_rebill',
            'status' => 'active',
            'is_default' => false,
        ]);

        $newDefault = PaymentMethod::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'tbank',
            'type' => 'card',
            'provider_reference' => 'fresh_rebill',
            'status' => 'active',
            'is_default' => true,
        ]);

        $retry = $this->renewalService->prepareTechnicalRetry($sub);

        $this->assertNotNull($retry);
        $this->assertSame($newDefault->id, $retry->payment_method_id);
        $this->assertNotSame($first->payment_method_id, $retry->payment_method_id);
        $this->assertNotSame($stale->id, $retry->payment_method_id);
    }

    public function test_old_attempt_is_left_untouched(): void
    {
        [$sub, , , $first] = $this->technicalFailureState();
        $before = $first->toArray();

        $this->renewalService->prepareTechnicalRetry($sub);

        $first->refresh();
        // assertEquals: same rows, key order of toArray() is irrelevant.
        $this->assertEquals($before, $first->toArray());
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $first->status);
        $this->assertSame('reconciliation_timeout', $first->failure_category);
        $this->assertArrayNotHasKey('technical_retry', $first->metadata);
        $this->assertArrayNotHasKey('charge_dispatch_started_at', $first->metadata);
    }

    public function test_failed_cycle_reopens_as_pending(): void
    {
        [$sub, $cycle] = $this->technicalFailureState();
        $this->assertSame(BillingCycleStatus::Failed, $cycle->status);

        $this->renewalService->prepareTechnicalRetry($sub);

        $cycle->refresh();
        $this->assertSame(BillingCycleStatus::Pending, $cycle->status);
    }

    public function test_repeated_prepare_does_not_create_a_third_attempt(): void
    {
        [$sub] = $this->technicalFailureState();

        $first = $this->renewalService->prepareTechnicalRetry($sub);
        $this->assertNotNull($first);

        $this->assertNull($this->renewalService->prepareTechnicalRetry($sub));
        $this->assertNull($this->renewalService->prepareTechnicalRetry($sub));

        $this->assertSame(2, PaymentAttempt::count());
    }

    // ── Gating ──

    public function test_expired_grace_blocks_retry(): void
    {
        [$sub] = $this->technicalFailureState(['grace_until' => now()->subMinute()]);

        $this->assertNull($this->renewalService->prepareTechnicalRetry($sub));
        $this->assertSame(1, PaymentAttempt::count());
    }

    public function test_missing_grace_blocks_retry(): void
    {
        [$sub] = $this->technicalFailureState(['grace_until' => null]);

        $this->assertNull($this->renewalService->prepareTechnicalRetry($sub));
        $this->assertSame(1, PaymentAttempt::count());
    }

    public function test_subscription_not_past_due_blocks_retry(): void
    {
        [$sub] = $this->technicalFailureState([
            'status' => BillingSubscriptionStatus::Active,
        ]);

        $this->assertNull($this->renewalService->prepareTechnicalRetry($sub));
        $this->assertSame(1, PaymentAttempt::count());
    }

    public function test_canceled_auto_renew_blocks_retry(): void
    {
        [$sub] = $this->technicalFailureState(['cancel_at_period_end' => true]);

        $this->assertNull($this->renewalService->prepareTechnicalRetry($sub));
        $this->assertSame(1, PaymentAttempt::count());
    }

    public function test_missing_consent_blocks_retry(): void
    {
        [$sub] = $this->technicalFailureState(['auto_renew_consent_at' => null]);

        $this->assertNull($this->renewalService->prepareTechnicalRetry($sub));
        $this->assertSame(1, PaymentAttempt::count());
    }

    public function test_insufficient_funds_failure_blocks_retry(): void
    {
        [$sub, , , $first] = $this->technicalFailureState();
        $first->update(['failure_category' => 'insufficient_funds']);

        $this->assertNull($this->renewalService->prepareTechnicalRetry($sub));
        $this->assertSame(1, PaymentAttempt::count());
    }

    public function test_non_renewal_failure_blocks_retry(): void
    {
        [$sub, , , $first] = $this->technicalFailureState();
        $first->update(['metadata' => ['billing_subscription_id' => $sub->id]]);

        $this->assertNull($this->renewalService->prepareTechnicalRetry($sub));
        $this->assertSame(1, PaymentAttempt::count());
    }

    public function test_in_flight_attempt_blocks_retry(): void
    {
        [$sub, $cycle, $method] = $this->technicalFailureState();

        PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'payment_method_id' => $method->id,
            'provider' => 'tbank',
            'attempt_number' => 2,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'renew_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => 'tbank_'.bin2hex(random_bytes(8)),
            'status' => PaymentAttemptStatus::Processing,
            'initiated_at' => now(),
            'metadata' => ['renewal' => true, 'billing_subscription_id' => $sub->id],
        ]);

        $this->assertNull($this->renewalService->prepareTechnicalRetry($sub));
        $this->assertSame(2, PaymentAttempt::count());
    }

    public function test_paid_cycle_blocks_retry(): void
    {
        [$sub, $cycle] = $this->technicalFailureState();
        $cycle->update(['status' => BillingCycleStatus::Paid]);

        $this->assertNull($this->renewalService->prepareTechnicalRetry($sub));
        $this->assertSame(1, PaymentAttempt::count());
    }

    public function test_missing_payment_method_blocks_retry(): void
    {
        [$sub] = $this->technicalFailureState();
        PaymentMethod::query()->update(['status' => 'revoked']);

        $this->assertNull($this->renewalService->prepareTechnicalRetry($sub));
        $this->assertSame(1, PaymentAttempt::count());
    }

    public function test_missing_renewal_cycle_blocks_retry(): void
    {
        [$sub, $cycle] = $this->technicalFailureState();
        // Horizon moved after the cycle was created — no matching cycle left.
        $sub->update(['current_period_end' => $cycle->period_end]);

        $this->assertNull($this->renewalService->prepareTechnicalRetry($sub));
        $this->assertSame(1, PaymentAttempt::count());
    }

    // ── Outcomes ──

    public function test_insufficient_funds_on_retry_stops_auto_renew_without_grace(): void
    {
        [$sub] = $this->technicalFailureState();

        $retry = $this->renewalService->prepareTechnicalRetry($sub);
        $this->assertNotNull($retry);

        $result = $this->transitionService->transition(new ProviderStatusUpdate(
            provider: 'tbank',
            providerPaymentId: 'tbank_retry_payment',
            internalOrderId: $retry->internal_order_id,
            normalizedStatus: PaymentAttemptStatus::FailedTerminal,
            raw: [
                'Status' => 'REJECTED',
                'ErrorCode' => '103',
                'PaymentId' => 'tbank_retry_payment',
            ],
            failureCode: '103',
            failureCategory: 'insufficient_funds',
        ));
        $this->assertTrue($result['success']);

        $sub->refresh();
        $this->assertTrue($sub->cancel_at_period_end);
        $this->assertNull($sub->grace_until);
        $this->assertNull($sub->next_charge_at);
        $this->assertNotSame(BillingSubscriptionStatus::PastDue, $sub->status);

        // The insufficient-funds flow owns the terminal state — no retry left.
        $this->assertNull($this->renewalService->prepareTechnicalRetry($sub));
        $this->assertSame(2, PaymentAttempt::count());
    }

    public function test_successful_retry_activates_and_extends_horizon_and_clears_grace(): void
    {
        [$sub, $cycle] = $this->technicalFailureState();

        $retry = $this->renewalService->prepareTechnicalRetry($sub);
        $this->assertNotNull($retry);

        $result = $this->transitionService->transition(new ProviderStatusUpdate(
            provider: 'tbank',
            providerPaymentId: 'tbank_retry_payment',
            internalOrderId: $retry->internal_order_id,
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: $retry->amount,
            currency: $retry->currency,
            raw: [
                'Status' => 'CONFIRMED',
                'PaymentId' => 'tbank_retry_payment',
                'OrderId' => $retry->internal_order_id,
            ],
        ));
        $this->assertTrue($result['success']);

        $retry->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $retry->status);

        $cycle->refresh();
        $this->assertSame(BillingCycleStatus::Paid, $cycle->status);

        $sub->refresh();
        $this->assertSame(BillingSubscriptionStatus::Active, $sub->status);
        $this->assertTrue($sub->current_period_end->equalTo($cycle->period_end));
        $this->assertNotNull($sub->next_charge_at);
        $this->assertTrue($sub->next_charge_at->equalTo($sub->current_period_end));
        $this->assertNull($sub->grace_until);
        $this->assertFalse($sub->cancel_at_period_end);
    }

    public function test_second_technical_timeout_does_not_extend_original_grace(): void
    {
        [$sub, , , $first] = $this->technicalFailureState();
        $originalGrace = $sub->grace_until->copy();
        $originalPeriodEnd = $sub->current_period_end->copy();

        $retry = $this->renewalService->prepareTechnicalRetry($sub);
        $this->assertNotNull($retry);

        $result = $this->transitionService->transition(new ProviderStatusUpdate(
            provider: 'tbank',
            providerPaymentId: 'tbank_retry_payment',
            internalOrderId: $retry->internal_order_id,
            normalizedStatus: PaymentAttemptStatus::FailedTerminal,
            raw: [
                'Status' => 'REJECTED',
                'ErrorCode' => '9999',
                'PaymentId' => 'tbank_retry_payment',
            ],
            failureCode: '9999',
            failureCategory: 'reconciliation_timeout',
            failureMessage: 'Не удалось подтвердить статус платежа',
        ));
        $this->assertTrue($result['success']);

        $retry->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $retry->status);

        $sub->refresh();
        $this->assertSame(BillingSubscriptionStatus::PastDue, $sub->status);
        $this->assertNotNull($sub->grace_until);
        // The original window survives untouched — no second N-day extension.
        $this->assertTrue($sub->grace_until->equalTo($originalGrace));
        $this->assertTrue($sub->current_period_end->equalTo($originalPeriodEnd));
        $this->assertNull($sub->next_charge_at);
        $this->assertFalse($sub->cancel_at_period_end);

        // Still at most one technical retry per cycle.
        $this->assertNull($this->renewalService->prepareTechnicalRetry($sub));
        $this->assertSame(2, PaymentAttempt::count());

        $first->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $first->status);
    }

    // ── Helpers ──

    /**
     * PastDue subscription with an open technical grace window: paid
     * granting cycle + failed renewal cycle + one terminal technical
     * failure attempt + active default payment method.
     *
     * @return array{0: BillingSubscription, 1: BillingCycle, 2: PaymentMethod, 3: PaymentAttempt}
     */
    private function technicalFailureState(
        array $subOverrides = [],
        array $attemptOverrides = [],
    ): array {
        $periodEnd = now()->subDay();

        $sub = BillingSubscription::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::PastDue,
            'renewal_period_months' => 1,
            'auto_renew_consent_at' => now()->subDays(5),
            'auto_renew_consent_version' => config('billing.recurring_terms_version'),
            'cancel_at_period_end' => false,
            'current_period_start' => $periodEnd->copy()->subMonth(),
            'current_period_end' => $periodEnd,
            'next_charge_at' => null,
            'grace_until' => now()->addDays(3),
        ], $subOverrides));

        BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => $periodEnd->copy()->subMonth(),
            'period_end' => $periodEnd,
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::LegacyGrant,
        ]);

        $renewalCycle = BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => $periodEnd,
            'period_end' => $periodEnd->copy()->addMonth(),
            'status' => BillingCycleStatus::Failed,
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

        $attempt = PaymentAttempt::create(array_merge([
            'billing_cycle_id' => $renewalCycle->id,
            'payment_method_id' => $method->id,
            'provider' => 'tbank',
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'renew_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => 'tbank_'.bin2hex(random_bytes(8)),
            'status' => PaymentAttemptStatus::FailedTerminal,
            'failure_code' => '9999',
            'failure_category' => 'reconciliation_timeout',
            'failure_message' => 'Не удалось подтвердить статус платежа',
            'initiated_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(30),
            'metadata' => [
                'renewal' => true,
                'billing_subscription_id' => $sub->id,
            ],
        ], $attemptOverrides));

        return [$sub, $renewalCycle, $method, $attempt];
    }
}
