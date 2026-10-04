<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\PaymentAttempt;
use App\Models\PlanPrice;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Payment\PaymentReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;
    private Workspace $workspace;
    private User $master;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.legacy_mock_webhook_secret' => 'test_secret_123']);
        config(['billing.reconciliation' => [
            'batch_size' => 50,
            'fresh_grace_seconds' => 0, // No grace for tests
            'backoff' => [60, 120, 300],
            'max_age_with_provider_id' => 86400,
            'max_age_without_provider_id' => 1800,
        ]]);

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
    }

    public function test_max_age_with_provider_id_failed_terminal(): void
    {
        config(['billing.reconciliation.max_age_with_provider_id' => 0]); // Immediate

        $attempt = $this->createAttempt(PaymentAttemptStatus::Unknown, 490);

        $reconciliationService = app(PaymentReconciliationService::class);
        $result = $reconciliationService->reconcile();

        $this->assertGreaterThan(0, $result['processed']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('reconciliation_timeout', $attempt->failure_category);
    }

    public function test_max_age_without_provider_id_failed_terminal(): void
    {
        config(['billing.reconciliation.max_age_without_provider_id' => 0]); // Immediate

        $attempt = $this->createAttemptWithoutProviderPaymentId(PaymentAttemptStatus::Unknown, 490);

        $reconciliationService = app(PaymentReconciliationService::class);
        $result = $reconciliationService->reconcile();

        $this->assertGreaterThan(0, $result['processed']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('reconciliation_timeout', $attempt->failure_category);
    }

    public function test_fresh_attempt_not_polled(): void
    {
        config(['billing.reconciliation.fresh_grace_seconds' => 3600]); // 1 hour

        $attempt = $this->createAttempt(PaymentAttemptStatus::Unknown, 490);

        $reconciliationService = app(PaymentReconciliationService::class);
        $result = $reconciliationService->reconcile();

        $this->assertSame(0, $result['processed']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Unknown, $attempt->status);
    }

    public function test_failed_terminal_no_longer_blocks_new_checkout(): void
    {
        $attempt = $this->createAttempt(PaymentAttemptStatus::FailedTerminal, 490);

        // Check that in-flight lookup doesn't find failed_terminal
        $inFlight = app(\App\Services\Billing\BillingCoreWriter::class)
            ->findExistingInFlightAttempt($this->workspace->id, $this->proPlan->id);

        $this->assertNull($inFlight);
    }

    public function test_created_attempt_age_released(): void
    {
        config(['billing.reconciliation.max_age_with_provider_id' => 0]); // Immediate

        $attempt = $this->createAttempt(PaymentAttemptStatus::Created, 490);

        $reconciliationService = app(PaymentReconciliationService::class);
        $result = $reconciliationService->reconcile();

        $this->assertGreaterThan(0, $result['processed']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('reconciliation_timeout', $attempt->failure_category);
    }

    public function test_processing_attempt_age_released(): void
    {
        config(['billing.reconciliation.max_age_with_provider_id' => 0]); // Immediate

        $attempt = $this->createAttempt(PaymentAttemptStatus::Processing, 490);

        $reconciliationService = app(PaymentReconciliationService::class);
        $result = $reconciliationService->reconcile();

        $this->assertGreaterThan(0, $result['processed']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('reconciliation_timeout', $attempt->failure_category);
    }

    // ── Reconciliation success schedules next_charge_at ──

    public function test_reconciliation_success_schedules_next_charge(): void
    {
        $sub = BillingSubscription::create([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::Active,
            'renewal_period_months' => 1,
            'auto_renew_consent_at' => now(),
            'auto_renew_consent_version' => config('billing.recurring_terms_version'),
            'cancel_at_period_end' => false,
        ]);

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now(),
            'period_end' => now()->addMonth(),
            'status' => BillingCycleStatus::Pending,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => 'payment',
        ]);

        $attempt = new PaymentAttempt([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'tbank',
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'core_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => 'tbank_recon_'.bin2hex(random_bytes(4)),
            'status' => PaymentAttemptStatus::Processing,
            'initiated_at' => now()->subHour(),
        ]);
        $attempt->created_at = now()->subHour();
        $attempt->save();

        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => true,
                'PaymentId' => $attempt->provider_payment_id,
                'OrderId' => $attempt->internal_order_id,
                'Status' => 'CONFIRMED',
                'Amount' => 49000,
            ], 200),
        ]);

        $result = app(PaymentReconciliationService::class)->reconcile();

        $this->assertGreaterThan(0, $result['processed']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);

        $sub->refresh();
        $this->assertNotNull($sub->current_period_end);
        $this->assertNotNull($sub->next_charge_at);
        $this->assertTrue($sub->next_charge_at->equalTo($sub->current_period_end));
    }

    // ── last_polled_at backoff parsing ──

    public function test_iso8601_last_polled_inside_backoff_skips_provider(): void
    {
        $attempt = $this->createAttempt(PaymentAttemptStatus::Processing, 490, 'tbank');
        // poll_count=1 → backoff[0] = 60s; polled 30s ago → still inside backoff
        $attempt->update(['metadata' => [
            'poll_count' => 1,
            'last_polled_at' => now()->subSeconds(30)->toIso8601String(),
        ]]);

        Http::fake();

        $result = app(PaymentReconciliationService::class)->reconcile();

        $this->assertSame(0, $result['errors']);
        Http::assertNothingSent();

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        $this->assertSame(1, $attempt->metadata['poll_count']);
    }

    public function test_iso8601_last_polled_after_backoff_polls_provider(): void
    {
        $attempt = $this->createAttempt(PaymentAttemptStatus::Processing, 490, 'tbank');
        // poll_count=1 → backoff[0] = 60s; polled 120s ago → due
        $attempt->update(['metadata' => [
            'poll_count' => 1,
            'last_polled_at' => now()->subSeconds(120)->toIso8601String(),
        ]]);

        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response(['Success' => false], 200),
        ]);

        $result = app(PaymentReconciliationService::class)->reconcile();

        $this->assertSame(0, $result['errors']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/GetState'));

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        $this->assertSame(2, $attempt->metadata['poll_count']);
    }

    public function test_malformed_last_polled_at_does_not_crash_command(): void
    {
        $attempt = $this->createAttempt(PaymentAttemptStatus::Processing, 490, 'tbank');
        $attempt->update(['metadata' => [
            'poll_count' => 3,
            'last_polled_at' => 'not-a-timestamp',
        ]]);

        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response(['Success' => false], 200),
        ]);

        // Backoff unverifiable → current poll allowed, no crash, no errors.
        $this->artisan('billing:reconcile-payment-attempts')
            ->assertExitCode(0);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/GetState'));

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        $this->assertSame(4, $attempt->metadata['poll_count']);
        // The poll itself rewrote last_polled_at with a fresh valid timestamp.
        $this->assertNotSame('not-a-timestamp', $attempt->metadata['last_polled_at']);
    }

    public function test_null_last_polled_at_keeps_previous_behavior(): void
    {
        $attempt = $this->createAttempt(PaymentAttemptStatus::Processing, 490, 'tbank');
        $attempt->update(['metadata' => ['poll_count' => 3]]); // no last_polled_at

        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response(['Success' => false], 200),
        ]);

        $result = app(PaymentReconciliationService::class)->reconcile();

        $this->assertSame(0, $result['errors']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/GetState'));

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        $this->assertSame(4, $attempt->metadata['poll_count']);
        $this->assertNotEmpty($attempt->metadata['last_polled_at']);
    }

    // ── Helpers ──

    private function createAttempt(
        PaymentAttemptStatus $status,
        int $amount,
        string $provider = 'mock',
    ): PaymentAttempt {
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
            'amount' => $amount,
            'currency' => 'RUB',
            'origin' => 'payment',
        ]);

        $attempt = new PaymentAttempt([
            'billing_cycle_id' => $cycle->id,
            'provider' => $provider,
            'attempt_number' => 1,
            'amount' => $amount,
            'currency' => 'RUB',
            'internal_order_id' => 'core_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => 'mock_'.bin2hex(random_bytes(8)),
            'status' => $status,
            'initiated_at' => now()->subHour(),
        ]);
        $attempt->created_at = now()->subHour();
        $attempt->save();

        return $attempt;
    }

    private function createAttemptWithoutProviderPaymentId(
        PaymentAttemptStatus $status,
        int $amount,
        string $provider = 'mock',
    ): PaymentAttempt {
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
            'amount' => $amount,
            'currency' => 'RUB',
            'origin' => 'payment',
        ]);

        $attempt = new PaymentAttempt([
            'billing_cycle_id' => $cycle->id,
            'provider' => $provider,
            'attempt_number' => 1,
            'amount' => $amount,
            'currency' => 'RUB',
            'internal_order_id' => 'core_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => null,
            'status' => $status,
            'initiated_at' => now()->subHour(),
        ]);
        $attempt->created_at = now()->subHour();
        $attempt->save();

        return $attempt;
    }
}
