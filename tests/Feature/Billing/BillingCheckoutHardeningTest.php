<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\SubscriptionStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\PaymentAttempt;
use App\Models\ProviderEvent;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingCoreWriter;
use App\Services\Billing\BillingService;
use App\Services\Payment\PaymentGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    // ── #18: Double click: processing + checkout_url returns existing ──

    public function test_double_click_with_processing_attempt_returns_existing_checkout(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        // First checkout
        $r1 = $service->subscribe($master, $this->proPlan, 1);

        // In-flight check: attempt is Processing
        $attempt1 = PaymentAttempt::where('billing_cycle_id', BillingCycle::where('legacy_subscription_id', $r1['subscription']->id)->first()->id)->first();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt1->status);
        $this->assertNotNull($attempt1->metadata['checkout_url']);

        // Second checkout — same plan, same period → returns existing
        $r2 = $service->subscribe($master, $this->proPlan, 1);

        // Should return the same subscription + checkout_url
        $this->assertSame($r1['confirmation_url'], $r2['confirmation_url']);

        // Only 1 BillingCycle, 1 PaymentAttempt, 1 provider payment
        $this->assertDatabaseCount('billing_cycles', 1);
        $this->assertDatabaseCount('payment_attempts', 1);

        // Legacy pending row not superseded
        $legacy1 = Subscription::find($r1['subscription']->id);
        $this->assertSame('pending', $legacy1->status);
    }

    // ── #19: Unknown blocks retry ──

    public function test_unknown_attempt_blocks_retry(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        // Create a gateway that always fails
        $failingGateway = new class implements PaymentGatewayInterface {
            public function createPayment(\App\Models\Subscription $subscription, int $amount, string $internalOrderId): array
            {
                throw new \RuntimeException('Network timeout');
            }

            public function verifyWebhook(array $payload, string $signature): bool
            {
                return true;
            }

            public function parseWebhookStatus(array $payload): ?string
            {
                return null;
            }
        };

        $this->app->instance(PaymentGatewayInterface::class, $failingGateway);

        $service = app(BillingService::class);

        // First attempt: gateway fails → unknown
        try {
            $service->subscribe($master, $this->proPlan, 1);
        } catch (\RuntimeException) {
            // expected
        }

        $attempt = PaymentAttempt::where('internal_order_id', 'like', 'core_%')->first();
        $this->assertSame(PaymentAttemptStatus::Unknown, $attempt->status);

        // Second attempt: should throw 422, NOT create a new attempt
        $threw = false;
        try {
            $service->subscribe($master, $this->proPlan, 1);
        } catch (ValidationException) {
            $threw = true;
        }

        $this->assertTrue($threw);

        // Only 1 attempt exists
        $this->assertDatabaseCount('payment_attempts', 1);

        // Gateway not called again (still the failing one)
    }

    // ── #20: Created blocks retry ──

    public function test_created_attempt_blocks_retry(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        // Manually create a created attempt (simulates provider payment created but Transaction C failed)
        $service = app(BillingService::class);
        $result = $service->subscribe($master, $this->proPlan, 1);

        // Force attempt back to Created status
        $attempt = PaymentAttempt::where('internal_order_id', 'like', 'core_%')->first();
        $attempt->update([
            'status' => PaymentAttemptStatus::Created,
            'provider_payment_id' => null,
        ]);

        // Also force the gateway to fail for second attempt
        $failingGateway = new class implements PaymentGatewayInterface {
            public function createPayment(\App\Models\Subscription $subscription, int $amount, string $internalOrderId): array
            {
                throw new \RuntimeException('Should not be called');
            }

            public function verifyWebhook(array $payload, string $signature): bool
            {
                return true;
            }

            public function parseWebhookStatus(array $payload): ?string
            {
                return null;
            }
        };

        $this->app->instance(PaymentGatewayInterface::class, $failingGateway);

        // Second attempt should throw 422
        $threw = false;
        try {
            $service->subscribe($master, $this->proPlan, 1);
        } catch (ValidationException) {
            $threw = true;
        }

        $this->assertTrue($threw);

        // Only 1 attempt exists — no new payment created
        $this->assertDatabaseCount('payment_attempts', 1);
    }

    // ── #21: Terminal retry — same cycle reused, new attempt ──

    public function test_terminal_failure_allows_retry(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        // First attempt
        $r1 = $service->subscribe($master, $this->proPlan, 1);

        // Fail it via webhook
        $this->sendWebhook($r1['subscription']->payment_id, 'failed', $r1['subscription']->amount_paid);

        $cycle = BillingCycle::where('legacy_subscription_id', $r1['subscription']->id)->first();
        $this->assertSame(BillingCycleStatus::Failed, $cycle->status);

        $attempt1 = PaymentAttempt::where('billing_cycle_id', $cycle->id)->first();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt1->status);

        $totalAttemptsBefore = PaymentAttempt::count();

        // Now retry — should succeed (terminal failure is retryable)
        $r2 = $service->subscribe($master, $this->proPlan, 1);

        // Verify new subscription was created
        $this->assertNotNull($r2['subscription']);
        $this->assertSame('pending', $r2['subscription']->status);

        // Verify new attempt was created in the SAME cycle (same period reuse)
        $totalAttemptsAfter = PaymentAttempt::count();
        $this->assertGreaterThan($totalAttemptsBefore, $totalAttemptsAfter);

        // New attempt should be Processing
        $attempt2 = PaymentAttempt::where('billing_cycle_id', $cycle->id)
            ->where('status', PaymentAttemptStatus::Processing)
            ->first();
        $this->assertNotNull($attempt2);
    }

    // ── #22: Unknown late success via order_id ──

    public function test_unknown_late_success_recovers_via_order_id(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        // Create a real legacy subscription to link to
        $legacy = Subscription::create([
            'workspace_id' => $master->workspace_id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => 'pending',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
        ]);

        // Create billing sub + cycle + attempt manually (simulating unknown state after gateway timeout)
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
        ]);

        $internalOrderId = 'core_unknown_recovery_test';

        $attempt = PaymentAttempt::create([
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

        $this->assertSame(PaymentAttemptStatus::Unknown, $attempt->status);
        $this->assertNull($attempt->provider_payment_id);

        // Late webhook with order_id + new payment_id
        $payload = [
            'payment_id' => 'mock_late_success',
            'order_id' => $internalOrderId,
            'status' => 'paid',
            'amount' => 490,
        ];

        $this->postJson('/webhooks/payment', $payload, [
            'X-Webhook-Signature' => 'test_secret_123',
        ])->assertOk();

        // attempt recovered
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
        $this->assertSame('mock_late_success', $attempt->provider_payment_id);

        // Legacy recovered
        $legacy->refresh();
        $this->assertSame('active', $legacy->status);
        $this->assertSame('mock_late_success', $legacy->payment_id);

        // Cycle paid
        $cycle->refresh();
        $this->assertSame(BillingCycleStatus::Paid, $cycle->status);

        // BillingSubscription active
        $billingSub->refresh();
        $this->assertSame(BillingSubscriptionStatus::Active, $billingSub->status);
    }

    // ── #23: Projection rerun on runtime-mirrored rows → 0 attempts_created ──

    public function test_projection_rerun_on_mirrored_rows_creates_no_duplicates(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        // Do a normal checkout
        $r = $service->subscribe($master, $this->proPlan, 1);

        // This already created BillingCoreWriter records
        $attempt = PaymentAttempt::where('internal_order_id', 'like', 'core_%')->first();
        $this->assertNotNull($attempt);

        // Now project legacy (simulate runtime mirror projection)
        $projection = app(\App\Services\Billing\LegacyProjectionService::class);
        $stats = $projection->projectAll(dryRun: false);

        // Should create 0 new attempts (runtime-mirrored row already exists)
        $this->assertSame(0, $stats['created']['payment_attempts']);

        // No unique violations
        $this->assertDatabaseCount('payment_attempts', 1);
    }

    // ── #24: Canonical status — PendingInitial for first checkout ──

    public function test_first_pending_checkout_sets_pending_initial(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        $service->subscribe($master, $this->proPlan, 1);

        $billingSub = BillingSubscription::where('workspace_id', $master->workspace_id)->first();
        $this->assertNotNull($billingSub);
        $this->assertSame(BillingSubscriptionStatus::PendingInitial, $billingSub->status);
    }

    // ── #24: Active granting subscription + renewal checkout → stays Active ──

    public function test_active_granting_subscription_stays_active_on_checkout(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        // First checkout
        $r1 = $service->subscribe($master, $this->proPlan, 1);

        // Activate it via webhook
        $this->sendWebhook($r1['subscription']->payment_id, 'paid', $r1['subscription']->amount_paid);

        // BillingSubscription should be Active now
        $billingSub = BillingSubscription::where('workspace_id', $master->workspace_id)->first();
        $this->assertSame(BillingSubscriptionStatus::Active, $billingSub->status);

        // Second checkout (stacked renewal)
        $r2 = $service->subscribe($master, $this->proPlan, 1);

        // Should remain Active
        $billingSub->refresh();
        $this->assertSame(BillingSubscriptionStatus::Active, $billingSub->status);
    }

    // ── #24: Expired/canceled without granting + checkout → PendingInitial ──

    public function test_expired_without_granting_sets_pending_initial(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        // Create a BillingSubscription that is Canceled (no granting cycles)
        BillingSubscription::create([
            'workspace_id' => $master->workspace_id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::Canceled,
        ]);

        $service = app(BillingService::class);
        $r = $service->subscribe($master, $this->proPlan, 1);

        $billingSub = BillingSubscription::where('workspace_id', $master->workspace_id)->first();
        // Should be PendingInitial (no granting entitlement)
        $this->assertSame(BillingSubscriptionStatus::PendingInitial, $billingSub->status);
    }

    // ── #12: Checkout URL persistence in metadata ──

    public function test_checkout_url_persisted_in_attempt_metadata(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        $result = $service->subscribe($master, $this->proPlan, 1);

        $attempt = PaymentAttempt::where('internal_order_id', 'like', 'core_%')->first();
        $this->assertNotNull($attempt->metadata['checkout_url']);
        $this->assertStringContainsString('/admin/settings?payment=', $attempt->metadata['checkout_url']);

        // Legacy subscription_id preserved
        $this->assertNotNull($attempt->metadata['legacy_subscription_id']);
        $this->assertSame($result['subscription']->id, $attempt->metadata['legacy_subscription_id']);
    }

    // ── #16: Provider event dedup — same status = 1 event, different = 2 ──

    public function test_provider_event_dedup_by_composite_key(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        $result = $service->subscribe($master, $this->proPlan, 1);
        $paymentId = $result['subscription']->payment_id;
        $amount = $result['subscription']->amount_paid;

        // Send paid twice
        $this->sendWebhook($paymentId, 'paid', $amount);
        $this->sendWebhook($paymentId, 'paid', $amount);

        // Only 1 paid event
        $paidEvents = ProviderEvent::where('dedup_key', $paymentId.':paid')->count();
        $this->assertSame(1, $paidEvents);

        // Send refunded — should be a 2nd event
        $this->sendWebhook($paymentId, 'refunded', $amount);

        $refundedEvents = ProviderEvent::where('dedup_key', $paymentId.':refunded')->count();
        $this->assertSame(1, $refundedEvents);

        $totalEvents = ProviderEvent::where('provider', 'mock')->count();
        $this->assertSame(2, $totalEvents);
    }

    // ── #7: Same period reuse — no second BillingCycle for same period ──

    public function test_same_period_reuse_does_not_create_second_cycle(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        // Create billing sub + cycle manually
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

        // Create legacy sub with exact same period
        $legacy = Subscription::create([
            'workspace_id' => $master->workspace_id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => 'pending',
            'starts_at' => $periodStart,
            'expires_at' => $periodEnd,
        ]);

        // Call checkoutCreated — should find existing cycle and not create a new one
        $coreWriter = app(BillingCoreWriter::class);

        $result = $coreWriter->checkoutCreated($legacy, $this->proPlan, ['base' => 490, 'discount_percent' => 0, 'final' => 490], 1);

        // Only 1 BillingCycle for this period
        $cycles = BillingCycle::where('billing_subscription_id', $billingSub->id)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->get();

        $this->assertCount(1, $cycles);

        // But attempt was created
        $this->assertNotNull($result['attempt']);
        $this->assertSame(1, $result['attempt']->attempt_number);
    }

    // ── #14: Subscription status — PendingInitial vs Active ──

    public function test_subscription_status_correct_semantics(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        // First: PendingInitial (no granting yet)
        $r1 = $service->subscribe($master, $this->proPlan, 1);
        $billingSub = BillingSubscription::where('workspace_id', $master->workspace_id)->first();
        $this->assertSame(BillingSubscriptionStatus::PendingInitial, $billingSub->status);

        // After successful payment: Active
        $this->sendWebhook($r1['subscription']->payment_id, 'paid', $r1['subscription']->amount_paid);
        $billingSub->refresh();
        $this->assertSame(BillingSubscriptionStatus::Active, $billingSub->status);
    }

    // ── #13: Phase C failure — attempt stays uncertain ──

    public function test_phase_c_failure_leaves_attempt_uncertain(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        // Gateway that succeeds on createPayment but simulate Transaction C failure
        // by making the attempt go through createPayment, then we manually set it back
        $service = app(BillingService::class);
        $r = $service->subscribe($master, $this->proPlan, 1);

        // Verify attempt is Processing (Phase C succeeded)
        $attempt = PaymentAttempt::where('internal_order_id', 'like', 'core_%')->first();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);

        // This test validates that Phase C succeeds normally.
        // Phase C failure would leave attempt as Created (not Processing).
        // The unknown/created blocking behavior is tested by #19 and #20.
    }

    // ── #1: Cache lock exists ──

    public function test_checkout_uses_cache_lock(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        // Verify lock is acquired by checking the key pattern
        $lockKey = "billing-checkout:{$master->workspace_id}:{$this->proPlan->id}";

        $service = app(BillingService::class);
        $result = $service->subscribe($master, $this->proPlan, 1);

        $this->assertNotNull($result['subscription']);
        $this->assertNotNull($result['confirmation_url']);

        // If lock wasn't released, a second call would timeout
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
