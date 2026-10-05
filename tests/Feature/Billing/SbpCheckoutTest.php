<?php

namespace Tests\Feature\Billing;

use App\Enums\PaymentAttemptStatus;
use App\Models\PaymentAttempt;
use App\Models\PlanPrice;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use App\Services\Payment\DTOs\PaymentInitiation;
use App\Services\Payment\DTOs\ProviderStatusUpdate;
use App\Services\Payment\PaymentGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SbpCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->proPlan = TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'max_appointments_per_month' => null,
            'max_masters' => 1,
            'features' => ['unlimited_appointments'],
            'is_active' => true,
        ]);

        foreach ([1, 3, 6, 12] as $months) {
            PlanPrice::create([
                'tariff_plan_id' => $this->proPlan->id,
                'period_months' => $months,
                'base_amount' => 490 * $months,
                'discount_percent' => 0,
                'final_amount' => 490 * $months,
                'currency' => 'RUB',
                'version' => 1,
                'valid_from' => now(),
                'is_active' => true,
            ]);
        }
    }

    private function createMasterWithWorkspace(): array
    {
        $master = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'ws-'.$master->id,
            'owner_id' => $master->id,
        ]);
        $workspace->ensureSlug();
        $master->update(['workspace_id' => $workspace->id]);

        return [$master, $workspace];
    }

    private function coreAttempt(): PaymentAttempt
    {
        return PaymentAttempt::where('internal_order_id', 'like', 'core_%')->firstOrFail();
    }

    // ── Service contract ──

    public function test_sbp_checkout_returns_sbp_contract(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $result = app(BillingService::class)->subscribe($master, $this->proPlan, 1, false, 'sbp');

        $this->assertSame(
            ['subscription', 'payment_method', 'payment_id', 'sbp_payload'],
            array_keys($result),
        );
        $this->assertSame('sbp', $result['payment_method']);
        $this->assertNotNull($result['payment_id']);
        $this->assertNotNull($result['sbp_payload']);
        $this->assertNotSame('', $result['sbp_payload']);
        $this->assertSame($result['payment_id'], $result['subscription']->payment_id);
    }

    public function test_sbp_attempt_is_processing_with_metadata(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $result = app(BillingService::class)->subscribe($master, $this->proPlan, 1, false, 'sbp');

        $attempt = $this->coreAttempt();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        $this->assertSame($result['payment_id'], $attempt->provider_payment_id);
        $this->assertSame('sbp', $attempt->metadata['payment_method']);
        $this->assertSame($result['sbp_payload'], $attempt->metadata['sbp_payload']);
        $this->assertArrayNotHasKey('checkout_url', $attempt->metadata);
        $this->assertSame($result['payment_id'], $attempt->provider_payment_id);
    }

    public function test_card_attempt_keeps_checkout_url_metadata(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $result = app(BillingService::class)->subscribe($master, $this->proPlan, 1, false, 'card');

        $attempt = $this->coreAttempt();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        $this->assertSame('card', $attempt->metadata['payment_method']);
        $this->assertSame($result['confirmation_url'], $attempt->metadata['checkout_url']);
        $this->assertArrayNotHasKey('sbp_payload', $attempt->metadata);
    }

    public function test_sbp_with_auto_renew_is_rejected(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        try {
            app(BillingService::class)->subscribe($master, $this->proPlan, 1, true, 'sbp');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('auto_renew', $e->errors());
            $this->assertStringContainsString(
                'Автопродление через СБП пока недоступно',
                $e->errors()['auto_renew'][0],
            );
        }

        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_invalid_payment_method_is_rejected(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        try {
            app(BillingService::class)->subscribe($master, $this->proPlan, 1, false, 'cash');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment_method', $e->errors());
        }

        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_gateway_receives_payment_method_in_context(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $gateway = new class implements PaymentGatewayInterface
        {
            public array $contexts = [];

            public function name(): string
            {
                return 'mock';
            }

            public function createPayment(int $amount, string $currency, string $internalOrderId, array $context = []): PaymentInitiation
            {
                $this->contexts[] = $context;

                return new PaymentInitiation(
                    providerPaymentId: 'test_sbp_1',
                    method: PaymentInitiation::METHOD_SBP,
                    payload: 'https://qr.nspk.ru/TEST',
                );
            }

            public function verifyWebhook(array $payload, string $signature): bool
            {
                return true;
            }

            public function normalizeWebhook(array $payload): ProviderStatusUpdate
            {
                throw new \RuntimeException('Not implemented');
            }

            public function getPaymentStatus(?string $providerPaymentId, string $internalOrderId): ?ProviderStatusUpdate
            {
                return null;
            }
        };
        $this->app->instance(PaymentGatewayInterface::class, $gateway);

        app(BillingService::class)->subscribe($master, $this->proPlan, 1, false, 'sbp');

        $this->assertCount(1, $gateway->contexts);
        $context = $gateway->contexts[0];
        $this->assertSame('sbp', $context['payment_method']);
        $this->assertArrayHasKey('workspace_id', $context);
        $this->assertArrayHasKey('auto_renew', $context);
        $this->assertFalse($context['auto_renew']);
    }

    public function test_sbp_double_click_returns_same_payment(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        $r1 = $service->subscribe($master, $this->proPlan, 1, false, 'sbp');
        $r2 = $service->subscribe($master, $this->proPlan, 1, false, 'sbp');

        $this->assertSame($r1['payment_id'], $r2['payment_id']);
        $this->assertSame($r1['sbp_payload'], $r2['sbp_payload']);
        $this->assertSame('sbp', $r2['payment_method']);
        $this->assertDatabaseCount('payment_attempts', 1);
    }

    // ── HTTP contract (POST /admin/checkout) ──
    // Web routes render validation errors as redirect + session errors
    // (shouldRenderJsonWhen is api/* in bootstrap/app.php).

    public function test_checkout_requires_payment_method(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $response = $this->actingAs($master)->post('/admin/checkout', [
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('payment_method');
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_checkout_rejects_unknown_payment_method(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $response = $this->actingAs($master)->post('/admin/checkout', [
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'payment_method' => 'cash',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('payment_method');
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_checkout_sbp_with_auto_renew_is_rejected(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $response = $this->actingAs($master)->post('/admin/checkout', [
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'auto_renew' => true,
            'payment_method' => 'sbp',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('auto_renew');
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_checkout_card_still_returns_checkout_url(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $response = $this->actingAs($master)->postJson('/admin/checkout', [
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'payment_method' => 'card',
        ]);

        $response->assertOk();

        $json = $response->json();
        $this->assertSame('card', $json['payment_method']);
        $this->assertNotNull($json['checkout_url']);
        $this->assertArrayHasKey('subscription_id', $json);
        $this->assertArrayHasKey('amount', $json);
        $this->assertArrayNotHasKey('sbp_payload', $json);

        $attempt = $this->coreAttempt();
        $this->assertSame('card', $attempt->metadata['payment_method']);
        $this->assertSame($json['checkout_url'], $attempt->metadata['checkout_url']);
    }

    public function test_checkout_sbp_returns_payload(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $response = $this->actingAs($master)->postJson('/admin/checkout', [
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'payment_method' => 'sbp',
        ]);

        $response->assertOk();

        $json = $response->json();
        $this->assertSame('sbp', $json['payment_method']);
        $this->assertNotNull($json['payment_id']);
        $this->assertNotNull($json['sbp_payload']);
        $this->assertArrayHasKey('subscription_id', $json);
        $this->assertArrayHasKey('amount', $json);
        $this->assertArrayNotHasKey('checkout_url', $json);
    }
}
