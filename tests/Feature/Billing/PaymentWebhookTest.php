<?php

namespace Tests\Feature\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Services\Payment\MockPaymentGateway;
use App\Services\Payment\PaymentGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    private TariffPlan $proPlan;

    private TariffPlan $studioPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->master = User::factory()->master()->create([
            'tariff' => 'free',
            'expires_at' => null,
        ]);

        $this->proPlan = TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'is_active' => true,
        ]);

        $this->studioPlan = TariffPlan::create([
            'code' => 'studio',
            'name' => 'Студия',
            'price_monthly' => 1290,
            'is_active' => true,
        ]);

        $this->app->bind(PaymentGatewayInterface::class, MockPaymentGateway::class);
    }

    public function test_successful_payment_activates_subscription(): void
    {
        $subscription = Subscription::create([
            'user_id' => $this->master->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 990,
            'status' => SubscriptionStatus::Pending->value,
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
            'payment_id' => 'mock_txn_123',
        ]);

        Http::fake();

        $response = $this->postJson(route('webhooks.payment'), [
            'payment_id' => 'mock_txn_123',
            'subscription_id' => $subscription->id,
            'status' => 'succeeded',
        ], [
            'X-Webhook-Signature' => 'mock_secret_sig',
        ]);

        $response->assertOk();

        $this->master->refresh();

        $this->assertEquals('pro', $this->master->tariff);
        $this->assertNotNull($this->master->expires_at);
        $this->assertTrue($this->master->expires_at->isAfter(now()->addDays(29)));
        $this->assertTrue($this->master->expires_at->isBefore(now()->addDays(31)));

        $subscription->refresh();
        $this->assertEquals(SubscriptionStatus::Active->value, $subscription->status);
    }

    public function test_invalid_signature_returns_403(): void
    {
        Http::fake();

        $response = $this->postJson(route('webhooks.payment'), [
            'payment_id' => 'mock_txn_456',
            'status' => 'succeeded',
        ], [
            'X-Webhook-Signature' => 'invalid_signature',
        ]);

        $response->assertStatus(403);
    }

    public function test_failed_payment_does_not_change_tariff(): void
    {
        $subscription = Subscription::create([
            'user_id' => $this->master->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 990,
            'status' => SubscriptionStatus::Pending->value,
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
            'payment_id' => 'mock_txn_789',
        ]);

        Http::fake();

        $response = $this->postJson(route('webhooks.payment'), [
            'payment_id' => 'mock_txn_789',
            'subscription_id' => $subscription->id,
            'status' => 'failed',
        ], [
            'X-Webhook-Signature' => 'mock_secret_sig',
        ]);

        $response->assertOk();

        $this->master->refresh();

        $this->assertEquals('free', $this->master->tariff);
        $this->assertNull($this->master->expires_at);

        $subscription->refresh();
        $this->assertEquals(SubscriptionStatus::Failed->value, $subscription->status);
    }

    public function test_yearly_subscription_sets_correct_expiry(): void
    {
        $subscription = Subscription::create([
            'user_id' => $this->master->id,
            'tariff_plan_id' => $this->studioPlan->id,
            'period_months' => 12,
            'amount_paid' => 9900,
            'status' => SubscriptionStatus::Pending->value,
            'starts_at' => now(),
            'expires_at' => now()->addMonths(12),
            'payment_id' => 'mock_txn_yearly',
        ]);

        Http::fake();

        $response = $this->postJson(route('webhooks.payment'), [
            'payment_id' => 'mock_txn_yearly',
            'subscription_id' => $subscription->id,
            'status' => 'succeeded',
        ], [
            'X-Webhook-Signature' => 'mock_secret_sig',
        ]);

        $response->assertOk();

        $this->master->refresh();

        $this->assertEquals('studio', $this->master->tariff);
        $this->assertTrue($this->master->expires_at->isAfter(now()->addDays(364)));
        $this->assertTrue($this->master->expires_at->isBefore(now()->addDays(366)));
    }
}
