<?php

namespace Tests\Feature;

use App\Models\DiscountRule;
use App\Models\PlanPrice;
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

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.legacy_mock_webhook_secret' => 'test_secret_123']);
        config(['billing.core_entitlement' => true]);

        $this->billingService = app(BillingService::class);

        $this->proPlan = TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'is_active' => true,
        ]);

        // Create plan_prices (replaces discount_rules for pricing)
        $periods = [1, 3, 6, 12];
        $discounts = [
            1 => 0,
            3 => 5,
            6 => 10,
            12 => 20,
        ];

        foreach ($periods as $months) {
            $baseAmount = 490 * $months;
            $discountPercent = $discounts[$months];
            $finalAmount = (int) round($baseAmount * (1 - $discountPercent / 100));

            PlanPrice::create([
                'tariff_plan_id' => $this->proPlan->id,
                'period_months' => $months,
                'base_amount' => $baseAmount,
                'discount_percent' => $discountPercent,
                'final_amount' => $finalAmount,
                'currency' => 'RUB',
                'version' => 1,
                'valid_from' => now(),
                'is_active' => true,
            ]);
        }
    }

    // ═══════════════════════════════════════════
    // calculatePrice (via PlanPriceResolver)
    // ═══════════════════════════════════════════

    public function test_calculate_price_pro_12_months(): void
    {
        $result = $this->billingService->calculatePrice($this->proPlan, 12);

        $this->assertEquals(5880, $result['base']);
        $this->assertEquals(20, $result['discount_percent']);
        $this->assertEquals(4704, $result['final']);
    }

    public function test_calculate_price_pro_1_month(): void
    {
        $result = $this->billingService->calculatePrice($this->proPlan, 1);

        $this->assertEquals(490, $result['base']);
        $this->assertEquals(0, $result['discount_percent']);
        $this->assertEquals(490, $result['final']);
    }

    public function test_calculate_price_pro_3_months(): void
    {
        $result = $this->billingService->calculatePrice($this->proPlan, 3);

        $this->assertEquals(1470, $result['base']);
        $this->assertEquals(5, $result['discount_percent']);
        $this->assertEquals(1397, $result['final']);
    }

    public function test_calculate_price_returns_plan_price_id(): void
    {
        $result = $this->billingService->calculatePrice($this->proPlan, 1);

        $this->assertArrayHasKey('plan_price_id', $result);
        $this->assertNotNull($result['plan_price_id']);
    }

    public function test_calculate_price_fallback_when_no_plan_price(): void
    {
        config(['billing.core_entitlement' => false]);

        $unknownPlan = TariffPlan::create([
            'code' => 'unknown',
            'name' => 'Unknown',
            'price_monthly' => 100,
            'is_active' => true,
        ]);

        $result = $this->billingService->calculatePrice($unknownPlan, 1);

        // Falls back to computing from tariff_plans.price_monthly
        $this->assertEquals(100, $result['final']);
        $this->assertNull($result['plan_price_id']);
    }

    // ═══════════════════════════════════════════
    // subscribe
    // ═══════════════════════════════════════════

    public function test_subscribe_creates_pending_subscription(): void
    {
        $master = User::factory()->master()->create();

        $result = $this->billingService->subscribe($master, $this->proPlan, 1);

        $subscription = $result['subscription'];

        $this->assertNotNull($subscription);
        $this->assertEquals('pending', $subscription->status);
        $this->assertNotNull($subscription->payment_id);
    }

    public function test_subscribe_sets_correct_expires_at(): void
    {
        $master = User::factory()->master()->create();

        $result = $this->billingService->subscribe($master, $this->proPlan, 6);

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
        $master = User::factory()->master()->create();

        $result = $this->billingService->subscribe($master, $this->proPlan, 1);

        $payload = [
            'payment_id' => $result['subscription']->payment_id,
            'status' => 'paid',
            'amount' => $result['subscription']->amount_paid,
        ];

        $response = $this->postJson('/webhooks/payment', $payload, [
            'X-Webhook-Signature' => 'test_secret_123',
        ]);

        $response->assertOk();

        $result['subscription']->refresh();
        $this->assertEquals('active', $result['subscription']->status);
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

    public function test_subscribe_creates_workspace_for_solo_user(): void
    {
        $master = User::factory()->master()->create([
            'workspace_id' => null,
        ]);

        $this->assertNull($master->workspace_id);

        $result = $this->billingService->subscribe($master, $this->proPlan, 1);

        $master->refresh();

        $this->assertNotNull($master->workspace_id, 'Workspace should be created for solo user');
        $this->assertNotNull($master->workspace, 'Workspace relation should be loaded');

        $subscription = $result['subscription'];
        $this->assertEquals($master->workspace_id, $subscription->workspace_id, 'Subscription should be tied to the created workspace');

        $this->assertDatabaseHas('workspaces', [
            'owner_id' => $master->id,
        ]);
    }
}
