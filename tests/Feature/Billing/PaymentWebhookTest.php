<?php

namespace Tests\Feature\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Payment\PaymentGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'test_legacy_webhook_secret_abc123';

    private TariffPlan $proPlan;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.legacy_mock_webhook_secret' => self::WEBHOOK_SECRET]);

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

    private function createPendingSubscription(string $paymentId, int $amountPaid = 490): Subscription
    {
        return Subscription::create([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => $amountPaid,
            'status' => SubscriptionStatus::Pending,
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
            'payment_id' => $paymentId,
        ]);
    }

    private function sendWebhook(array $payload, string $signature = self::WEBHOOK_SECRET): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('webhooks.payment'), $payload, [
            'X-Webhook-Signature' => $signature,
        ]);
    }

    // ── 1. Secret not configured → 403 ──

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

    public function test_empty_secret_returns_403(): void
    {
        config(['billing.legacy_mock_webhook_secret' => '']);

        $response = $this->sendWebhook([
            'payment_id' => 'mock_any',
            'status' => 'succeeded',
            'amount' => 490,
        ]);

        $response->assertStatus(403);
    }

    // ── 2. Signature missing → 403 ──

    public function test_missing_signature_returns_403(): void
    {
        $response = $this->postJson(route('webhooks.payment'), [
            'payment_id' => 'mock_any',
            'status' => 'succeeded',
            'amount' => 490,
        ]);

        $response->assertStatus(403);
    }

    // ── 3. Signature invalid → 403 ──

    public function test_invalid_signature_returns_403(): void
    {
        $response = $this->sendWebhook(
            ['payment_id' => 'mock_any', 'status' => 'succeeded', 'amount' => 490],
            'wrong_signature',
        );

        $response->assertStatus(403);
    }

    // ── 4. Valid signature + wrong amount → subscription NOT active ──

    public function test_wrong_amount_does_not_activate_subscription(): void
    {
        $sub = $this->createPendingSubscription('mock_wrong_amt', 490);

        $response = $this->sendWebhook([
            'payment_id' => 'mock_wrong_amt',
            'status' => 'succeeded',
            'amount' => 999,
        ]);

        $response->assertOk();

        $sub->refresh();
        $this->assertSame(SubscriptionStatus::Pending->value, $sub->status);
    }

    // ── 5. Valid signature + missing amount → NOT active ──

    public function test_missing_amount_does_not_activate_subscription(): void
    {
        $sub = $this->createPendingSubscription('mock_no_amt', 490);

        $response = $this->sendWebhook([
            'payment_id' => 'mock_no_amt',
            'status' => 'succeeded',
        ]);

        $response->assertOk();

        $sub->refresh();
        $this->assertSame(SubscriptionStatus::Pending->value, $sub->status);
    }

    public function test_zero_amount_does_not_activate_subscription(): void
    {
        $sub = $this->createPendingSubscription('mock_zero_amt', 490);

        $response = $this->sendWebhook([
            'payment_id' => 'mock_zero_amt',
            'status' => 'succeeded',
            'amount' => 0,
        ]);

        $response->assertOk();

        $sub->refresh();
        $this->assertSame(SubscriptionStatus::Pending->value, $sub->status);
    }

    public function test_negative_amount_does_not_activate_subscription(): void
    {
        $sub = $this->createPendingSubscription('mock_neg_amt', 490);

        $response = $this->sendWebhook([
            'payment_id' => 'mock_neg_amt',
            'status' => 'succeeded',
            'amount' => -100,
        ]);

        $response->assertOk();

        $sub->refresh();
        $this->assertSame(SubscriptionStatus::Pending->value, $sub->status);
    }

    // ── 6. Valid signature + correct amount + allowed status → active ──

    public function test_valid_success_activates_pending_subscription(): void
    {
        $sub = $this->createPendingSubscription('mock_valid_1', 490);

        $response = $this->sendWebhook([
            'payment_id' => 'mock_valid_1',
            'status' => 'succeeded',
            'amount' => 490,
        ]);

        $response->assertOk();

        $sub->refresh();
        $this->assertSame(SubscriptionStatus::Active->value, $sub->status);
    }

    public function test_valid_paid_status_activates_pending_subscription(): void
    {
        $sub = $this->createPendingSubscription('mock_valid_paid', 490);

        $response = $this->sendWebhook([
            'payment_id' => 'mock_valid_paid',
            'status' => 'paid',
            'amount' => 490,
        ]);

        $response->assertOk();

        $sub->refresh();
        $this->assertSame(SubscriptionStatus::Active->value, $sub->status);
    }

    public function test_failed_status_sets_pending_to_failed(): void
    {
        $sub = $this->createPendingSubscription('mock_failed_1', 490);

        $response = $this->sendWebhook([
            'payment_id' => 'mock_failed_1',
            'status' => 'failed',
        ]);

        $response->assertOk();

        $sub->refresh();
        $this->assertSame(SubscriptionStatus::Failed->value, $sub->status);
    }

    public function test_refunded_status_sets_active_to_refunded(): void
    {
        $sub = $this->createPendingSubscription('mock_ref_1', 490);
        $sub->update(['status' => SubscriptionStatus::Active]);

        $response = $this->sendWebhook([
            'payment_id' => 'mock_ref_1',
            'status' => 'refunded',
        ]);

        $response->assertOk();

        $sub->refresh();
        $this->assertSame(SubscriptionStatus::Refunded->value, $sub->status);
    }

    // ── 7. Duplicate valid success doesn't break state ──

    public function test_duplicate_success_does_not_create_new_subscription(): void
    {
        $sub = $this->createPendingSubscription('mock_dup_1', 490);
        $subId = $sub->id;

        $this->sendWebhook([
            'payment_id' => 'mock_dup_1',
            'status' => 'succeeded',
            'amount' => 490,
        ]);

        $this->sendWebhook([
            'payment_id' => 'mock_dup_1',
            'status' => 'succeeded',
            'amount' => 490,
        ]);

        $this->assertDatabaseCount('subscriptions', 1);

        $sub->refresh();
        $this->assertSame(SubscriptionStatus::Active->value, $sub->status);
        $this->assertSame($subId, $sub->id);
    }

    // ── 8. Terminal subscription not reactivated by success event ──

    public function test_terminal_failed_not_reactivated_by_success(): void
    {
        $sub = $this->createPendingSubscription('mock_terminal_1', 490);
        $sub->update(['status' => SubscriptionStatus::Failed]);

        $response = $this->sendWebhook([
            'payment_id' => 'mock_terminal_1',
            'status' => 'succeeded',
            'amount' => 490,
        ]);

        $response->assertOk();

        $sub->refresh();
        $this->assertSame(SubscriptionStatus::Failed->value, $sub->status);
    }

    public function test_terminal_refunded_not_reactivated_by_success(): void
    {
        $sub = $this->createPendingSubscription('mock_terminal_2', 490);
        $sub->update(['status' => SubscriptionStatus::Refunded]);

        $response = $this->sendWebhook([
            'payment_id' => 'mock_terminal_2',
            'status' => 'succeeded',
            'amount' => 490,
        ]);

        $response->assertOk();

        $sub->refresh();
        $this->assertSame(SubscriptionStatus::Refunded->value, $sub->status);
    }

    public function test_terminal_expired_not_reactivated_by_success(): void
    {
        $sub = $this->createPendingSubscription('mock_terminal_3', 490);
        $sub->update(['status' => SubscriptionStatus::Expired]);

        $response = $this->sendWebhook([
            'payment_id' => 'mock_terminal_3',
            'status' => 'succeeded',
            'amount' => 490,
        ]);

        $response->assertOk();

        $sub->refresh();
        $this->assertSame(SubscriptionStatus::Expired->value, $sub->status);
    }

    // ── Extra: checkout redirect does NOT activate Pro ──

    public function test_checkout_redirect_does_not_activate_subscription(): void
    {
        $sub = $this->createPendingSubscription('mock_checkout_redirect', 490);

        // Simulate what the mock confirmation_url does: redirect to /admin/settings?payment=...
        // This endpoint does NOT activate any subscription — only the webhook does.
        $response = $this->get('/admin/settings?payment=mock_checkout_redirect');

        // The endpoint exists (admin settings) but doesn't activate anything
        // We just verify the subscription is still pending
        $sub->refresh();
        $this->assertSame(SubscriptionStatus::Pending->value, $sub->status);
    }
}
