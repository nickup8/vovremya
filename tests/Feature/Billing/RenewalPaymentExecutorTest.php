<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Models\BillingSubscription;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Models\PlanPrice;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\RenewalPaymentExecutor;
use App\Services\Billing\RenewalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class RenewalPaymentExecutorTest extends TestCase
{
    use RefreshDatabase;

    private const TERMINAL_KEY = 'TestTerminal';

    private const PASSWORD = 'test-password';

    private const PAYMENT_ID = '735986544';

    private TariffPlan $proPlan;
    private Workspace $workspace;
    private RenewalPaymentExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.gateways.tbank' => [
                'driver' => 'tbank',
                'terminal_key' => self::TERMINAL_KEY,
                'password' => self::PASSWORD,
                'base_url' => 'https://securepay.tinkoff.ru',
            ],
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

        $this->executor = app(RenewalPaymentExecutor::class);
    }

    // ── Happy path ──

    public function test_happy_path_executes_init_charge_and_transition(): void
    {
        $attempt = $this->preparedRenewalAttempt();
        $cycle = $attempt->billingCycle;
        $sub = BillingSubscription::findOrFail($cycle->billing_subscription_id);
        $originalPeriodEnd = $sub->current_period_end->copy();

        $this->fakeRecurringHttp('CONFIRMED', $attempt->internal_order_id);

        $result = $this->executor->execute($attempt);

        $this->assertTrue($result['success']);
        Http::assertSentCount(2);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
        $this->assertSame(self::PAYMENT_ID, $attempt->provider_payment_id);

        $cycle->refresh();
        $this->assertSame(BillingCycleStatus::Paid, $cycle->status);

        // Horizon moved by the EXISTING transition path.
        $sub->refresh();
        $this->assertSame(BillingSubscriptionStatus::Active, $sub->status);
        $this->assertTrue($sub->current_period_end->equalTo($cycle->period_end));
        $this->assertTrue($sub->current_period_end->greaterThan($originalPeriodEnd));
        $this->assertTrue($sub->next_charge_at->equalTo($sub->current_period_end));
    }

    public function test_payment_id_is_saved_before_charge_is_called(): void
    {
        $attempt = $this->preparedRenewalAttempt();

        $stateAtCharge = null;

        Http::fake(function ($request) use (&$stateAtCharge, $attempt) {
            if (str_ends_with($request->url(), '/v2/Charge')) {
                $fresh = PaymentAttempt::find($attempt->id);
                $stateAtCharge = [$fresh->status, $fresh->provider_payment_id];

                return Http::response($this->chargeResponseBody('CONFIRMED', $attempt->internal_order_id), 200);
            }

            return Http::response([
                'Success' => true,
                'PaymentId' => self::PAYMENT_ID,
            ], 200);
        });

        $this->executor->execute($attempt);

        $this->assertNotNull($stateAtCharge, 'Charge request was never sent');
        $this->assertSame(PaymentAttemptStatus::Processing, $stateAtCharge[0]);
        $this->assertSame(self::PAYMENT_ID, $stateAtCharge[1]);
    }

    public function test_charge_authorized_keeps_processing_and_grants_no_entitlement(): void
    {
        $attempt = $this->preparedRenewalAttempt();
        $cycle = $attempt->billingCycle;
        $sub = BillingSubscription::findOrFail($cycle->billing_subscription_id);
        $periodEndBefore = $sub->current_period_end->copy();

        $this->fakeRecurringHttp('AUTHORIZED', $attempt->internal_order_id);

        $result = $this->executor->execute($attempt);

        $this->assertTrue($result['success']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        $this->assertSame(self::PAYMENT_ID, $attempt->provider_payment_id);

        $cycle->refresh();
        $this->assertSame(BillingCycleStatus::Pending, $cycle->status);

        $sub->refresh();
        $this->assertTrue($sub->current_period_end->equalTo($periodEndBefore));
    }

    // ── Exceptions ──

    public function test_init_exception_leaves_attempt_created_and_skips_charge(): void
    {
        $attempt = $this->preparedRenewalAttempt();

        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/v2/Init')) {
                return Http::response([
                    'Success' => false,
                    'ErrorCode' => '9999',
                ], 200);
            }

            return Http::response(['Success' => false], 200);
        });

        $caught = false;
        try {
            $this->executor->execute($attempt);
        } catch (RuntimeException $e) {
            $caught = true;
        }

        $this->assertTrue($caught, 'execute() should have thrown RuntimeException');

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Created, $attempt->status);
        $this->assertNull($attempt->provider_payment_id);

        Http::assertSentCount(1); // Init only — Charge must not be called
    }

    public function test_charge_exception_leaves_attempt_processing_with_payment_id(): void
    {
        $attempt = $this->preparedRenewalAttempt();
        $cycle = $attempt->billingCycle;

        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/v2/Init')) {
                return Http::response([
                    'Success' => true,
                    'PaymentId' => self::PAYMENT_ID,
                ], 200);
            }

            return Http::response([
                'Success' => false,
                'ErrorCode' => '5106',
                'ErrorMessage' => 'card blocked',
            ], 200);
        });

        $caught = false;
        try {
            $this->executor->execute($attempt);
        } catch (RuntimeException $e) {
            $caught = true;
        }

        $this->assertTrue($caught, 'execute() should have thrown RuntimeException');

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        $this->assertSame(self::PAYMENT_ID, $attempt->provider_payment_id);

        $cycle->refresh();
        $this->assertSame(BillingCycleStatus::Pending, $cycle->status);
    }

    // ── Idempotency / no-op ──

    public function test_reexecute_for_processing_with_payment_id_is_noop_without_http(): void
    {
        $attempt = $this->preparedRenewalAttempt();
        $attempt->update([
            'status' => PaymentAttemptStatus::Processing,
            'provider_payment_id' => self::PAYMENT_ID,
        ]);

        Http::fake();

        $result = $this->executor->execute($attempt);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['noop'] ?? false);

        Http::assertNothingSent();
    }

    public function test_succeeded_attempt_is_noop_without_http(): void
    {
        $attempt = $this->preparedRenewalAttempt();
        $attempt->update([
            'status' => PaymentAttemptStatus::Succeeded,
            'provider_payment_id' => self::PAYMENT_ID,
        ]);

        Http::fake();

        $result = $this->executor->execute($attempt);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['noop'] ?? false);

        Http::assertNothingSent();
    }

    // ── Fail closed guards ──

    public function test_missing_payment_method_fails_closed_without_http(): void
    {
        $attempt = $this->preparedRenewalAttempt();
        PaymentMethod::where('id', $attempt->payment_method_id)->delete();

        Http::fake();

        $caught = false;
        try {
            $this->executor->execute($attempt);
        } catch (RuntimeException $e) {
            $caught = true;
        }

        $this->assertTrue($caught, 'execute() should have thrown RuntimeException');
        Http::assertNothingSent();
    }

    public function test_inactive_payment_method_fails_closed_without_http(): void
    {
        $attempt = $this->preparedRenewalAttempt();
        $attempt->paymentMethod->update(['status' => 'revoked']);

        Http::fake();

        $caught = false;
        try {
            $this->executor->execute($attempt);
        } catch (RuntimeException $e) {
            $caught = true;
        }

        $this->assertTrue($caught, 'execute() should have thrown RuntimeException');
        Http::assertNothingSent();
    }

    public function test_non_renewal_cycle_fails_closed_without_http(): void
    {
        $attempt = $this->preparedRenewalAttempt();
        $attempt->billingCycle->update(['origin' => BillingCycleOrigin::Payment]);

        Http::fake();

        $caught = false;
        try {
            $this->executor->execute($attempt);
        } catch (RuntimeException $e) {
            $caught = true;
        }

        $this->assertTrue($caught, 'execute() should have thrown RuntimeException');
        Http::assertNothingSent();
    }

    public function test_missing_renewal_metadata_fails_closed_without_http(): void
    {
        $attempt = $this->preparedRenewalAttempt();
        $attempt->update([
            'metadata' => ['billing_subscription_id' => $attempt->billingCycle->billing_subscription_id],
        ]);

        Http::fake();

        $caught = false;
        try {
            $this->executor->execute($attempt);
        } catch (RuntimeException $e) {
            $caught = true;
        }

        $this->assertTrue($caught, 'execute() should have thrown RuntimeException');
        Http::assertNothingSent();
    }

    // ── Helpers ──

    private function preparedRenewalAttempt(): PaymentAttempt
    {
        $periodEnd = now()->subHour();

        $sub = BillingSubscription::create([
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
        ]);

        PaymentMethod::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'tbank',
            'type' => 'card',
            'provider_reference' => 'rebill_'.bin2hex(random_bytes(8)),
            'status' => 'active',
            'is_default' => true,
        ]);

        $attempt = app(RenewalService::class)->prepare($sub);
        $this->assertNotNull($attempt, 'RenewalService::prepare should have created an attempt');

        return $attempt;
    }

    private function fakeRecurringHttp(string $chargeStatus, string $orderId): void
    {
        Http::fake(function ($request) use ($chargeStatus, $orderId) {
            if (str_ends_with($request->url(), '/v2/Charge')) {
                return Http::response($this->chargeResponseBody($chargeStatus, $orderId), 200);
            }

            return Http::response([
                'Success' => true,
                'PaymentId' => self::PAYMENT_ID,
            ], 200);
        });
    }

    private function chargeResponseBody(string $status, string $orderId): array
    {
        return [
            'Success' => true,
            'PaymentId' => self::PAYMENT_ID,
            'OrderId' => $orderId,
            'Status' => $status,
            'Amount' => 49000,
        ];
    }
}
