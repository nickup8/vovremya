<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleOrigin;
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
use App\Services\Billing\BillingCoreWriter;
use App\Services\Billing\BillingService;
use App\Services\Payment\DTOs\PaymentInitiation;
use App\Services\Payment\DTOs\ProviderStatusUpdate;
use App\Services\Payment\MockPaymentGateway;
use App\Services\Payment\PaymentGatewayInterface;
use App\Services\Payment\PaymentReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Локальный age-release (failed_terminal + reconciliation_timeout) — с
 * provider_payment_id или без: исход оплаты неизвестен банку, а потерянный
 * ответ Init не доказывает отсутствия платежа, поэтому повторный checkout
 * блокируется controlled 422 «уточняется» до выяснения. Подтверждённый отказ
 * (включая DEADLINE_EXPIRED → provider_failed) по-прежнему разрешает новый
 * checkout.
 */
class CheckoutReconciliationTimeoutBlockTest extends TestCase
{
    use RefreshDatabase;

    private const TIMEOUT_MESSAGE = 'Статус предыдущего платежа уточняется. Попробуйте позже.';

    private TariffPlan $proPlan;

    private TariffPlan $altPlan;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.legacy_mock_webhook_secret' => 'test_secret_123']);
        config(['billing.reconciliation' => [
            'batch_size' => 50,
            'fresh_grace_seconds' => 0,
            'backoff' => [60, 120, 300],
            'max_age_with_provider_id' => 86400,
            'max_age_without_provider_id' => 1800,
        ]]);

