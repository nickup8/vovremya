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
use App\Services\Payment\MockPaymentGateway;
use App\Services\Payment\PaymentGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * In-flight reuse must never substitute the selected payment method:
 * an SBP attempt cannot answer a card request, a card attempt cannot
 * answer an SBP request — controlled 422 instead, with no new attempt,
 * no Init and no cancellation of the existing payment.
 */
class CheckoutPaymentMethodMismatchTest extends TestCase
{
    use RefreshDatabase;

    private const MISMATCH = 'Есть незавершённый платёж другим способом. Сначала проверьте его статус';

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

    /**
     * Real initiation behaviour (SBP payload / card redirect) plus a call
     * counter — reuse must not reach the gateway a second time.
     */
    private function countingGateway(): PaymentGatewayInterface
    {
        $inner = new MockPaymentGateway;

        return new class($inner) implements PaymentGatewayInterface
        {
            public int $calls = 0;

            public function __construct(private MockPaymentGateway $inner) {}

            public function name(): string
            {
                return $this->inner->name();
            }

            public function createPayment(int $amount, string $currency, string $internalOrderId, array $context = []): PaymentInitiation
            {
                $this->calls++;

                return $this->inner->createPayment($amount, $currency, $internalOrderId, $context);
            }

            public function verifyWebhook(array $payload, string $signature): bool
            {
                return $this->inner->verifyWebhook($payload, $signature);
            }

            public function normalizeWebhook(array $payload): ProviderStatusUpdate
            {
                return $this->inner->normalizeWebhook($payload);
            }

            public function getPaymentStatus(?string $providerPaymentId, string $internalOrderId): ?ProviderStatusUpdate
            {
                return $this->inner->getPaymentStatus($providerPaymentId, $internalOrderId);
            }
        };
    }

    private function coreAttempt(): PaymentAttempt
    {
        return PaymentAttempt::where('internal_order_id', 'like', 'core_%')->firstOrFail();
    }

    private function expectMismatch(BillingService $service, User $master, string $requestedMethod): void
    {
        try {
            $service->subscribe($master, $this->proPlan, 1, false, $requestedMethod);
            $this->fail('Expected ValidationException for a mismatched in-flight method');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment_method', $e->errors());
            $this->assertStringContainsString(self::MISMATCH, $e->errors()['payment_method'][0]);
        }
    }

