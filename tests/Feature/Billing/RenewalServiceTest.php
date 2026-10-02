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
use App\Services\Billing\PlanPriceResolver;
use App\Services\Billing\RenewalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RenewalServiceTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;
    private Workspace $workspace;
    private PlanPrice $planPrice;
    private RenewalService $renewalService;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->planPrice = PlanPrice::create([
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'base_amount' => 490,
            'discount_percent' => 10,
            'final_amount' => 441,
            'currency' => 'RUB',
            'version' => 1,
            'valid_from' => now()->subDay(),
            'is_active' => true,
        ]);

        $this->renewalService = app(RenewalService::class);
    }

    // ── Happy path ──

    public function test_due_subscription_creates_renewal_cycle_and_attempt(): void
    {
        $sub = $this->createDueSubscription();
        $method = $this->createDefaultPaymentMethod();

        $attempt = $this->renewalService->prepare($sub);

        $this->assertNotNull($attempt);

        $cycle = BillingCycle::where('billing_subscription_id', $sub->id)
            ->where('origin', BillingCycleOrigin::Renewal)
            ->firstOrFail();

        $this->assertSame(BillingCycleStatus::Pending, $cycle->status);
        $this->assertSame($attempt->billing_cycle_id, $cycle->id);

        $this->assertSame(1, BillingCycle::where('billing_subscription_id', $sub->id)->count());
        $this->assertSame(1, PaymentAttempt::count());
    }

    public function test_period_bounds_come_from_subscription_horizon_not_now(): void
    {
        $sub = $this->createDueSubscription([
            'current_period_end' => now()->subHours(3),
        ]);
        $this->createDefaultPaymentMethod();

        $this->renewalService->prepare($sub);

        $sub->refresh();
        $cycle = BillingCycle::where('billing_subscription_id', $sub->id)
            ->where('origin', BillingCycleOrigin::Renewal)
            ->firstOrFail();

        $this->assertTrue($cycle->period_start->equalTo($sub->current_period_end));
        $this->assertTrue(
            $cycle->period_end->equalTo(
                $sub->current_period_end->copy()->addMonths($sub->renewal_period_months)
            )
        );
        $this->assertFalse($cycle->period_start->equalTo(now()));
    }

    public function test_actual_plan_price_and_snapshot_are_used(): void
    {
        $sub = $this->createDueSubscription();
        $this->createDefaultPaymentMethod();

        $attempt = $this->renewalService->prepare($sub);

        $cycle = BillingCycle::where('billing_subscription_id', $sub->id)
            ->where('origin', BillingCycleOrigin::Renewal)
            ->firstOrFail();

        $this->assertSame($this->planPrice->id, $cycle->plan_price_id);
        $this->assertSame($this->planPrice->final_amount, $cycle->amount);
        $this->assertSame($this->planPrice->currency, $cycle->currency);
        $this->assertSame(
            app(PlanPriceResolver::class)->snapshot($this->planPrice),
            $cycle->price_snapshot
        );
        $this->assertSame($cycle->amount, $attempt->amount);
        $this->assertSame($cycle->currency, $attempt->currency);
    }

    public function test_attempt_fields_link_to_default_tbank_method(): void
    {
        $sub = $this->createDueSubscription();
        $method = $this->createDefaultPaymentMethod();

        $attempt = $this->renewalService->prepare($sub);

        $this->assertSame($method->id, $attempt->payment_method_id);
        $this->assertSame('tbank', $attempt->provider);
        $this->assertNull($attempt->provider_payment_id);
        $this->assertSame(PaymentAttemptStatus::Created, $attempt->status);
        $this->assertSame(1, $attempt->attempt_number);
        $this->assertStringStartsWith('renew_', $attempt->internal_order_id);
        $this->assertTrue($attempt->metadata['renewal'] ?? false);
        $this->assertSame($sub->id, $attempt->metadata['billing_subscription_id']);
        $this->assertArrayNotHasKey('legacy_subscription_id', $attempt->metadata);
    }

    public function test_prepare_does_not_mutate_subscription(): void
    {
        $sub = $this->createDueSubscription([
            'grace_until' => now()->addDays(3),
        ]);
        $this->createDefaultPaymentMethod();

        $before = [
            'status' => $sub->status,
            'next_charge_at' => $sub->next_charge_at,
            'current_period_end' => $sub->current_period_end,
            'grace_until' => $sub->grace_until,
            'auto_renew_consent_at' => $sub->auto_renew_consent_at,
            'cancel_at_period_end' => $sub->cancel_at_period_end,
            'renewal_period_months' => $sub->renewal_period_months,
        ];

        $this->renewalService->prepare($sub);

        $sub->refresh();
        $this->assertSame($before['status'], $sub->status);
        $this->assertTrue($sub->next_charge_at->equalTo($before['next_charge_at']));
        $this->assertTrue($sub->current_period_end->equalTo($before['current_period_end']));
        $this->assertTrue($sub->grace_until->equalTo($before['grace_until']));
        $this->assertTrue($sub->auto_renew_consent_at->equalTo($before['auto_renew_consent_at']));
        $this->assertFalse($sub->cancel_at_period_end);
        $this->assertSame($before['renewal_period_months'], $sub->renewal_period_months);
    }

    public function test_repeated_prepare_returns_same_in_flight_attempt_without_duplicates(): void
    {
        $sub = $this->createDueSubscription();
        $this->createDefaultPaymentMethod();

        $first = $this->renewalService->prepare($sub);
        $second = $this->renewalService->prepare($sub);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, BillingCycle::where('billing_subscription_id', $sub->id)->count());
        $this->assertSame(1, PaymentAttempt::count());
    }

    // ── Gating ──

    public function test_cancel_at_period_end_blocks_prepare(): void
    {
        $sub = $this->createDueSubscription(['cancel_at_period_end' => true]);
        $this->createDefaultPaymentMethod();

        $this->assertNull($this->renewalService->prepare($sub));
        $this->assertSame(0, BillingCycle::where('billing_subscription_id', $sub->id)->count());
        $this->assertSame(0, PaymentAttempt::count());
    }

    public function test_future_next_charge_at_blocks_prepare(): void
    {
        $sub = $this->createDueSubscription(['next_charge_at' => now()->addHour()]);
        $this->createDefaultPaymentMethod();

        $this->assertNull($this->renewalService->prepare($sub));
        $this->assertSame(0, BillingCycle::where('billing_subscription_id', $sub->id)->count());
        $this->assertSame(0, PaymentAttempt::count());
    }

    public function test_missing_consent_blocks_prepare(): void
    {
        $sub = $this->createDueSubscription(['auto_renew_consent_at' => null]);
        $this->createDefaultPaymentMethod();

        $this->assertNull($this->renewalService->prepare($sub));
        $this->assertSame(0, BillingCycle::where('billing_subscription_id', $sub->id)->count());
        $this->assertSame(0, PaymentAttempt::count());
    }

    public function test_missing_payment_method_blocks_prepare(): void
    {
        $sub = $this->createDueSubscription();

        $this->assertNull($this->renewalService->prepare($sub));
        $this->assertSame(0, BillingCycle::where('billing_subscription_id', $sub->id)->count());
        $this->assertSame(0, PaymentAttempt::count());
    }

    public function test_missing_active_plan_price_fails_closed(): void
    {
        // No PlanPrice exists for period 3 — resolver returns null.
        $sub = $this->createDueSubscription(['renewal_period_months' => 3]);
        $this->createDefaultPaymentMethod();

        $this->assertNull($this->renewalService->prepare($sub));
        $this->assertSame(0, BillingCycle::where('billing_subscription_id', $sub->id)->count());
        $this->assertSame(0, PaymentAttempt::count());
    }

    public function test_paid_renewal_cycle_creates_no_new_attempt(): void
    {
        $sub = $this->createDueSubscription();
        $this->createDefaultPaymentMethod();

        $start = $sub->current_period_end->copy();
        BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'plan_price_id' => $this->planPrice->id,
            'period_start' => $start,
            'period_end' => $start->copy()->addMonths($sub->renewal_period_months),
            'status' => BillingCycleStatus::Paid,
            'amount' => $this->planPrice->final_amount,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Renewal,
        ]);

        $this->assertNull($this->renewalService->prepare($sub));
        $this->assertSame(0, PaymentAttempt::count());
        $this->assertSame(1, BillingCycle::where('billing_subscription_id', $sub->id)->count());
    }

    // ── Helpers ──

    private function createDueSubscription(array $overrides = []): BillingSubscription
    {
        $periodEnd = now()->subHour();

        return BillingSubscription::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::Active,
            'renewal_period_months' => 1,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => $periodEnd,
            'next_charge_at' => $periodEnd,
            'cancel_at_period_end' => false,
            'auto_renew_consent_at' => now()->subDays(2),
            'auto_renew_consent_version' => config('billing.recurring_terms_version'),
        ], $overrides));
    }

    private function createDefaultPaymentMethod(array $overrides = []): PaymentMethod
    {
        return PaymentMethod::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'provider' => 'tbank',
            'type' => 'card',
            'provider_reference' => 'rebill_'.bin2hex(random_bytes(8)),
            'status' => 'active',
            'is_default' => true,
        ], $overrides));
    }
}
