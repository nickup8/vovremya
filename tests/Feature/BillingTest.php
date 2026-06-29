<?php

namespace Tests\Feature;

use App\Models\DiscountRule;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Payment\MockPaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private BillingService $billingService;
    private TariffPlan $proPlan;
    private TariffPlan $studioPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->billingService = new BillingService(new MockPaymentGateway());

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

        DiscountRule::insert([
            ['period_months' => 1, 'discount_percent' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['period_months' => 3, 'discount_percent' => 5, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['period_months' => 6, 'discount_percent' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['period_months' => 12, 'discount_percent' => 20, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    // ═══════════════════════════════════════════
    // calculatePrice
    // ═══════════════════════════════════════════

    public function test_calculate_price_pro_12_months(): void
    {
        $result = $this->billingService->calculatePrice($this->proPlan, 12);

        $this->assertEquals(5880, $result['base']);
        $this->assertEquals(20, $result['discount_percent']);
        $this->assertEquals(4704, $result['final']);
    }

    public function test_calculate_price_studio_1_month(): void
    {
        $result = $this->billingService->calculatePrice($this->studioPlan, 1);

        $this->assertEquals(1290, $result['base']);
        $this->assertEquals(0, $result['discount_percent']);
        $this->assertEquals(1290, $result['final']);
    }

    public function test_calculate_price_pro_3_months(): void
    {
        $result = $this->billingService->calculatePrice($this->proPlan, 3);

        $this->assertEquals(1470, $result['base']);
        $this->assertEquals(5, $result['discount_percent']);
        $this->assertEquals(1397, $result['final']);
    }

    // ═══════════════════════════════════════════
    // subscribe
    // ═══════════════════════════════════════════

    public function test_subscribe_creates_pending_subscription(): void
    {
        $master = User::factory()->master()->create();

        $result = $this->billingService->subscribe($master, $this->proPlan, 12);

        $subscription = $result['subscription'];

        $this->assertNotNull($subscription);
        $this->assertEquals('pending', $subscription->status);
        $this->assertEquals(4704, $subscription->amount_paid);
        $this->assertEquals(12, $subscription->period_months);
        $this->assertEquals($this->proPlan->id, $subscription->tariff_plan_id);
        $this->assertEquals($master->id, $subscription->user_id);

        $this->assertNotNull($result['confirmation_url']);
        $this->assertNotNull($subscription->payment_id);
        $this->assertStringStartsWith('mock_', $subscription->payment_id);
    }

    public function test_subscribe_sets_correct_expires_at(): void
    {
        $master = User::factory()->master()->create();

        $result = $this->billingService->subscribe($master, $this->studioPlan, 6);

        $subscription = $result['subscription'];

        $this->assertNotNull($subscription->starts_at);
        $this->assertNotNull($subscription->expires_at);
        $this->assertTrue($subscription->expires_at->eq(
            $subscription->starts_at->copy()->addMonths(6)
        ));
    }

    // ═══════════════════════════════════════════
    // Payment Webhook Activation
    // ═══════════════════════════════════════════

    public function test_webhook_payment_activates_subscription(): void
    {
        $master = User::factory()->master()->create([
            'tariff' => 'free',
        ]);

        $result = $this->billingService->subscribe($master, $this->proPlan, 3);
        $subscription = $result['subscription'];

        $this->assertEquals('pending', $subscription->status);

        config()->offsetSet('services.telegram.secret_token', null);

        $response = $this->postJson('/webhooks/payment', [
            'payment_id' => $subscription->payment_id,
            'status' => 'paid',
        ], [
            'X-Webhook-Signature' => 'mock_secret_sig',
        ]);

        $response->assertOk();

        $subscription->refresh();
        $master->refresh();

        $this->assertEquals('active', $subscription->status);
        $this->assertEquals('pro', $master->tariff);
        $this->assertEquals($subscription->expires_at->toDateTimeString(), $master->expires_at->toDateTimeString());
    }

    public function test_webhook_payment_with_invalid_signature_is_rejected(): void
    {
        $master = User::factory()->master()->create();
        $result = $this->billingService->subscribe($master, $this->proPlan, 1);
        $subscription = $result['subscription'];

        $response = $this->postJson('/webhooks/payment', [
            'payment_id' => $subscription->payment_id,
            'status' => 'paid',
        ], [
            'X-Webhook-Signature' => 'wrong-sig',
        ]);

        $response->assertStatus(403);

        $subscription->refresh();
        $this->assertEquals('pending', $subscription->status);
    }
}
