<?php

namespace Tests\Feature\Billing;

use App\Enums\PaymentAttemptStatus;
use App\Models\BillingSubscription;
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
use Tests\TestCase;

class BillingCheckoutAutoRenewTest extends TestCase
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

    private function billingSub(User $master): BillingSubscription
    {
        return BillingSubscription::where('workspace_id', $master->workspace_id)
            ->where('tariff_plan_id', $this->proPlan->id)
            ->firstOrFail();
    }

    private function recordingGateway(): PaymentGatewayInterface
    {
        return new class implements PaymentGatewayInterface
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
                    providerPaymentId: 'test_'.bin2hex(random_bytes(8)),
                    method: 'redirect',
                    checkoutUrl: 'https://example.test/checkout',
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
    }

    public function test_legacy_checkout_without_auto_renew_still_works(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $result = app(BillingService::class)->subscribe($master, $this->proPlan, 1);

        $this->assertNotNull($result['confirmation_url']);

        $sub = $this->billingSub($master);
        $this->assertNull($sub->auto_renew_consent_at);
        $this->assertNull($sub->auto_renew_consent_version);
        $this->assertNull($sub->renewal_period_months);
    }

    public function test_auto_renew_false_records_no_consent(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $gateway = $this->recordingGateway();
        $this->app->instance(PaymentGatewayInterface::class, $gateway);

        app(BillingService::class)->subscribe($master, $this->proPlan, 1, false);

        $sub = $this->billingSub($master);
        $this->assertNull($sub->auto_renew_consent_at);
        $this->assertNull($sub->auto_renew_consent_version);
        $this->assertNull($sub->renewal_period_months);

        $this->assertCount(1, $gateway->contexts);
        $this->assertFalse($gateway->contexts[0]['auto_renew']);
        $this->assertSame($master->workspace_id, $gateway->contexts[0]['workspace_id']);
    }

    public function test_auto_renew_true_records_consent(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $gateway = $this->recordingGateway();
        $this->app->instance(PaymentGatewayInterface::class, $gateway);

        app(BillingService::class)->subscribe($master, $this->proPlan, 3, true);

        $sub = $this->billingSub($master);
        $this->assertNotNull($sub->auto_renew_consent_at);
        $this->assertSame(config('billing.recurring_terms_version'), $sub->auto_renew_consent_version);
        $this->assertSame(3, $sub->renewal_period_months);
        $this->assertFalse($sub->cancel_at_period_end);

        $this->assertCount(1, $gateway->contexts);
        $this->assertTrue($gateway->contexts[0]['auto_renew']);
        $this->assertSame($master->workspace_id, $gateway->contexts[0]['workspace_id']);
    }

    public function test_next_charge_at_stays_null_even_with_consent(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        app(BillingService::class)->subscribe($master, $this->proPlan, 1, true);

        $sub = $this->billingSub($master);
        $this->assertNotNull($sub->auto_renew_consent_at);
        $this->assertNull($sub->next_charge_at);
        $this->assertNull($sub->grace_until);
    }

    public function test_manual_checkout_false_preserves_existing_consent(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        $service->subscribe($master, $this->proPlan, 1, true);

        $sub = $this->billingSub($master);
        $this->assertNotNull($sub->auto_renew_consent_at);

        // Pin consent to a distinct past value so an accidental rewrite is detectable
        $pinnedConsentAt = $sub->auto_renew_consent_at->copy()->subDays(5);
        $sub->update(['auto_renew_consent_at' => $pinnedConsentAt]);

        // Clear the in-flight attempt so the next checkout proceeds
        PaymentAttempt::where('status', PaymentAttemptStatus::Processing)
            ->update(['status' => PaymentAttemptStatus::FailedTerminal]);

        $service->subscribe($master, $this->proPlan, 1, false);

        $sub->refresh();
        $this->assertNotNull($sub->auto_renew_consent_at);
        $this->assertTrue($sub->auto_renew_consent_at->equalTo($pinnedConsentAt));
        $this->assertSame(config('billing.recurring_terms_version'), $sub->auto_renew_consent_version);
    }

    public function test_checkout_request_without_auto_renew_is_accepted(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $response = $this->actingAs($master)->post('/admin/checkout', [
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
        ]);

        $response->assertOk();

        $sub = $this->billingSub($master);
        $this->assertNull($sub->auto_renew_consent_at);
        $this->assertNull($sub->auto_renew_consent_version);
    }
}
