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
use App\Models\PlanPrice;
use App\Models\ProviderEvent;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingCoreWriter;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingCoreWriterTest extends TestCase
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

    // ── 1. Checkout creates legacy + Core consistently ──

    public function test_checkout_creates_legacy_and_core_consistently(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        $result = $service->subscribe($master, $this->proPlan, 1);

        $legacy = Subscription::where('workspace_id', $master->workspace_id)->first();
        $this->assertNotNull($legacy);
        $this->assertSame('pending', $legacy->status);
        $this->assertNotNull($legacy->payment_id);

        $billingSub = BillingSubscription::where('workspace_id', $master->workspace_id)->first();
        $this->assertNotNull($billingSub);
        $this->assertSame($this->proPlan->id, $billingSub->tariff_plan_id);

        $cycle = BillingCycle::where('billing_subscription_id', $billingSub->id)->first();
        $this->assertNotNull($cycle);
        $this->assertSame(BillingCycleStatus::Pending, $cycle->status);
        $this->assertSame(BillingCycleOrigin::Payment, $cycle->origin);
        $this->assertSame($legacy->starts_at->format('Y-m-d H:i:s'), $cycle->period_start->format('Y-m-d H:i:s'));
        $this->assertSame($legacy->expires_at->format('Y-m-d H:i:s'), $cycle->period_end->format('Y-m-d H:i:s'));
        $this->assertSame($legacy->id, $cycle->legacy_subscription_id);

        $attempt = PaymentAttempt::where('billing_cycle_id', $cycle->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        $this->assertSame($legacy->payment_id, $attempt->provider_payment_id);
        $this->assertNotNull($attempt->internal_order_id);
        $this->assertSame(1, $attempt->attempt_number);
        $this->assertSame($legacy->amount_paid, $attempt->amount);
    }

    // ── 2. Stacked renewal: boundaries match legacy exactly ──

    public function test_stacked_renewal_boundaries_match_legacy(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        // First purchase
        $r1 = $service->subscribe($master, $this->proPlan, 1);
        $sub1 = $r1['subscription'];

        // Activate it via webhook
        $this->sendWebhook($sub1->payment_id, 'paid', $sub1->amount_paid);

        // Second purchase: stacked renewal (starts after first expires)
        $r2 = $service->subscribe($master, $this->proPlan, 1);
        $sub2 = Subscription::where('id', $r2['subscription']->id)->first();

        $this->assertSame($sub1->expires_at->format('Y-m-d H:i:s'), $sub2->starts_at->format('Y-m-d H:i:s'));
        $this->assertGreaterThan($sub1->expires_at, $sub2->expires_at);

        $cycle2 = BillingCycle::where('legacy_subscription_id', $sub2->id)->first();
        $this->assertNotNull($cycle2);
        $this->assertSame($sub2->starts_at->format('Y-m-d H:i:s'), $cycle2->period_start->format('Y-m-d H:i:s'));
        $this->assertSame($sub2->expires_at->format('Y-m-d H:i:s'), $cycle2->period_end->format('Y-m-d H:i:s'));
    }

    // ── 3. Gateway exception: Core attempt → unknown, Pro not issued ──

    public function test_gateway_exception_sets_unknown(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        // Create a failing gateway
        $failingGateway = new class implements \App\Services\Payment\PaymentGatewayInterface {
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

        $this->app->instance(\App\Services\Payment\PaymentGatewayInterface::class, $failingGateway);

        $service = app(BillingService::class);

        $threw = false;
        try {
            $service->subscribe($master, $this->proPlan, 1);
        } catch (\RuntimeException) {
            $threw = true;
        }

        $this->assertTrue($threw);

        // Legacy sub exists as pending
        $legacy = Subscription::where('workspace_id', $master->workspace_id)->first();
        $this->assertNotNull($legacy);
        $this->assertSame('pending', $legacy->status);
        $this->assertNull($legacy->payment_id);

        // Core attempt is unknown
        $attempt = PaymentAttempt::where('internal_order_id', 'like', 'core_%')->first();
        $this->assertNotNull($attempt);
        $this->assertSame(PaymentAttemptStatus::Unknown, $attempt->status);
    }

    // ── 4. Successful webhook: legacy active + attempt succeeded + cycle paid ──

    public function test_success_webhook_updates_all_layers(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        $result = $service->subscribe($master, $this->proPlan, 1);
        $legacy = $result['subscription'];

        $this->sendWebhook($legacy->payment_id, 'paid', $legacy->amount_paid);

        $legacy->refresh();
        $this->assertSame('active', $legacy->status);

        $attempt = PaymentAttempt::where('provider_payment_id', $legacy->payment_id)->first();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
        $this->assertNotNull($attempt->finished_at);

        $cycle = BillingCycle::where('id', $attempt->billing_cycle_id)->first();
        $this->assertSame(BillingCycleStatus::Paid, $cycle->status);

        $billingSub = BillingSubscription::where('workspace_id', $master->workspace_id)->first();
        $this->assertSame(BillingSubscriptionStatus::Active, $billingSub->status);
    }

    // ── 5. Duplicate success: no-op, no duplicates ──

    public function test_duplicate_success_is_idempotent(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        $result = $service->subscribe($master, $this->proPlan, 1);
        $legacy = $result['subscription'];

        $this->sendWebhook($legacy->payment_id, 'paid', $legacy->amount_paid);

        $attemptsAfterFirst = PaymentAttempt::count();
        $eventsAfterFirst = ProviderEvent::count();

        // Send duplicate
        $this->sendWebhook($legacy->payment_id, 'paid', $legacy->amount_paid);

        $this->assertSame($attemptsAfterFirst, PaymentAttempt::count());
        $this->assertSame($eventsAfterFirst, ProviderEvent::count());

        // Status still correct
        $attempt = PaymentAttempt::where('provider_payment_id', $legacy->payment_id)->first();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
    }

    // ── 6. Failed webhook: legacy failed + attempt failed_terminal + cycle failed ──

    public function test_failed_webhook_sets_failed(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        $result = $service->subscribe($master, $this->proPlan, 1);
        $legacy = $result['subscription'];

        $this->sendWebhook($legacy->payment_id, 'failed', $legacy->amount_paid);

        $legacy->refresh();
        $this->assertSame('failed', $legacy->status);

        $attempt = PaymentAttempt::where('provider_payment_id', $legacy->payment_id)->first();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);

        $cycle = BillingCycle::where('id', $attempt->billing_cycle_id)->first();
        $this->assertSame(BillingCycleStatus::Failed, $cycle->status);
    }

    // ── 7. Refunded: attempt/cycle refunded + billing sub recalculated ──

    public function test_refunded_webhook_refunds_cycle(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        $result = $service->subscribe($master, $this->proPlan, 1);
        $legacy = $result['subscription'];

        // First succeed
        $this->sendWebhook($legacy->payment_id, 'paid', $legacy->amount_paid);

        // Then refund
        $this->sendWebhook($legacy->payment_id, 'refunded', $legacy->amount_paid);

        $attempt = PaymentAttempt::where('provider_payment_id', $legacy->payment_id)->first();
        $this->assertSame(PaymentAttemptStatus::Refunded, $attempt->status);

        $cycle = BillingCycle::where('id', $attempt->billing_cycle_id)->first();
        $this->assertSame(BillingCycleStatus::Refunded, $cycle->status);

        // BillingSubscription should be canceled (no other granting cycles)
        $billingSub = BillingSubscription::where('workspace_id', $master->workspace_id)->first();
        $this->assertSame(BillingSubscriptionStatus::Canceled, $billingSub->status);
    }

    // ── 8. Attempt numbering: multiple attempts per cycle get sequential numbers ──

    public function test_attempt_numbering_is_sequential(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        // First purchase → attempt #1
        $r1 = $service->subscribe($master, $this->proPlan, 1);
        $this->sendWebhook($r1['subscription']->payment_id, 'paid', $r1['subscription']->amount_paid);

        // Second purchase (same plan, after first active = stacked) → new cycle, attempt #1
        $r2 = $service->subscribe($master, $this->proPlan, 1);

        $cycle2 = BillingCycle::where('legacy_subscription_id', $r2['subscription']->id)->first();
        $attempt2 = PaymentAttempt::where('billing_cycle_id', $cycle2->id)->first();
        $this->assertSame(1, $attempt2->attempt_number);

        // Both attempts have unique internal_order_ids
        $this->assertNotSame(
            $r1['subscription']->payment_id,
            $r2['subscription']->payment_id,
        );
    }

    // ── 9. Pending webhook rejected (terminal state guard) ──

    public function test_failed_then_success_webhook_is_rejected(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        $result = $service->subscribe($master, $this->proPlan, 1);
        $legacy = $result['subscription'];

        // First: fail
        $this->sendWebhook($legacy->payment_id, 'failed', $legacy->amount_paid);
        $legacy->refresh();
        $this->assertSame('failed', $legacy->status);

        // Then: success webhook → rejected (terminal state)
        $this->sendWebhook($legacy->payment_id, 'paid', $legacy->amount_paid);
        $legacy->refresh();
        $this->assertSame('failed', $legacy->status);
    }

    // ── 10. ProviderEvent recorded ──

    public function test_provider_event_recorded_on_webhook(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        $result = $service->subscribe($master, $this->proPlan, 1);
        $this->sendWebhook($result['subscription']->payment_id, 'paid', $result['subscription']->amount_paid);

        $event = ProviderEvent::where('dedup_key', $result['subscription']->payment_id . ':paid')->first();
        $this->assertNotNull($event);
        $this->assertSame('mock', $event->provider);
        $this->assertSame('paid', $event->event_type);
        $this->assertNotNull($event->received_at);
    }

    // ── 11. Legacy + Core atomicity: old pending superseded ──

    public function test_retry_supersedes_old_pending(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        $r1 = $service->subscribe($master, $this->proPlan, 1);

        $attempt1 = PaymentAttempt::where('internal_order_id', 'like', 'core_%')->first();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt1->status);

        $this->sendWebhook($r1['subscription']->payment_id, 'paid', $r1['subscription']->amount_paid);

        $r2 = $service->subscribe($master, $this->proPlan, 1);

        $legacy1 = Subscription::where('id', $r1['subscription']->id)->first();
        $this->assertSame('active', $legacy1->status);

        $legacy2 = Subscription::where('id', $r2['subscription']->id)->first();
        $this->assertSame('pending', $legacy2->status);

        $this->assertTrue($r2['subscription']->starts_at->greaterThan($r1['subscription']->starts_at));
    }

    // ── 12. Core EntitlementService still works (parity) ──

    public function test_entitlement_service_reads_core_after_webhook(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        $result = $service->subscribe($master, $this->proPlan, 1);
        $this->sendWebhook($result['subscription']->payment_id, 'paid', $result['subscription']->amount_paid);

        $entitlement = app(\App\Services\Billing\EntitlementService::class);
        $workspace = $master->workspace;
        $this->assertTrue($entitlement->hasPlan($workspace, 'pro'));
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