    /** Missing or contradictory method data: any controlled 422, no initiation. */
    private function expectBlocked(BillingService $service, User $master, string $requestedMethod): void
    {
        try {
            $service->subscribe($master, $this->proPlan, 1, false, $requestedMethod);
            $this->fail('Expected ValidationException for unusable in-flight method data');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    // ── sbp in-flight → card request ──

    public function test_sbp_attempt_rejects_card_request(): void
    {
        Http::fake();
        [$master] = $this->createMasterWithWorkspace();

        $gateway = $this->countingGateway();
        $this->app->instance(PaymentGatewayInterface::class, $gateway);
        $service = app(BillingService::class);

        $sbp = $service->subscribe($master, $this->proPlan, 1, false, 'sbp');
        $attempt = $this->coreAttempt();
        $this->assertSame(1, $gateway->calls);

        $this->expectMismatch($service, $master, 'card');

        // No new attempt, no new Init, no HTTP, no cancellation.
        $this->assertSame(1, $gateway->calls);
        Http::assertNothingSent();
        $this->assertDatabaseCount('payment_attempts', 1);
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        $this->assertSame($sbp['payment_id'], $attempt->provider_payment_id);
        $this->assertSame('sbp', $attempt->metadata['payment_method']);
        $this->assertArrayHasKey('sbp_payload', $attempt->metadata);
    }

    // ── card in-flight → sbp request ──

    public function test_card_attempt_rejects_sbp_request(): void
    {
        Http::fake();
        [$master] = $this->createMasterWithWorkspace();

        $gateway = $this->countingGateway();
        $this->app->instance(PaymentGatewayInterface::class, $gateway);
        $service = app(BillingService::class);

        $card = $service->subscribe($master, $this->proPlan, 1, false, 'card');
        $attempt = $this->coreAttempt();

        $this->expectMismatch($service, $master, 'sbp');

        $this->assertSame(1, $gateway->calls);
        Http::assertNothingSent();
        $this->assertDatabaseCount('payment_attempts', 1);
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        $this->assertSame($card['payment_id'], $attempt->provider_payment_id);
        $this->assertSame('card', $attempt->metadata['payment_method']);
        $this->assertArrayHasKey('checkout_url', $attempt->metadata);
    }

    // ── matching method keeps the previous resume ──

    public function test_matching_method_keeps_sbp_resume(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $gateway = $this->countingGateway();
        $this->app->instance(PaymentGatewayInterface::class, $gateway);
        $service = app(BillingService::class);

        $r1 = $service->subscribe($master, $this->proPlan, 1, false, 'sbp');
        $r2 = $service->subscribe($master, $this->proPlan, 1, false, 'sbp');

        $this->assertSame($r1['payment_id'], $r2['payment_id']);
        $this->assertSame($r1['sbp_payload'], $r2['sbp_payload']);
        $this->assertSame('sbp', $r2['payment_method']);
        $this->assertSame(1, $gateway->calls);
        $this->assertDatabaseCount('payment_attempts', 1);
    }

    public function test_matching_method_keeps_card_resume(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $gateway = $this->countingGateway();
        $this->app->instance(PaymentGatewayInterface::class, $gateway);
        $service = app(BillingService::class);

        $r1 = $service->subscribe($master, $this->proPlan, 1, false, 'card');
        $r2 = $service->subscribe($master, $this->proPlan, 1, false, 'card');

        $this->assertSame($r1['confirmation_url'], $r2['confirmation_url']);
        $this->assertSame($r1['payment_id'], $r2['payment_id']);
        $this->assertSame(1, $gateway->calls);
        $this->assertDatabaseCount('payment_attempts', 1);
    }

    // ── Fail closed on missing / contradictory method data ──

    public function test_processing_attempt_without_method_data_fails_closed(): void
    {
        Http::fake();
        [$master] = $this->createMasterWithWorkspace();

        $gateway = $this->countingGateway();
        $this->app->instance(PaymentGatewayInterface::class, $gateway);
        $service = app(BillingService::class);

        $service->subscribe($master, $this->proPlan, 1, false, 'card');

        // Method attribution lost — reuse must not hand out initiation.
        $attempt = $this->coreAttempt();
        $metadata = $attempt->metadata;
        unset($metadata['payment_method']);
        $attempt->update(['metadata' => $metadata]);

        $this->expectBlocked($service, $master, 'card');

        $this->assertSame(1, $gateway->calls);
        Http::assertNothingSent();
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertSame(PaymentAttemptStatus::Processing, $this->coreAttempt()->status);
    }

    public function test_contradictory_method_data_fails_closed(): void
    {
        Http::fake();
        [$master] = $this->createMasterWithWorkspace();

        $gateway = $this->countingGateway();
        $this->app->instance(PaymentGatewayInterface::class, $gateway);
        $service = app(BillingService::class);

        $service->subscribe($master, $this->proPlan, 1, false, 'sbp');

        // Declared card while the stored initiation is an SBP payload.
        $attempt = $this->coreAttempt();
        $attempt->update([
            'metadata' => array_merge($attempt->metadata, ['payment_method' => 'card']),
        ]);

        $this->expectBlocked($service, $master, 'card');

        $this->assertSame(1, $gateway->calls);
        Http::assertNothingSent();
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertSame(PaymentAttemptStatus::Processing, $this->coreAttempt()->status);
    }

    // ── HTTP contract: JSON clients get the controlled 422 ──

    public function test_json_checkout_sbp_attempt_returns_422_for_card(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $gateway = $this->countingGateway();
        $this->app->instance(PaymentGatewayInterface::class, $gateway);

        app(BillingService::class)->subscribe($master, $this->proPlan, 1, false, 'sbp');

        $response = $this->actingAs($master)->postJson('/admin/checkout', [
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'payment_method' => 'card',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.payment_method.0', self::MISMATCH);

        $this->assertSame(1, $gateway->calls);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertSame(PaymentAttemptStatus::Processing, $this->coreAttempt()->status);
    }

    public function test_json_checkout_card_attempt_returns_422_for_sbp(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $gateway = $this->countingGateway();
        $this->app->instance(PaymentGatewayInterface::class, $gateway);

        app(BillingService::class)->subscribe($master, $this->proPlan, 1, false, 'card');

        $response = $this->actingAs($master)->postJson('/admin/checkout', [
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'payment_method' => 'sbp',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.payment_method.0', self::MISMATCH);

        $this->assertSame(1, $gateway->calls);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertSame(PaymentAttemptStatus::Processing, $this->coreAttempt()->status);
    }
}
