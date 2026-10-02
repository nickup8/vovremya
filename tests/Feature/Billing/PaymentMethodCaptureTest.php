<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\SubscriptionStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Models\ProviderEvent;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PaymentMethodCaptureTest extends TestCase
{
    use RefreshDatabase;

    private const TBANK_TERMINAL_KEY = 'TestTerminal';

    private const TBANK_PASSWORD = 'tbank_test_password';

    private TariffPlan $proPlan;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        config([
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
    }

    private function createTbankAttempt(
        string $paymentId,
        int $amount = 490,
        PaymentAttemptStatus $status = PaymentAttemptStatus::Processing,
        ?BillingCycle $cycle = null,
    ): PaymentAttempt {
        if ($cycle === null) {
            $sub = Subscription::create([
                'workspace_id' => $this->workspace->id,
                'tariff_plan_id' => $this->proPlan->id,
                'period_months' => 1,
                'amount_paid' => $amount,
                'status' => SubscriptionStatus::Pending,
                'starts_at' => now(),
                'expires_at' => now()->addMonth(),
                'payment_id' => $paymentId,
            ]);

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
                'amount' => $amount,
                'currency' => 'RUB',
                'origin' => 'payment',
                'legacy_subscription_id' => $sub->id,
            ]);
        }

        return PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'tbank',
            'attempt_number' => (PaymentAttempt::where('billing_cycle_id', $cycle->id)->max('attempt_number') ?? 0) + 1,
            'amount' => $amount,
            'currency' => 'RUB',
            'internal_order_id' => 'core_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => $paymentId,
            'status' => $status,
            'initiated_at' => now(),
            'metadata' => ['legacy_subscription_id' => $cycle->legacy_subscription_id],
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

        $scalars['Password'] = self::TBANK_PASSWORD;

        ksort($scalars);

        return hash('sha256', implode('', $scalars));
    }

    private function sendTbankWebhook(array $payload): TestResponse
    {
        $payload['Token'] = $this->tbankToken($payload);

        return $this->postJson(route('webhooks.payment.provider', 'tbank'), $payload);
    }

    public function test_authorized_with_rebill_id_creates_card_method_without_entitlement(): void
    {
        $attempt = $this->createTbankAttempt('tbank_auth_1', status: PaymentAttemptStatus::Created);
        $cycleId = $attempt->billing_cycle_id;
        $legacyId = $attempt->metadata['legacy_subscription_id'];

        $response = $this->sendTbankWebhook([
            'TerminalKey' => self::TBANK_TERMINAL_KEY,
            'PaymentId' => 'tbank_auth_1',
            'OrderId' => $attempt->internal_order_id,
            'Status' => 'AUTHORIZED',
            'Amount' => 49000,
            'RebillId' => 'rebill_auth_1',
            'CustomerKey' => (string) $this->workspace->id,
            'CardId' => 'card_123',
        ]);

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        $this->assertNotNull($attempt->payment_method_id);

        $method = PaymentMethod::find($attempt->payment_method_id);
        $this->assertNotNull($method);
        $this->assertSame('tbank', $method->provider);
        $this->assertSame('card', $method->type);
        $this->assertSame('rebill_auth_1', $method->provider_reference);
        $this->assertSame('active', $method->status);
        $this->assertTrue($method->is_default);
        $this->assertSame($this->workspace->id, $method->workspace_id);

        // No entitlement: cycle and legacy subscription stay pending
        $cycle = BillingCycle::find($cycleId);
        $this->assertSame(BillingCycleStatus::Pending, $cycle->status);

        $legacy = Subscription::find($legacyId);
        $this->assertSame(SubscriptionStatus::Pending->value, $legacy->status);
    }

    public function test_authorized_on_processing_attempt_is_idempotent_and_captures_method(): void
    {
        // Production-like: after Init/paymentAttached the attempt is already processing
        $attempt = $this->createTbankAttempt('tbank_auth_proc', status: PaymentAttemptStatus::Processing);
        $cycleId = $attempt->billing_cycle_id;
        $legacyId = $attempt->metadata['legacy_subscription_id'];

        $response = $this->sendTbankWebhook([
            'TerminalKey' => self::TBANK_TERMINAL_KEY,
            'PaymentId' => 'tbank_auth_proc',
            'OrderId' => $attempt->internal_order_id,
            'Status' => 'AUTHORIZED',
            'Amount' => 49000,
            'RebillId' => 'rebill_auth_proc',
        ]);

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());

        // Transition success: provider event processed without error
        $event = ProviderEvent::where('provider', 'tbank')
            ->where('event_type', 'processing')
            ->first();
        $this->assertNotNull($event);
        $this->assertNull($event->processing_error);
        $this->assertNotNull($event->processed_at);

        // Attempt stays processing — no entitlement
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        $this->assertNotNull($attempt->payment_method_id);

        $cycle = BillingCycle::find($cycleId);
        $this->assertSame(BillingCycleStatus::Pending, $cycle->status);

        $legacy = Subscription::find($legacyId);
        $this->assertSame(SubscriptionStatus::Pending->value, $legacy->status);

        // Card method captured with RebillId
        $method = PaymentMethod::find($attempt->payment_method_id);
        $this->assertNotNull($method);
        $this->assertSame('tbank', $method->provider);
        $this->assertSame('card', $method->type);
        $this->assertSame('rebill_auth_proc', $method->provider_reference);
    }

    public function test_confirmed_with_rebill_id_creates_method_and_attempt_succeeds(): void
    {
        $attempt = $this->createTbankAttempt('tbank_conf_1');
        $cycleId = $attempt->billing_cycle_id;
        $legacyId = $attempt->metadata['legacy_subscription_id'];

        $response = $this->sendTbankWebhook([
            'TerminalKey' => self::TBANK_TERMINAL_KEY,
            'PaymentId' => 'tbank_conf_1',
            'OrderId' => $attempt->internal_order_id,
            'Status' => 'CONFIRMED',
            'Amount' => 49000,
            'RebillId' => 'rebill_conf_1',
        ]);

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
        $this->assertNotNull($attempt->payment_method_id);

        $method = PaymentMethod::find($attempt->payment_method_id);
        $this->assertNotNull($method);
        $this->assertSame('card', $method->type);
        $this->assertSame('rebill_conf_1', $method->provider_reference);
        $this->assertSame('active', $method->status);
        $this->assertSame(1, PaymentMethod::count());

        $cycle = BillingCycle::find($cycleId);
        $this->assertSame(BillingCycleStatus::Paid, $cycle->status);

        $legacy = Subscription::find($legacyId);
        $this->assertSame('active', $legacy->status);
    }

    public function test_repeated_webhook_does_not_duplicate_method(): void
    {
        $attempt = $this->createTbankAttempt('tbank_dup_1');

        $payload = [
            'TerminalKey' => self::TBANK_TERMINAL_KEY,
            'PaymentId' => 'tbank_dup_1',
            'OrderId' => $attempt->internal_order_id,
            'Status' => 'CONFIRMED',
            'Amount' => 49000,
            'RebillId' => 'rebill_dup_1',
        ];

        $this->sendTbankWebhook($payload)->assertOk();
        $this->sendTbankWebhook($payload)->assertOk();

        $this->assertSame(1, PaymentMethod::count());

        $attempt->refresh();
        $this->assertNotNull($attempt->payment_method_id);
    }

    public function test_new_rebill_id_becomes_default_and_old_is_demoted(): void
    {
        $attemptA = $this->createTbankAttempt('tbank_a');
        $this->sendTbankWebhook([
            'TerminalKey' => self::TBANK_TERMINAL_KEY,
            'PaymentId' => 'tbank_a',
            'OrderId' => $attemptA->internal_order_id,
            'Status' => 'CONFIRMED',
            'Amount' => 49000,
            'RebillId' => 'rebill_a',
        ])->assertOk();

        $attemptB = $this->createTbankAttempt('tbank_b', cycle: $attemptA->billingCycle);
        $this->sendTbankWebhook([
            'TerminalKey' => self::TBANK_TERMINAL_KEY,
            'PaymentId' => 'tbank_b',
            'OrderId' => $attemptB->internal_order_id,
            'Status' => 'CONFIRMED',
            'Amount' => 49000,
            'RebillId' => 'rebill_b',
        ])->assertOk();

        $this->assertSame(2, PaymentMethod::count());

        $methodA = PaymentMethod::where('provider_reference', 'rebill_a')->first();
        $methodB = PaymentMethod::where('provider_reference', 'rebill_b')->first();

        $this->assertNotNull($methodA);
        $this->assertNotNull($methodB);
        $this->assertFalse($methodA->is_default);
        $this->assertTrue($methodB->is_default);
    }

    public function test_invalid_token_does_not_create_method(): void
    {
        $this->postJson(route('webhooks.payment.provider', 'tbank'), [
            'TerminalKey' => self::TBANK_TERMINAL_KEY,
            'PaymentId' => 'tbank_any',
            'OrderId' => 'order_any',
            'Status' => 'CONFIRMED',
            'Amount' => 49000,
            'RebillId' => 'rebill_any',
            'Token' => str_repeat('0', 64),
        ])->assertStatus(403);

        $this->assertSame(0, PaymentMethod::count());
    }

    public function test_transition_validation_failure_does_not_create_method(): void
    {
        $attempt = $this->createTbankAttempt('tbank_bad_amt');

        $response = $this->sendTbankWebhook([
            'TerminalKey' => self::TBANK_TERMINAL_KEY,
            'PaymentId' => 'tbank_bad_amt',
            'OrderId' => $attempt->internal_order_id,
            'Status' => 'CONFIRMED',
            'Amount' => 99900,
            'RebillId' => 'rebill_bad_amt',
        ]);

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());

        $this->assertSame(0, PaymentMethod::count());

        $attempt->refresh();
        $this->assertNull($attempt->payment_method_id);
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
    }

    public function test_token_not_stored_in_method_metadata(): void
    {
        $attempt = $this->createTbankAttempt('tbank_meta_1');

        $payload = [
            'TerminalKey' => self::TBANK_TERMINAL_KEY,
            'PaymentId' => 'tbank_meta_1',
            'OrderId' => $attempt->internal_order_id,
            'Status' => 'CONFIRMED',
            'Amount' => 49000,
            'RebillId' => 'rebill_meta_1',
            'CustomerKey' => (string) $this->workspace->id,
            'CardId' => 'card_999',
        ];
        $payload['Token'] = $this->tbankToken($payload);
        $token = $payload['Token'];

        $this->postJson(route('webhooks.payment.provider', 'tbank'), $payload)->assertOk();

        $method = PaymentMethod::where('provider_reference', 'rebill_meta_1')->first();
        $this->assertNotNull($method);

        $metadata = $method->metadata;
        $this->assertSame(2, count($metadata));
        $this->assertSame((string) $this->workspace->id, $metadata['customer_key']);
        $this->assertSame('card_999', $metadata['card_id']);
        $this->assertArrayNotHasKey('Token', $metadata);
        $this->assertStringNotContainsString($token, json_encode($metadata));
    }
}
