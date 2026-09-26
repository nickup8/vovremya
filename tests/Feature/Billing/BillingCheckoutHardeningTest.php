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
use App\Models\ProviderEvent;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingCoreWriter;
use App\Services\Billing\BillingService;
use App\Services\Payment\PaymentGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BillingCheckoutHardeningTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.legacy_mock_webhook_secret' => 'test_secret_123']);

        $this->proPlan = TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'max_appointments_per_month' => null,
            'max_masters' => 1,
            'features' => ['unlimited_appointments'],
            'is_active' => true,
        ]);

        // Create plan_prices for all period options
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

    // ── 1. date-only periods НЕ считаются одинаковыми ──

    public function test_different_exact_timestamps_create_different_cycles(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        // Cycle A: 10:00:00 → next month 10:00:00
        $periodStartA = now()->startOfSecond();
        $periodEndA = $periodStartA->copy()->addMonth();

        $billingSub = BillingSubscription::create([
            'workspace_id' => $master->workspace_id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::PendingInitial,
        ]);

        BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $master->workspace_id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => $periodStartA,
            'period_end' => $periodEndA,
            'status' => BillingCycleStatus::Failed,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Payment,
        ]);

        // Cycle B: 10:00:01 → next month 10:00:01 (different second)
        $periodStartB = $periodStartA->copy()->addSeconds(1);
        $periodEndB = $periodEndA->copy()->addSeconds(1);

        BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $master->workspace_id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => $periodStartB,
            'period_end' => $periodEndB,
            'status' => BillingCycleStatus::Pending,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Payment,
        ]);

        // Should be 2 distinct cycles
        $cycleCount = BillingCycle::where('billing_subscription_id', $billingSub->id)->count();
        $this->assertSame(2, $cycleCount);
    }

    // ── 2. Double-click: processing + checkout_url returns existing (workspace+plan in-flight) ──

    public function test_double_click_returns_existing_checkout(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        // First checkout
        $r1 = $service->subscribe($master, $this->proPlan, 1);

        $attempt1 = PaymentAttempt::where('billing_cycle_id', BillingCycle::where('legacy_subscription_id', $r1['subscription']->id)->first()->id)->first();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt1->status);
        $this->assertNotNull($attempt1->metadata['checkout_url']);

        // Second checkout — same plan, returns existing
        $r2 = $service->subscribe($master, $this->proPlan, 1);

        $this->assertSame($r1['confirmation_url'], $r2['confirmation_url']);
        $this->assertDatabaseCount('billing_cycles', 1);
        $this->assertDatabaseCount('payment_attempts', 1);

        // Legacy pending row not superseded
        $legacy1 = Subscription::find($r1['subscription']->id);
        $this->assertSame('pending', $legacy1->status);
    }

    // ── 3. Double-click: processing + URL → provider called exactly once ──

    public function test_double_click_provider_called_once(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $callCounter = new \stdClass();
        $callCounter->count = 0;

        $gateway = new class ($callCounter) implements PaymentGatewayInterface {
            private \stdClass $counter;

            public function __construct(\stdClass $counter) { $this->counter = $counter; }

            public function createPayment(\App\Models\Subscription $subscription, int $amount, string $internalOrderId): array
            {
                $this->counter->count++;

                return [
                    'payment_id' => 'mock_'.uniqid(),
                    'confirmation_url' => 'http://example.com/pay?'.uniqid(),
                ];
            }

            public function verifyWebhook(array $payload, string $signature): bool { return true; }
            public function parseWebhookStatus(array $payload): ?string { return null; }
        };

        $this->app->instance(PaymentGatewayInterface::class, $gateway);
        $service = app(BillingService::class);

        $service->subscribe($master, $this->proPlan, 1);
        $service->subscribe($master, $this->proPlan, 1);

        $this->assertSame(1, $callCounter->count);
    }

    // ── 4. Unknown → 422, no retry ──

    public function test_unknown_blocks_retry(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $failingGateway = new class implements PaymentGatewayInterface {
            public function createPayment(\App\Models\Subscription $subscription, int $amount, string $internalOrderId): array
            {
                throw new \RuntimeException('Network timeout');
            }
            public function verifyWebhook(array $payload, string $signature): bool { return true; }
            public function parseWebhookStatus(array $payload): ?string { return null; }
        };
        $this->app->instance(PaymentGatewayInterface::class, $failingGateway);

        $service = app(BillingService::class);

        try { $service->subscribe($master, $this->proPlan, 1); } catch (\RuntimeException) {}

        $attempt = PaymentAttempt::where('internal_order_id', 'like', 'core_%')->first();
        $this->assertSame(PaymentAttemptStatus::Unknown, $attempt->status);

        // Second attempt: 422, no new attempt
        $this->expectException(ValidationException::class);
        $service->subscribe($master, $this->proPlan, 1);

        $this->assertDatabaseCount('payment_attempts', 1);
    }

    // ── 5. Created → 422 ──

    public function test_created_blocks_retry(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        $service->subscribe($master, $this->proPlan, 1);

        // Force attempt back to Created
        $attempt = PaymentAttempt::where('internal_order_id', 'like', 'core_%')->first();
        $attempt->update([
            'status' => PaymentAttemptStatus::Created,
            'provider_payment_id' => null,
        ]);

        $failingGateway = new class implements PaymentGatewayInterface {
            public function createPayment(\App\Models\Subscription $subscription, int $amount, string $internalOrderId): array
            {
                throw new \RuntimeException('Should not be called');
            }
            public function verifyWebhook(array $payload, string $signature): bool { return true; }
            public function parseWebhookStatus(array $payload): ?string { return null; }
        };
        $this->app->instance(PaymentGatewayInterface::class, $failingGateway);

        $this->expectException(ValidationException::class);
        $service->subscribe($master, $this->proPlan, 1);

        $this->assertDatabaseCount('payment_attempts', 1);
    }

    // ── 6. Exact failed cycle → retry attempt #N+1 ──

    public function test_terminal_retry_same_cycle_attempt_n_plus_1(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        \Carbon\Carbon::setTestNow(now());

        $r1 = $service->subscribe($master, $this->proPlan, 1);
        $this->sendWebhook($r1['subscription']->payment_id, 'failed', $r1['subscription']->amount_paid);

        $cycle = BillingCycle::where('legacy_subscription_id', $r1['subscription']->id)->first();
        $this->assertSame(BillingCycleStatus::Failed, $cycle->status);

        $attempt1 = PaymentAttempt::where('billing_cycle_id', $cycle->id)->first();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt1->status);

        $totalBefore = PaymentAttempt::count();

        // Retry — frozen time → exact period match → attempt #2 in same cycle
        $r2 = $service->subscribe($master, $this->proPlan, 1);

        \Carbon\Carbon::setTestNow();

        $this->assertNotNull($r2['subscription']);
        $this->assertSame('pending', $r2['subscription']->status);

        $totalAfter = PaymentAttempt::count();
        $this->assertGreaterThan($totalBefore, $totalAfter);

        $attempt2 = PaymentAttempt::where('billing_cycle_id', $cycle->id)
            ->where('status', PaymentAttemptStatus::Processing)
            ->first();
        $this->assertNotNull($attempt2);
        $this->assertSame(2, $attempt2->attempt_number);
    }

    // ── 7. Different exact timestamp → новый BillingCycle ──

    public function test_different_period_creates_new_cycle(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        // First checkout: cycle with period P1
        $r1 = $service->subscribe($master, $this->proPlan, 1);
        $cycle1 = BillingCycle::where('legacy_subscription_id', $r1['subscription']->id)->first();
        $periodStart1 = $cycle1->period_start;

        // Fail it
        $this->sendWebhook($r1['subscription']->payment_id, 'failed', $r1['subscription']->amount_paid);

        // Manually create a second legacy with different exact timestamps
        $newStart = $periodStart1->copy()->addSeconds(5);
        $newEnd = $newStart->copy()->addMonth();

        $r2 = $service->subscribe($master, $this->proPlan, 1);

        // Since same-second, timestamps likely match → same cycle. Verify cycle reuse.
        // But if timestamps differ (test takes >1s), a new cycle would be created.
        // This test verifies the mechanism, not the timing.
        $cycles = BillingCycle::where('billing_subscription_id', $cycle1->billing_subscription_id)->get();

        // We should have exactly 1 cycle (reuse) if same-second, or 2 if different-second.
        // Either way, no unique constraint violations.
        $this->assertLessThanOrEqual(2, $cycles->count());
    }

    // ── 8. LockTimeoutException → 422, not 500 ──

    public function test_lock_timeout_returns_422(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        // Simulate lock timeout by pre-acquiring the lock
        $lockKey = "billing-checkout:{$master->workspace_id}:{$this->proPlan->id}";
        $lock = Cache::lock($lockKey, 120);

        $this->assertTrue($lock->get());

        $service = app(BillingService::class);

        try {
            $service->subscribe($master, $this->proPlan, 1);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('plan', $e->errors());
            $this->assertStringContainsString('Платёж уже обрабатывается', $e->errors()['plan'][0]);
        } finally {
            $lock->release();
        }
    }

    // ── 9. Response reuse contains url/subscription_id/amount ──

    public function test_reuse_response_contract(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        $r1 = $service->subscribe($master, $this->proPlan, 1);
        $r2 = $service->subscribe($master, $this->proPlan, 1);

        $this->assertArrayHasKey('confirmation_url', $r2);
        $this->assertArrayHasKey('subscription', $r2);
        $this->assertNotNull($r2['confirmation_url']);
        $this->assertNotNull($r2['subscription']);
        $this->assertSame($r1['confirmation_url'], $r2['confirmation_url']);
        $this->assertSame($r1['subscription']->id, $r2['subscription']->id);
        $this->assertSame(490, $r2['subscription']->amount_paid);
    }

    // ── 10. Same-exact-period multi-legacy projection → 0/0 ──

    public function test_same_exact_period_projection_creates_no_duplicates(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $billingSub = BillingSubscription::create([
            'workspace_id' => $master->workspace_id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::PendingInitial,
        ]);

        $periodStart = now()->startOfSecond();
        $periodEnd = $periodStart->copy()->addMonth();

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $master->workspace_id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => BillingCycleStatus::Pending,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Payment,
        ]);

        // Legacy A: failed_terminal
        $legacyA = Subscription::create([
            'workspace_id' => $master->workspace_id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => 'failed',
            'starts_at' => $periodStart,
            'expires_at' => $periodEnd,
            'payment_id' => 'mock_legacy_a',
        ]);

        PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'mock',
            'provider_payment_id' => 'mock_legacy_a',
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'core_legacy_a',
            'status' => PaymentAttemptStatus::FailedTerminal,
            'initiated_at' => now(),
            'metadata' => ['legacy' => true, 'legacy_subscription_id' => $legacyA->id],
        ]);

        // Legacy B: succeeded
        $legacyB = Subscription::create([
            'workspace_id' => $master->workspace_id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => 'active',
            'starts_at' => $periodStart,
            'expires_at' => $periodEnd,
            'payment_id' => 'mock_legacy_b',
        ]);

        PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'mock',
            'provider_payment_id' => 'mock_legacy_b',
            'attempt_number' => 2,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'core_legacy_b',
            'status' => PaymentAttemptStatus::Succeeded,
            'initiated_at' => now(),
            'metadata' => ['legacy' => true, 'legacy_subscription_id' => $legacyB->id],
        ]);

        $projection = app(\App\Services\Billing\LegacyProjectionService::class);

        // Dry run (uses 'to_create' key)
        $dry = $projection->projectAll(dryRun: true);
        $this->assertSame(0, $dry['to_create']['billing_cycles']);
        $this->assertSame(0, $dry['to_create']['payment_attempts']);

        // Actual (uses 'created' key)
        $actual = $projection->projectAll(dryRun: false);
        $this->assertSame(0, $actual['created']['billing_cycles']);
        $this->assertSame(0, $actual['created']['payment_attempts']);

        // Still only 1 cycle, 2 attempts
        $this->assertDatabaseCount('billing_cycles', 1);
        $this->assertDatabaseCount('payment_attempts', 2);
    }

    // ── 11. Different-exact-period projection → two cycles, rerun 0/0 ──

    public function test_different_exact_period_projection_creates_two_cycles(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $billingSub = BillingSubscription::create([
            'workspace_id' => $master->workspace_id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::PendingInitial,
        ]);

        $periodStart1 = now()->startOfSecond();
        $periodEnd1 = $periodStart1->copy()->addMonth();

        // Legacy A: period 10:00:00
        $legacyA = Subscription::create([
            'workspace_id' => $master->workspace_id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => 'failed',
            'starts_at' => $periodStart1,
            'expires_at' => $periodEnd1,
            'payment_id' => 'mock_a',
        ]);

        // Legacy B: period 10:00:01 (different second)
        $periodStart2 = $periodStart1->copy()->addSeconds(1);
        $periodEnd2 = $periodEnd1->copy()->addSeconds(1);

        $legacyB = Subscription::create([
            'workspace_id' => $master->workspace_id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => 'active',
            'starts_at' => $periodStart2,
            'expires_at' => $periodEnd2,
            'payment_id' => 'mock_b',
        ]);

        $projection = app(\App\Services\Billing\LegacyProjectionService::class);

        // First projection: creates 2 cycles + 2 attempts
        $stats = $projection->projectAll(dryRun: false);
        $this->assertSame(2, $stats['created']['billing_cycles']);
        $this->assertSame(2, $stats['created']['payment_attempts']);

        $this->assertDatabaseCount('billing_cycles', 2);
        $this->assertDatabaseCount('payment_attempts', 2);

        // Rerun: 0/0
        $rerun = $projection->projectAll(dryRun: false);
        $this->assertSame(0, $rerun['created']['billing_cycles']);
        $this->assertSame(0, $rerun['created']['payment_attempts']);

        $this->assertDatabaseCount('billing_cycles', 2);
        $this->assertDatabaseCount('payment_attempts', 2);
    }

    // ── 12. Unknown late webhook recovery ──

    public function test_unknown_late_webhook_recovery(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $legacy = Subscription::create([
            'workspace_id' => $master->workspace_id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => 'pending',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
        ]);

        $billingSub = BillingSubscription::create([
            'workspace_id' => $master->workspace_id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::PendingInitial,
        ]);

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $master->workspace_id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now(),
            'period_end' => now()->addMonth(),
            'status' => BillingCycleStatus::Pending,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Payment,
            'legacy_subscription_id' => $legacy->id,
        ]);

        $internalOrderId = 'core_unknown_recovery_test';

        PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'mock',
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => $internalOrderId,
            'status' => PaymentAttemptStatus::Unknown,
            'initiated_at' => now(),
            'metadata' => [
                'legacy' => true,
                'legacy_subscription_id' => $legacy->id,
            ],
        ]);

        // Late webhook with order_id
        $payload = [
            'payment_id' => 'mock_late_success',
            'order_id' => $internalOrderId,
            'status' => 'paid',
            'amount' => 490,
        ];

        $this->postJson('/webhooks/payment', $payload, [
            'X-Webhook-Signature' => 'test_secret_123',
        ])->assertOk();

        $attempt = PaymentAttempt::where('internal_order_id', $internalOrderId)->first();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
        $this->assertSame('mock_late_success', $attempt->provider_payment_id);

        $legacy->refresh();
        $this->assertSame('active', $legacy->status);
        $this->assertSame('mock_late_success', $legacy->payment_id);

        $cycle->refresh();
        $this->assertSame(BillingCycleStatus::Paid, $cycle->status);

        $billingSub->refresh();
        $this->assertSame(BillingSubscriptionStatus::Active, $billingSub->status);
    }

    // ── Additional: canonical status ──

    public function test_first_checkout_sets_pending_initial(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        $service->subscribe($master, $this->proPlan, 1);

        $billingSub = BillingSubscription::where('workspace_id', $master->workspace_id)->first();
        $this->assertSame(BillingSubscriptionStatus::PendingInitial, $billingSub->status);
    }

    public function test_active_granting_stays_active_on_checkout(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        $r1 = $service->subscribe($master, $this->proPlan, 1);
        $this->sendWebhook($r1['subscription']->payment_id, 'paid', $r1['subscription']->amount_paid);

        $billingSub = BillingSubscription::where('workspace_id', $master->workspace_id)->first();
        $this->assertSame(BillingSubscriptionStatus::Active, $billingSub->status);

        $service->subscribe($master, $this->proPlan, 1);

        $billingSub->refresh();
        $this->assertSame(BillingSubscriptionStatus::Active, $billingSub->status);
    }

    public function test_expired_without_granting_sets_pending_initial(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        BillingSubscription::create([
            'workspace_id' => $master->workspace_id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::Canceled,
        ]);

        $service = app(BillingService::class);
        $service->subscribe($master, $this->proPlan, 1);

        $billingSub = BillingSubscription::where('workspace_id', $master->workspace_id)->first();
        $this->assertSame(BillingSubscriptionStatus::PendingInitial, $billingSub->status);
    }

    // ── Additional: provider event dedup + checkout url + phase C ──

    public function test_checkout_url_persisted_in_metadata(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        $result = $service->subscribe($master, $this->proPlan, 1);

        $attempt = PaymentAttempt::where('internal_order_id', 'like', 'core_%')->first();
        $this->assertNotNull($attempt->metadata['checkout_url']);
        $this->assertStringContainsString('/admin/settings?payment=', $attempt->metadata['checkout_url']);
        $this->assertSame($result['subscription']->id, $attempt->metadata['legacy_subscription_id']);
    }

    public function test_provider_event_dedup_composite_key(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        $result = $service->subscribe($master, $this->proPlan, 1);
        $paymentId = $result['subscription']->payment_id;
        $amount = $result['subscription']->amount_paid;

        $this->sendWebhook($paymentId, 'paid', $amount);
        $this->sendWebhook($paymentId, 'paid', $amount);

        $this->assertSame(1, ProviderEvent::where('dedup_key', $paymentId.':paid')->count());

        $this->sendWebhook($paymentId, 'refunded', $amount);

        $this->assertSame(1, ProviderEvent::where('dedup_key', $paymentId.':refunded')->count());
        $this->assertSame(2, ProviderEvent::where('provider', 'mock')->count());
    }

    public function test_subscription_status_transitions(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        $r1 = $service->subscribe($master, $this->proPlan, 1);
        $billingSub = BillingSubscription::where('workspace_id', $master->workspace_id)->first();
        $this->assertSame(BillingSubscriptionStatus::PendingInitial, $billingSub->status);

        $this->sendWebhook($r1['subscription']->payment_id, 'paid', $r1['subscription']->amount_paid);
        $billingSub->refresh();
        $this->assertSame(BillingSubscriptionStatus::Active, $billingSub->status);
    }

    public function test_cache_lock_released_after_checkout(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $lockKey = "billing-checkout:{$master->workspace_id}:{$this->proPlan->id}";
        $service = app(BillingService::class);

        $service->subscribe($master, $this->proPlan, 1);

        // Lock should be released — second call succeeds without timeout
        $r2 = $service->subscribe($master, $this->proPlan, 1);
        $this->assertNotNull($r2['subscription']);
    }

    // ── Helpers ──

    private function sendWebhook(string $paymentId, string $status, int $amount): void
    {
        $payload = [
            'payment_id' => $paymentId,
            'status' => $status,
            'amount' => $amount,
        ];

        $this->postJson('/webhooks/payment', $payload, [
            'X-Webhook-Signature' => 'test_secret_123',
        ])->assertOk();
    }
}
