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
use App\Services\Billing\EntitlementService;
use App\Services\Payment\DTOs\ProviderStatusUpdate;
use App\Services\Payment\PaymentReconciliationService;
use App\Services\Payment\PaymentTransitionService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RenewalTechnicalGraceTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;

    private Workspace $workspace;

    private PaymentTransitionService $transitionService;

    private EntitlementService $entitlements;

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
            'valid_from' => now(),
            'is_active' => true,
        ]);

        $this->transitionService = app(PaymentTransitionService::class);
        $this->entitlements = app(EntitlementService::class);
    }

    // ── Grace lifecycle ──

    public function test_technical_renewal_failure_sets_past_due_with_three_day_grace(): void
    {
        $periodEnd = now()->subDay();
        [$sub, , $method, $attempt] = $this->prepareRenewal($periodEnd);

        $result = $this->transitionService->transition($this->technicalFailureUpdate($attempt));
        $this->assertTrue($result['success']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('reconciliation_timeout', $attempt->failure_category);

        $sub->refresh();
        $this->assertSame(BillingSubscriptionStatus::PastDue, $sub->status);
        // Paid period already ended → base = now()
        $this->assertTrue($sub->grace_until->equalTo(now()->addDays(3)));
        $this->assertNull($sub->next_charge_at);
        // Spec invariants: never touched by a technical failure
        $this->assertFalse($sub->cancel_at_period_end);
        $this->assertTrue($sub->current_period_end->equalTo($periodEnd));

        // Payment method untouched
        $method->refresh();
        $this->assertSame('active', $method->status);
        $this->assertTrue($method->is_default);
        $this->assertNull($method->revoked_at);
    }

    public function test_pro_entitlement_holds_during_grace_and_expires_after(): void
    {
        $periodEnd = now()->subDay();
        [$sub, , , $attempt] = $this->prepareRenewal($periodEnd);

        $this->transitionService->transition($this->technicalFailureUpdate($attempt));
        $sub->refresh();

        // During grace: Pro, expiresAt = grace_until
        $plan = $this->entitlements->currentPlan($this->workspace);
        $this->assertNotNull($plan);
        $this->assertSame('pro', $plan->code);
        $this->assertTrue($plan->expiresAt->equalTo($sub->grace_until));

        // At the strict boundary and after → default (start) entitlement
        $this->assertNull($this->entitlements->currentPlan($this->workspace, $sub->grace_until));
        $this->assertNull($this->entitlements->currentPlan(
            $this->workspace,
            $sub->grace_until->copy()->addMinute(),
        ));
        $this->assertFalse(
            $this->entitlements->hasPlan($this->workspace, 'pro', $sub->grace_until->copy()->addMinute()),
        );
    }

    public function test_grace_duration_comes_from_config_not_hardcode(): void
    {
        config(['billing.renewal.technical_grace_days' => 1]);

        $periodEnd = now()->subDay();
        [$sub, , , $attempt] = $this->prepareRenewal($periodEnd);

        $this->transitionService->transition($this->technicalFailureUpdate($attempt));

        $sub->refresh();
        $this->assertSame(BillingSubscriptionStatus::PastDue, $sub->status);
        $this->assertTrue($sub->grace_until->equalTo(now()->addDay()));
    }

    public function test_grace_before_period_end_counts_from_period_end(): void
    {
        $periodEnd = now()->addDays(10);
        [$sub, , , $attempt] = $this->prepareRenewal($periodEnd);
        $originalEnd = $sub->current_period_end;

        $this->transitionService->transition($this->technicalFailureUpdate($attempt));

        $sub->refresh();
        $this->assertSame(BillingSubscriptionStatus::PastDue, $sub->status);
        // base = max(current_period_end, now()) → period_end is in the future
        $this->assertTrue($sub->grace_until->equalTo($periodEnd->copy()->addDays(3)));
        $this->assertTrue($sub->current_period_end->equalTo($originalEnd));
        $this->assertFalse($sub->cancel_at_period_end);
        $this->assertNull($sub->next_charge_at);
    }

    public function test_reconciliation_age_release_applies_technical_grace(): void
    {
        $periodEnd = now()->subDay();
        [$sub, , , $attempt] = $this->prepareRenewal($periodEnd);

        // Older than max_age_with_provider_id (24h) → age-release, no polling
        PaymentAttempt::where('id', $attempt->id)
            ->update(['created_at' => now()->subDays(2)]);

        $result = app(PaymentReconciliationService::class)->reconcile();
        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['errors']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('reconciliation_timeout', $attempt->failure_category);

        $sub->refresh();
        $this->assertSame(BillingSubscriptionStatus::PastDue, $sub->status);
        $this->assertTrue($sub->grace_until->equalTo(now()->addDays(3)));
        $this->assertNull($sub->next_charge_at);
        $this->assertFalse($sub->cancel_at_period_end);
        $this->assertTrue($sub->current_period_end->equalTo($periodEnd));

        $plan = $this->entitlements->currentPlan($this->workspace);
        $this->assertNotNull($plan);
        $this->assertSame('pro', $plan->code);
    }

    // ── What must NOT get a grace ──

    public function test_insufficient_funds_renewal_does_not_get_grace(): void
    {
        $periodEnd = now()->subDay();
        [$sub, , , $attempt] = $this->prepareRenewal($periodEnd);

        $result = $this->transitionService->transition(new ProviderStatusUpdate(
            provider: 'tbank',
            providerPaymentId: $attempt->provider_payment_id,
            internalOrderId: $attempt->internal_order_id,
            normalizedStatus: PaymentAttemptStatus::FailedTerminal,
            raw: [
                'Status' => 'REJECTED',
                'ErrorCode' => '103',
                'PaymentId' => $attempt->provider_payment_id,
            ],
            failureCode: '103',
            failureCategory: 'insufficient_funds',
        ));
        $this->assertTrue($result['success']);

        $sub->refresh();
        $this->assertSame(BillingSubscriptionStatus::Expired, $sub->status);
        $this->assertNotSame(BillingSubscriptionStatus::PastDue, $sub->status);
        $this->assertNull($sub->grace_until);
        $this->assertTrue($sub->cancel_at_period_end);

        // No Pro after the paid period
        $this->assertNull($this->entitlements->currentPlan($this->workspace));
    }

    public function test_non_renewal_payment_with_reconciliation_timeout_does_not_get_grace(): void
    {
        $periodEnd = now()->addDays(10);
        [$sub, , , $attempt] = $this->prepareRenewal($periodEnd);

        // Ordinary checkout attempt — no renewal flag
        $attempt->update(['metadata' => ['billing_subscription_id' => $sub->id]]);

        $this->transitionService->transition($this->technicalFailureUpdate($attempt));

        $sub->refresh();
        $this->assertSame(BillingSubscriptionStatus::Active, $sub->status);
        $this->assertNull($sub->grace_until);
        $this->assertFalse($sub->cancel_at_period_end);
        $this->assertNotNull($sub->next_charge_at);
    }

    public function test_past_due_without_technical_failure_does_not_grant_pro(): void
    {
        $sub = BillingSubscription::create([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::PastDue,
            'current_period_end' => now()->subDay(),
            'grace_until' => now()->addDays(3),
            'cancel_at_period_end' => false,
        ]);

        $this->assertNull($this->entitlements->currentPlan($this->workspace));
        $this->assertFalse($this->entitlements->hasPlan($this->workspace, 'pro'));
        $this->assertDatabaseHas('billing_subscriptions', [
            'id' => $sub->id,
            'status' => BillingSubscriptionStatus::PastDue->value,
        ]);
    }

    public function test_canceled_subscription_does_not_get_grace(): void
    {
        $periodEnd = now()->subDay();
        [$sub, , , $attempt] = $this->prepareRenewal($periodEnd);

        $this->transitionService->transition($this->technicalFailureUpdate($attempt));

        $sub->refresh()->update(['status' => BillingSubscriptionStatus::Canceled]);

        $this->assertNull($this->entitlements->currentPlan($this->workspace));
    }

    public function test_expired_subscription_does_not_get_grace(): void
    {
        $periodEnd = now()->subDay();
        [$sub, , , $attempt] = $this->prepareRenewal($periodEnd);

        $this->transitionService->transition($this->technicalFailureUpdate($attempt));

        $sub->refresh()->update(['status' => BillingSubscriptionStatus::Expired]);

        $this->assertNull($this->entitlements->currentPlan($this->workspace));
    }

    // ── What must stay unchanged ──

    public function test_paid_pro_entitlement_unchanged(): void
    {
        $periodEnd = now()->addDays(15);
        [$sub] = $this->prepareRenewal($periodEnd);

        $plan = $this->entitlements->currentPlan($this->workspace);

        $this->assertNotNull($plan);
        $this->assertSame('pro', $plan->code);
        $this->assertTrue($plan->expiresAt->equalTo($periodEnd));

        $sub->refresh();
        $this->assertSame(BillingSubscriptionStatus::Active, $sub->status);
        $this->assertNull($sub->grace_until);
        $this->assertFalse($sub->cancel_at_period_end);
    }

    public function test_ordinary_failed_renewal_keeps_subscription_untouched(): void
    {
        $periodEnd = now()->addDays(10);
        [$sub, , , $attempt] = $this->prepareRenewal($periodEnd);

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

        $sub->refresh();
        $this->assertSame(BillingSubscriptionStatus::Active, $sub->status);
        $this->assertNull($sub->grace_until);
        $this->assertFalse($sub->cancel_at_period_end);
        $this->assertNotNull($sub->next_charge_at);
        $this->assertTrue($sub->current_period_end->equalTo($periodEnd));
    }

    // ── Helpers ──

    /**
     * Paid Pro period (granting cycle) + pending renewal cycle + active
     * default payment method + Processing renewal attempt.
     *
     * @return array{0: BillingSubscription, 1: CarbonInterface, 2: PaymentMethod, 3: PaymentAttempt}
     */
    private function prepareRenewal(CarbonInterface $periodEnd): array
    {
        $sub = BillingSubscription::create([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::Active,
            'renewal_period_months' => 1,
            'auto_renew_consent_at' => now(),
            'auto_renew_consent_version' => config('billing.recurring_terms_version'),
            'cancel_at_period_end' => false,
            'current_period_start' => $periodEnd->copy()->subMonth(),
            'current_period_end' => $periodEnd,
            'next_charge_at' => $periodEnd,
            'grace_until' => null,
        ]);

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

        return [$sub, $periodEnd, $method, $attempt];
    }

    private function technicalFailureUpdate(PaymentAttempt $attempt): ProviderStatusUpdate
    {
        return new ProviderStatusUpdate(
            provider: 'tbank',
            providerPaymentId: $attempt->provider_payment_id,
            internalOrderId: $attempt->internal_order_id,
            normalizedStatus: PaymentAttemptStatus::FailedTerminal,
            raw: [
                'Status' => 'REJECTED',
                'ErrorCode' => '9999',
                'PaymentId' => $attempt->provider_payment_id,
            ],
            failureCode: '9999',
            failureCategory: 'reconciliation_timeout',
            failureMessage: 'Не удалось подтвердить статус платежа',
        );
    }
}
