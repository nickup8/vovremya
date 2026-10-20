<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentEnvironment;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingCoreWriter;
use App\Services\Billing\BillingService;
use App\Services\Billing\RenewalService;
use App\Services\Payment\PaymentEnvironmentResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentEnvSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;

    private User $master;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.legacy_mock_webhook_secret' => 'test_secret_123',
            'billing.default_gateway' => 'mock',
            'billing.gateways.tbank.environment' => 'demo',
        ]);

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

        $this->master = User::factory()->master()->create();
        $this->workspace = Workspace::create([
            'name' => 'ws-'.$this->master->id,
            'owner_id' => $this->master->id,
        ]);
        $this->workspace->ensureSlug();
        $this->master->update(['workspace_id' => $this->workspace->id]);
    }

    // ── Resolver ──

    public function test_resolver_tbank_defaults_to_demo(): void
    {
        config(['billing.gateways.tbank.environment' => null]);

        $this->assertSame(
            PaymentEnvironment::Demo,
            app(PaymentEnvironmentResolver::class)->resolve('tbank'),
        );
    }

    public function test_resolver_tbank_reads_live_config(): void
    {
        config(['billing.gateways.tbank.environment' => 'live']);

        $this->assertSame(
            PaymentEnvironment::Live,
            app(PaymentEnvironmentResolver::class)->resolve('tbank'),
        );
    }

    public function test_resolver_invalid_tbank_value_never_becomes_live(): void
    {
        foreach (['LIVE', 'Live', 'prod', 'production', '', '1', 'true'] as $value) {
            config(['billing.gateways.tbank.environment' => $value]);

            $this->assertSame(
                PaymentEnvironment::Demo,
                app(PaymentEnvironmentResolver::class)->resolve('tbank'),
                "value='{$value}'",
            );
        }
    }

    public function test_resolver_mock_is_always_demo_even_with_live_tbank_config(): void
    {
        config(['billing.gateways.tbank.environment' => 'live']);

        $this->assertSame(
            PaymentEnvironment::Demo,
            app(PaymentEnvironmentResolver::class)->resolve('mock'),
        );
    }

    // ── Checkout snapshot ──

    public function test_mock_checkout_attempt_snapshots_demo(): void
    {
        app(BillingService::class)->subscribe($this->master, $this->proPlan, 1, false, 'card');

        $attempt = PaymentAttempt::orderByDesc('created_at')->first();

        $this->assertNotNull($attempt);
        $this->assertSame('mock', $attempt->provider);
        $this->assertSame(PaymentEnvironment::Demo, $attempt->payment_env);
    }

    public function test_checkout_created_snapshots_tbank_live(): void
    {
        config(['billing.gateways.tbank.environment' => 'live']);

        $legacy = Subscription::create([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => 'pending',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
        ]);

        $price = [
            'plan_price_id' => null,
            'base' => 490,
            'discount_percent' => 0,
            'final' => 490,
            'currency' => 'RUB',
            'version' => 1,
            'period_months' => 1,
        ];

        $result = app(BillingCoreWriter::class)->checkoutCreated(
            $legacy,
            $this->proPlan,
            $price,
            1,
            'tbank',
        );

        $this->assertSame(PaymentEnvironment::Live, $result['attempt']->payment_env);
    }

    public function test_snapshot_survives_later_config_change(): void
    {
        config(['billing.gateways.tbank.environment' => 'demo']);

        $legacy = Subscription::create([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => 'pending',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
        ]);

        $result = app(BillingCoreWriter::class)->checkoutCreated(
            $legacy,
            $this->proPlan,
            ['base' => 490, 'discount_percent' => 0, 'final' => 490, 'currency' => 'RUB', 'period_months' => 1],
            1,
            'tbank',
        );

        $this->assertSame(PaymentEnvironment::Demo, $result['attempt']->payment_env);

        // Later config flip must NOT rewrite the existing attempt.
        config(['billing.gateways.tbank.environment' => 'live']);

        $this->assertSame(PaymentEnvironment::Demo, $result['attempt']->refresh()->payment_env);
    }

    // ── NULL stays NULL (no backfill) ──

    public function test_attempt_without_explicit_env_stays_null(): void
    {
        $attempt = $this->createTbankAttempt(paymentEnv: null);

        $this->assertNull($attempt->fresh()->payment_env);
    }

    // ── Renewal + technical retry snapshots ──

    public function test_renewal_attempt_snapshots_tbank_env(): void
    {
        config(['billing.gateways.tbank.environment' => 'live']);

        $attempt = app(RenewalService::class)->prepare($this->dueSubscription());

        $this->assertNotNull($attempt);
        $this->assertSame('tbank', $attempt->provider);
        $this->assertSame(PaymentEnvironment::Live, $attempt->payment_env);
        $this->assertTrue($attempt->metadata['renewal'] ?? false);
    }

    public function test_technical_retry_attempt_snapshots_tbank_env(): void
    {
        config(['billing.gateways.tbank.environment' => 'live']);

        $sub = $this->dueSubscription();
        $attempt = $this->failedRenewalAttemptFor($sub);

        $sub->refresh()->update([
            'status' => BillingSubscriptionStatus::PastDue,
            'next_charge_at' => null,
            'grace_until' => now()->addDays(2),
        ]);

        $retry = app(RenewalService::class)->prepareTechnicalRetry($sub->refresh());

        $this->assertNotNull($retry);
        $this->assertNotSame($attempt->id, $retry->id);
        $this->assertSame(PaymentEnvironment::Live, $retry->payment_env);
        $this->assertTrue($retry->metadata['technical_retry'] ?? false);
    }

    // ── Helpers ──

    private function dueSubscription(): BillingSubscription
    {
        $sub = BillingSubscription::create([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'plan_price_id' => PlanPrice::where('tariff_plan_id', $this->proPlan->id)->first()->id,
            'status' => BillingSubscriptionStatus::Active,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->subDay(),
            'next_charge_at' => now()->subDay(),
            'renewal_period_months' => 1,
            'auto_renew_consent_at' => now()->subMonth(),
            'auto_renew_consent_version' => '2026-10-02',
            'cancel_at_period_end' => false,
        ]);

        PaymentMethod::create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->master->id,
            'provider' => 'tbank',
            'type' => 'card',
            'status' => 'active',
            'is_default' => true,
            'provider_reference' => 'pm_test_1',
        ]);

        return $sub;
    }

    private function failedRenewalAttemptFor(BillingSubscription $sub): PaymentAttempt
    {
        $cycle = BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => $sub->current_period_end,
            'period_end' => $sub->current_period_end->copy()->addMonth(),
            'status' => BillingCycleStatus::Failed,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Renewal,
        ]);

        return PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'tbank',
            'payment_env' => PaymentEnvironment::Live,
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'renew_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => 'tbank_1',
            'status' => PaymentAttemptStatus::FailedTerminal,
            'failure_category' => 'reconciliation_timeout',
            'initiated_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(30),
            'metadata' => ['renewal' => true, 'billing_subscription_id' => $sub->id],
        ]);
    }

    private function createTbankAttempt(?PaymentEnvironment $paymentEnv): PaymentAttempt
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
            'period_start' => now(),
            'period_end' => now()->addMonth(),
            'status' => BillingCycleStatus::Pending,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Payment,
        ]);

        return PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'tbank',
            'payment_env' => $paymentEnv,
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'core_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => 'tbank_'.bin2hex(random_bytes(8)),
            'status' => PaymentAttemptStatus::Processing,
            'initiated_at' => now(),
        ]);
    }
}
