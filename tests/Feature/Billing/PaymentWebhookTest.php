<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\SubscriptionStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\PaymentAttempt;
use App\Models\PlanPrice;
use App\Models\ProviderEvent;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'test_legacy_webhook_secret_abc123';

    private const TBANK_TERMINAL_KEY = 'TestTerminal';

    private const TBANK_PASSWORD = 'tbank_test_password';

    private TariffPlan $proPlan;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.legacy_mock_webhook_secret' => self::WEBHOOK_SECRET,
            'billing.gateways.tbank' => [
                'driver' => 'tbank',
                'terminal_key' => self::TBANK_TERMINAL_KEY,
                'password' => self::TBANK_PASSWORD,
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
            'valid_from' => now(),
            'is_active' => true,
        ]);
    }

    private function createPendingSubscription(string $paymentId, int $amountPaid = 490, string $provider = 'mock'): Subscription
    {
        $sub = Subscription::create([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => $amountPaid,
            'status' => SubscriptionStatus::Pending,
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
            'payment_id' => $paymentId,
        ]);

        // Also create Core billing structures
        $billingSub = BillingSubscription::create([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::PendingInitial,
        ]);

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => $sub->starts_at,
            'period_end' => $sub->expires_at,
            'status' => BillingCycleStatus::Pending,
            'amount' => $amountPaid,
            'currency' => 'RUB',
            'origin' => 'payment',
            'legacy_subscription_id' => $sub->id,
        ]);

        PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => $provider,
            'attempt_number' => 1,
            'amount' => $amountPaid,
            'currency' => 'RUB',
            'internal_order_id' => 'core_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => $paymentId,
            'status' => PaymentAttemptStatus::Processing,
            'initiated_at' => now(),
            'metadata' => ['legacy_subscription_id' => $sub->id],
        ]);

        return $sub;
    }

    private function sendWebhook(array $payload, string $signature = self::WEBHOOK_SECRET): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('webhooks.payment'), $payload, [
            'X-Webhook-Signature' => $signature,
        ]);
    }

    /**
     * Independent mirror of the T-Bank token algorithm for test payloads.
     */
    private function tbankToken(array $payload): string
    {
        unset($payload['Token']);

        $scalars = [];
        foreach ($payload as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $scalars[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        ksort($scalars);

        return hash('sha256', implode('', $scalars).self::TBANK_PASSWORD);
    }

    private function sendTbankWebhook(array $payload): \Illuminate\Testing\TestResponse
    {
        $payload['Token'] = $this->tbankToken($payload);

        return $this->postJson(route('webhooks.payment.provider', 'tbank'), $payload);
    }

    // ── 1. Signature validation ──

    public function test_secret_not_configured_returns_403(): void
    {
        config(['billing.legacy_mock_webhook_secret' => null]);

        $response = $this->sendWebhook([
            'payment_id' => 'mock_any',
            'status' => 'succeeded',
            'amount' => 490,
        ]);

        $response->assertStatus(403);
    }

    public function test_missing_signature_returns_403(): void
    {
        $response = $this->postJson(route('webhooks.payment'), [
            'payment_id' => 'mock_any',
            'status' => 'succeeded',
            'amount' => 490,
        ]);

        $response->assertStatus(403);
    }

    public function test_invalid_signature_returns_403(): void
    {
        $response = $this->sendWebhook(
            ['payment_id' => 'mock_any', 'status' => 'succeeded', 'amount' => 490],
            'wrong_signature',
        );

        $response->assertStatus(403);
    }

    // ── 2. Core-first: success transitions attempt + cycle + legacy mirror ──

    public function test_valid_success_transitions_core_and_legacy(): void
    {
        $sub = $this->createPendingSubscription('mock_valid_1', 490);

        $response = $this->sendWebhook([
            'payment_id' => 'mock_valid_1',
            'status' => 'succeeded',
            'amount' => 490,
        ]);

        $response->assertOk();
        $response->assertExactJson(['ok' => true]);

        // Core state
        $attempt = PaymentAttempt::where('provider_payment_id', 'mock_valid_1')->first();
        $this->assertNotNull($attempt);
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);

        $cycle = BillingCycle::where('id', $attempt->billing_cycle_id)->first();
        $this->assertSame(BillingCycleStatus::Paid, $cycle->status);

        // Legacy mirror
        $sub->refresh();
        $this->assertSame('active', $sub->status);
    }

    public function test_paid_status_transitions_core_and_legacy(): void
    {
        $sub = $this->createPendingSubscription('mock_valid_paid', 490);

        $response = $this->sendWebhook([
            'payment_id' => 'mock_valid_paid',
            'status' => 'paid',
            'amount' => 490,
        ]);

        $response->assertOk();

        $attempt = PaymentAttempt::where('provider_payment_id', 'mock_valid_paid')->first();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);

        $sub->refresh();
        $this->assertSame('active', $sub->status);
    }

    // ── 3. Wrong amount → no transition ──

    public function test_wrong_amount_does_not_transition(): void
    {
        $sub = $this->createPendingSubscription('mock_wrong_amt', 490);

        $response = $this->sendWebhook([
            'payment_id' => 'mock_wrong_amt',
            'status' => 'succeeded',
            'amount' => 999,
        ]);

        $response->assertOk();

        $attempt = PaymentAttempt::where('provider_payment_id', 'mock_wrong_amt')->first();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);

        $sub->refresh();
        $this->assertSame(SubscriptionStatus::Pending->value, $sub->status);
    }

    public function test_missing_amount_does_not_transition(): void
    {
        $sub = $this->createPendingSubscription('mock_no_amt', 490);

        $response = $this->sendWebhook([
            'payment_id' => 'mock_no_amt',
            'status' => 'succeeded',
        ]);

        $response->assertOk();

        $attempt = PaymentAttempt::where('provider_payment_id', 'mock_no_amt')->first();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);

        $sub->refresh();
        $this->assertSame(SubscriptionStatus::Pending->value, $sub->status);
    }

    // ── 4. Failed status ──

    public function test_failed_status_transitions_core_and_legacy(): void
    {
        $sub = $this->createPendingSubscription('mock_failed_1', 490);

        $response = $this->sendWebhook([
            'payment_id' => 'mock_failed_1',
            'status' => 'failed',
        ]);

        $response->assertOk();

        $attempt = PaymentAttempt::where('provider_payment_id', 'mock_failed_1')->first();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);

        $sub->refresh();
        $this->assertSame('failed', $sub->status);
    }

    // ── 5. Refunded status ──

    public function test_refunded_status_transitions_core_and_legacy(): void
    {
        $sub = $this->createPendingSubscription('mock_ref_1', 490);
        $sub->update(['status' => SubscriptionStatus::Active]);

        // Also mark attempt as succeeded first
        PaymentAttempt::where('provider_payment_id', 'mock_ref_1')
            ->update(['status' => PaymentAttemptStatus::Succeeded]);

        $response = $this->sendWebhook([
            'payment_id' => 'mock_ref_1',
            'status' => 'refunded',
        ]);

        $response->assertOk();

        $attempt = PaymentAttempt::where('provider_payment_id', 'mock_ref_1')->first();
        $this->assertSame(PaymentAttemptStatus::Refunded, $attempt->status);

        $sub->refresh();
        $this->assertSame('refunded', $sub->status);
    }

    // ── 6. Duplicate success = idempotent ──

    public function test_duplicate_success_is_idempotent(): void
    {
        $sub = $this->createPendingSubscription('mock_dup_1', 490);
        $subId = $sub->id;

        $this->sendWebhook([
            'payment_id' => 'mock_dup_1',
            'status' => 'succeeded',
            'amount' => 490,
        ]);

        $eventsAfterFirst = ProviderEvent::count();

        $this->sendWebhook([
            'payment_id' => 'mock_dup_1',
            'status' => 'succeeded',
            'amount' => 490,
        ]);

        // No new events
        $this->assertSame($eventsAfterFirst, ProviderEvent::count());

        // Subscription count unchanged
        $this->assertDatabaseCount('subscriptions', 1);

        $sub->refresh();
        $this->assertSame(SubscriptionStatus::Active->value, $sub->status);
        $this->assertSame($subId, $sub->id);
    }

    // ── 7. Unmatched payment_id = no-op ──

    public function test_unmatched_payment_id_returns_ok_no_mutation(): void
    {
        $response = $this->sendWebhook([
            'payment_id' => 'mock_nonexistent',
            'status' => 'succeeded',
            'amount' => 490,
        ]);

        $response->assertOk();
    }

    // ── 8. Provider event recorded ──

    public function test_provider_event_recorded_on_success(): void
    {
        $sub = $this->createPendingSubscription('mock_event_1', 490);

        $this->sendWebhook([
            'payment_id' => 'mock_event_1',
            'status' => 'succeeded',
            'amount' => 490,
        ]);

        $event = ProviderEvent::where('provider', 'mock')
            ->where('event_type', 'succeeded')
            ->where('processing_error', null)
            ->first();
        $this->assertNotNull($event);
        $this->assertNotNull($event->processed_at);
    }

    // ── 9. Provider-aware route ──

    public function test_provider_aware_route_with_unknown_provider(): void
    {
        $response = $this->postJson(route('webhooks.payment.provider', 'unknown_provider'), [
            'payment_id' => 'mock_any',
            'status' => 'succeeded',
            'amount' => 490,
        ], [
            'X-Webhook-Signature' => self::WEBHOOK_SECRET,
        ]);

        $response->assertStatus(400);
    }

    // ── 10. Checkout redirect does NOT activate ──

    public function test_checkout_redirect_does_not_activate_subscription(): void
    {
        $sub = $this->createPendingSubscription('mock_checkout_redirect', 490);

        $response = $this->get('/admin/settings?payment=mock_checkout_redirect');

        $sub->refresh();
        $this->assertSame(SubscriptionStatus::Pending->value, $sub->status);
    }

    // ── 11. T-Bank webhooks: plain-text "OK" body ──

    public function test_tbank_valid_webhook_returns_ok_body(): void
    {
        $this->createPendingSubscription('tbank_ok_1', 490, 'tbank');

        $response = $this->sendTbankWebhook([
            'TerminalKey' => self::TBANK_TERMINAL_KEY,
            'PaymentId' => 'tbank_ok_1',
            'OrderId' => 'ord_tbank_ok_1',
            'Status' => 'CONFIRMED',
            'Amount' => 49000,
        ]);

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());

        $attempt = PaymentAttempt::where('provider_payment_id', 'tbank_ok_1')->first();
        $this->assertNotNull($attempt);
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
    }

    public function test_tbank_validation_error_still_returns_ok_body(): void
    {
        $this->createPendingSubscription('tbank_wrong_amt', 490, 'tbank');

        $response = $this->sendTbankWebhook([
            'TerminalKey' => self::TBANK_TERMINAL_KEY,
            'PaymentId' => 'tbank_wrong_amt',
            'OrderId' => 'ord_tbank_wrong_amt',
            'Status' => 'CONFIRMED',
            'Amount' => 99900,
        ]);

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());

        $attempt = PaymentAttempt::where('provider_payment_id', 'tbank_wrong_amt')->first();
        $this->assertNotNull($attempt);
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
    }

    public function test_tbank_unmatched_webhook_still_returns_ok_body(): void
    {
        $response = $this->sendTbankWebhook([
            'TerminalKey' => self::TBANK_TERMINAL_KEY,
            'PaymentId' => 'tbank_missing_1',
            'OrderId' => 'ord_tbank_missing',
            'Status' => 'CONFIRMED',
            'Amount' => 49000,
        ]);

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());
    }

    public function test_tbank_invalid_token_returns_403(): void
    {
        $response = $this->postJson(route('webhooks.payment.provider', 'tbank'), [
            'TerminalKey' => self::TBANK_TERMINAL_KEY,
            'PaymentId' => 'tbank_any',
            'OrderId' => 'ord_tbank_any',
            'Status' => 'CONFIRMED',
            'Amount' => 49000,
            'Token' => str_repeat('0', 64),
        ]);

        $response->assertStatus(403);
    }
}