        $this->proPlan = TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'max_appointments_per_month' => null,
            'max_masters' => 1,
            'features' => ['unlimited_appointments'],
            'is_active' => true,
        ]);

        $this->altPlan = TariffPlan::create([
            'code' => 'plus',
            'name' => 'Плюс',
            'price_monthly' => 990,
            'max_appointments_per_month' => null,
            'max_masters' => 2,
            'features' => ['unlimited_appointments'],
            'is_active' => true,
        ]);

        foreach ([$this->proPlan, $this->altPlan] as $plan) {
            foreach ([1, 3, 6, 12] as $months) {
                PlanPrice::create([
                    'tariff_plan_id' => $plan->id,
                    'period_months' => $months,
                    'base_amount' => $plan->price_monthly * $months,
                    'discount_percent' => 0,
                    'final_amount' => $plan->price_monthly * $months,
                    'currency' => 'RUB',
                    'version' => 1,
                    'valid_from' => now(),
                    'is_active' => true,
                ]);
            }
        }
    }

    // ── Helpers ──

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
     * Age-released attempt: failed_terminal + reconciliation_timeout.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function createTimeoutAttempt(
        Workspace $workspace,
        TariffPlan $plan,
        ?string $providerPaymentId = 'tbank_700123456',
        array $metadata = ['payment_method' => 'card', 'checkout_url' => 'https://example.test/pay'],
        string $failureCategory = 'reconciliation_timeout',
    ): PaymentAttempt {
        $subscription = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'status' => BillingSubscriptionStatus::PendingInitial,
        ]);

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $subscription->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'period_start' => now(),
            'period_end' => now()->addMonth(),
            'status' => BillingCycleStatus::Failed,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Payment,
        ]);

        $attempt = new PaymentAttempt([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'tbank',
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'core_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => $providerPaymentId,
            'status' => PaymentAttemptStatus::FailedTerminal,
            'failure_category' => $failureCategory,
            'initiated_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(30),
            'metadata' => $metadata,
        ]);
        $attempt->created_at = now()->subHour();
        $attempt->save();

        return $attempt;
    }

    private function expectTimeout422(BillingService $service, User $master, string $paymentMethod): void
    {
        try {
            $service->subscribe($master, $this->proPlan, 1, false, $paymentMethod);
            $this->fail('Expected ValidationException for an unresolved previous payment');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('plan', $e->errors());
            $this->assertSame(self::TIMEOUT_MESSAGE, $e->errors()['plan'][0]);
        }
    }

    /**
     * Real initiation behaviour plus a call counter — a blocked checkout
     * must never reach the gateway.
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

    // ── 1. Timeout + provider_payment_id: картой и СБП → 422 ──

    public function test_timeout_with_provider_id_blocks_card_and_sbp_checkout(): void
    {
        Http::fake();
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $attempt = $this->createTimeoutAttempt($workspace, $this->proPlan);

        $gateway = $this->countingGateway();
        $this->app->instance(PaymentGatewayInterface::class, $gateway);
        $service = app(BillingService::class);

        // Хранится card-инициация: SBP-запрос обязан получить тот же
        // «уточняется», а не ошибку несовпадения способа.
        $this->expectTimeout422($service, $master, 'card');
        $this->expectTimeout422($service, $master, 'sbp');

        // Без initiation, без HTTP, без новых attempts.
        $this->assertSame(0, $gateway->calls);
        Http::assertNothingSent();
        $this->assertDatabaseCount('payment_attempts', 1);

        // Старая попытка неизменна.
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('reconciliation_timeout', $attempt->failure_category);
        $this->assertSame('tbank_700123456', $attempt->provider_payment_id);
        $this->assertSame(1, $attempt->attempt_number);
    }

    // ── 2. Workspace/plan scope сохранён ──

    public function test_timeout_block_is_scoped_to_workspace_and_plan(): void
    {
        [$masterA, $workspaceA] = $this->createMasterWithWorkspace();
        [$masterB, $workspaceB] = $this->createMasterWithWorkspace();

        $blocked = $this->createTimeoutAttempt($workspaceA, $this->proPlan);

        $writer = app(BillingCoreWriter::class);

        // Свой workspace + свой план → блокируется.
        $this->assertNotNull($writer->findExistingInFlightAttempt($workspaceA->id, $this->proPlan->id));
        $this->assertSame(
            $blocked->id,
            $writer->findExistingInFlightAttempt($workspaceA->id, $this->proPlan->id)?->id,
        );

        // Чужой workspace и чужой план не попадают в выборку (OR-ветка
        // закрыта тем же workspace+plan scope).
        $this->assertNull($writer->findExistingInFlightAttempt($workspaceB->id, $this->proPlan->id));
        $this->assertNull($writer->findExistingInFlightAttempt($workspaceA->id, $this->altPlan->id));

        // Чужой workspace спокойно проходит checkout.
        $gateway = $this->countingGateway();
        $this->app->instance(PaymentGatewayInterface::class, $gateway);
        $service = app(BillingService::class);

        $result = $service->subscribe($masterB, $this->proPlan, 1, false, 'card');

        $this->assertSame(1, $gateway->calls);
        $this->assertNotNull($result['payment_id']);
        $this->assertNotSame($blocked->provider_payment_id, $result['payment_id']);

        // Свой workspace на другом плане тоже не блокируется.
        $service->subscribe($masterA, $this->altPlan, 1, false, 'card');
        $this->assertSame(2, $gateway->calls);
    }

    // ── 3. GetState DEADLINE_EXPIRED → reconciliation → новый checkout ──

    public function test_deadline_expired_reconciliation_unblocks_new_checkout(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();

        // In-flight SBP-попытка, уже привязанная к банку.
        $subscription = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::PendingInitial,
        ]);
        $cycle = BillingCycle::create([
            'billing_subscription_id' => $subscription->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now(),
            'period_end' => now()->addMonth(),
            'status' => BillingCycleStatus::Pending,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Payment,
        ]);
        $oldAttempt = new PaymentAttempt([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'tbank',
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'core_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => 'tbank_old_123',
            'status' => PaymentAttemptStatus::Processing,
            'initiated_at' => now()->subHour(),
            'metadata' => ['payment_method' => 'sbp', 'sbp_payload' => 'https://qr.nspk.ru/AS1'],
        ]);
        $oldAttempt->created_at = now()->subHour();
        $oldAttempt->save();

        // Официальный сценарий «Платеж — отказ по таймауту»
        // (developer.tbank.ru/eacq/intro/errors/test-sbp).
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => true,
                'PaymentId' => 'tbank_old_123',
                'OrderId' => $oldAttempt->internal_order_id,
                'Status' => 'DEADLINE_EXPIRED',
                'Amount' => 49000,
                'ErrorCode' => '0',
            ], 200),
        ]);

        $result = app(PaymentReconciliationService::class)->reconcile();
        $this->assertSame(0, $result['errors']);

        // Банк сообщил отказ сам → provider_failed, не reconciliation_timeout.
        $oldAttempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $oldAttempt->status);
        $this->assertSame('provider_failed', $oldAttempt->failure_category);
        $cycle->refresh();
        $this->assertSame(BillingCycleStatus::Failed, $cycle->status);

        // Подтверждённый отказ разрешает новый checkout.
        $gateway = $this->countingGateway();
        $this->app->instance(PaymentGatewayInterface::class, $gateway);
        $service = app(BillingService::class);

        $checkout = $service->subscribe($master, $this->proPlan, 1, false, 'card');

        $this->assertSame(1, $gateway->calls);
        $this->assertNotNull($checkout['payment_id']);
        $this->assertNotSame($oldAttempt->provider_payment_id, $checkout['payment_id']);

        // Новая попытка создана, старая осталась подтверждённо неуспешной.
        $this->assertDatabaseCount('payment_attempts', 2);
        $newAttempt = PaymentAttempt::where('status', PaymentAttemptStatus::Processing)->firstOrFail();
        $this->assertNotSame($oldAttempt->id, $newAttempt->id);
        $this->assertSame($checkout['payment_id'], $newAttempt->provider_payment_id);

        $oldAttempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $oldAttempt->status);
        $this->assertSame('provider_failed', $oldAttempt->failure_category);

        // Единственный HTTP в сценарии — GetState reconciliation'а.
        Http::assertSentCount(1);
    }

    // ── 4. Timeout без provider_payment_id: карта и СБП → 422 ──

    public function test_timeout_without_provider_id_blocks_card_and_sbp_checkout(): void
    {
        Http::fake();
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $attempt = $this->createTimeoutAttempt($workspace, $this->proPlan, providerPaymentId: null);

        $gateway = $this->countingGateway();
        $this->app->instance(PaymentGatewayInterface::class, $gateway);
        $service = app(BillingService::class);

        // Потерянный ответ Init не доказывает отсутствия платежа у банка.
        // Хранится card-инициация: SBP-запрос обязан получить тот же
        // «уточняется», а не ошибку несовпадения способа.
        $this->expectTimeout422($service, $master, 'card');
        $this->expectTimeout422($service, $master, 'sbp');

        // Без нового attempt, без вызовов gateway, без HTTP.
        $this->assertSame(0, $gateway->calls);
        Http::assertNothingSent();
        $this->assertDatabaseCount('payment_attempts', 1);

        // Старая попытка неизменна.
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('reconciliation_timeout', $attempt->failure_category);
        $this->assertNull($attempt->provider_payment_id);
        $this->assertSame(1, $attempt->attempt_number);
    }

    public function test_timeout_with_empty_provider_id_is_blocked(): void
    {
        Http::fake();
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $attempt = $this->createTimeoutAttempt($workspace, $this->proPlan, providerPaymentId: '');

        $gateway = $this->countingGateway();
        $this->app->instance(PaymentGatewayInterface::class, $gateway);
        $service = app(BillingService::class);

        $this->expectTimeout422($service, $master, 'card');

        $this->assertSame(0, $gateway->calls);
        Http::assertNothingSent();
        $this->assertDatabaseCount('payment_attempts', 1);

        $attempt->refresh();
        $this->assertSame('', $attempt->provider_payment_id);
    }

    // ── 5. Scope и подтверждённый отказ сохранены ──

    public function test_timeout_block_without_provider_id_is_scoped_to_workspace_and_plan(): void
    {
        [$masterA, $workspaceA] = $this->createMasterWithWorkspace();
        [, $workspaceB] = $this->createMasterWithWorkspace();

        $blocked = $this->createTimeoutAttempt($workspaceA, $this->proPlan, providerPaymentId: null);

        $writer = app(BillingCoreWriter::class);

        // Свой workspace + свой план → блокируется.
        $this->assertSame(
            $blocked->id,
            $writer->findExistingInFlightAttempt($workspaceA->id, $this->proPlan->id)?->id,
        );

        // Чужой workspace и чужой план не попадают в выборку.
        $this->assertNull($writer->findExistingInFlightAttempt($workspaceB->id, $this->proPlan->id));
        $this->assertNull($writer->findExistingInFlightAttempt($workspaceA->id, $this->altPlan->id));
    }

    public function test_confirmed_provider_failure_is_never_in_flight(): void
    {
        Http::fake();
        [$master, $workspace] = $this->createMasterWithWorkspace();
        [, $workspaceNoId] = $this->createMasterWithWorkspace();

        // Блокирует категория reconciliation_timeout, а не сам статус
        // failed_terminal: подтверждённый отказ (provider_failed, в том
        // числе DEADLINE_EXPIRED) не блокирует — ни с provider_payment_id,
        // ни без него.
        $this->createTimeoutAttempt(
            $workspace,
            $this->proPlan,
            providerPaymentId: 'tbank_1',
            failureCategory: 'provider_failed',
        );
        $this->createTimeoutAttempt(
            $workspaceNoId,
            $this->proPlan,
            providerPaymentId: null,
            failureCategory: 'provider_failed',
        );

        $writer = app(BillingCoreWriter::class);
        $this->assertNull($writer->findExistingInFlightAttempt($workspace->id, $this->proPlan->id));
        $this->assertNull($writer->findExistingInFlightAttempt($workspaceNoId->id, $this->proPlan->id));

        // И новый checkout спокойно проходит.
        $gateway = $this->countingGateway();
        $this->app->instance(PaymentGatewayInterface::class, $gateway);
        $service = app(BillingService::class);

        $result = $service->subscribe($master, $this->proPlan, 1, false, 'card');

        $this->assertSame(1, $gateway->calls);
        $this->assertNotNull($result['payment_id']);
        $this->assertDatabaseCount('payment_attempts', 3);
    }
}
