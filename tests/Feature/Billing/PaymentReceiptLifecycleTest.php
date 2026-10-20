<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentEnvironment;
use App\Enums\PaymentReceiptStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\PaymentAttempt;
use App\Models\PaymentReceipt;
use App\Models\PlanPrice;
use App\Models\SuperAdminAuditLog;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Payment\DTOs\ProviderStatusUpdate;
use App\Services\Payment\PaymentReceiptService;
use App\Services\Payment\PaymentTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PaymentReceiptLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;

    private Workspace $workspace;

    private PaymentTransitionService $transitionService;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.legacy_mock_webhook_secret' => 'test_secret_123',
            'billing.gateways.tbank.environment' => 'live',
            'billing.receipts.seller_inn' => '5248061993',
        ]);

        $master = User::factory()->master()->create();
        $this->workspace = Workspace::create([
            'name' => 'ws-'.$master->id,
            'owner_id' => $master->id,
        ]);
        $this->workspace->ensureSlug();
        $master->update(['workspace_id' => $this->workspace->id]);

        $this->proPlan = TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'max_appointments_per_month' => null,
            'max_masters' => 1,
            'features' => ['unlimited_appointments'],
            'is_active' => true,
        ]);

        PlanPrice::create([
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'base_amount' => 490,
            'discount_percent' => 0,
            'final_amount' => 490,
            'currency' => 'RUB',
            'version' => 1,
            'valid_from' => now(),
            'is_active' => true,
        ]);

        $this->transitionService = app(PaymentTransitionService::class);
    }

    // ── confirmed_at ──

    public function test_first_success_sets_confirmed_at_once(): void
    {
        $attempt = $this->liveTbankAttempt();

        $this->assertTrue($this->succeed($attempt)['success']);
        $attempt->refresh();

        $this->assertNotNull($attempt->confirmed_at);
        $firstConfirmed = $attempt->confirmed_at;

        // Repeated success is a state-machine no-op — confirmed_at untouched.
        $this->assertTrue($this->succeed($attempt)['success']);
        $attempt->refresh();
        $this->assertTrue($firstConfirmed->equalTo($attempt->confirmed_at));
    }

    public function test_refund_does_not_change_confirmed_at(): void
    {
        $attempt = $this->liveTbankAttempt();
        $this->succeed($attempt);
        $attempt->refresh();
        $confirmed = $attempt->confirmed_at;

        $this->assertTrue($this->refund($attempt)['success']);
        $attempt->refresh();

        $this->assertSame(PaymentAttemptStatus::Refunded, $attempt->status);
        $this->assertTrue($confirmed->equalTo($attempt->confirmed_at));
    }

    public function test_repeat_success_after_refund_keeps_confirmed_at_and_review_flag(): void
    {
        $attempt = $this->liveTbankAttempt();
        $this->succeed($attempt);
        $this->refund($attempt);
        $attempt->refresh();
        $confirmed = $attempt->confirmed_at;
        $receipt = PaymentReceipt::where('payment_attempt_id', $attempt->id)->firstOrFail();
        $this->assertTrue($receipt->refund_review_required);

        // refunded → succeeded is not in the state machine; nothing changes.
        $result = $this->succeed($attempt);
        $this->assertFalse($result['success'] ?? false);

        $attempt->refresh();
        $receipt->refresh();
        $this->assertTrue($confirmed->equalTo($attempt->confirmed_at));
        $this->assertTrue($receipt->refund_review_required);
    }

    // ── Receipt creation conditions ──

    public function test_live_tbank_success_creates_awaiting_manual_receipt(): void
    {
        $attempt = $this->liveTbankAttempt();
        $this->succeed($attempt);

        $receipt = PaymentReceipt::where('payment_attempt_id', $attempt->id)->first();
        $this->assertNotNull($receipt);
        $this->assertSame(PaymentReceiptStatus::AwaitingManual, $receipt->status);
        $this->assertNull($receipt->receipt_url);
    }

    public function test_demo_tbank_success_creates_no_receipt(): void
    {
        $attempt = $this->liveTbankAttempt(paymentEnv: PaymentEnvironment::Demo);
        $this->succeed($attempt);

        $this->assertNull(PaymentReceipt::where('payment_attempt_id', $attempt->id)->first());
    }

    public function test_null_env_success_creates_no_receipt(): void
    {
        $attempt = $this->liveTbankAttempt(paymentEnv: null);
        $this->succeed($attempt);

        $this->assertNull(PaymentReceipt::where('payment_attempt_id', $attempt->id)->first());
    }

    public function test_mock_provider_creates_no_receipt_even_with_live_env(): void
    {
        $attempt = $this->liveTbankAttempt(provider: 'mock', paymentEnv: PaymentEnvironment::Live);
        $this->succeed($attempt, provider: 'mock');

        $this->assertNull(PaymentReceipt::where('payment_attempt_id', $attempt->id)->first());
    }

    public function test_admin_grant_cycle_creates_no_receipt(): void
    {
        $attempt = $this->liveTbankAttempt(origin: BillingCycleOrigin::AdminGrant);
        $this->succeed($attempt);

        $this->assertNull(PaymentReceipt::where('payment_attempt_id', $attempt->id)->first());
    }

    public function test_zero_amount_creates_no_receipt(): void
    {
        $attempt = $this->liveTbankAttempt(amount: 0);
        // Amount validation would reject a mismatched success; use matching zero.
        $this->succeed($attempt, amount: 0);

        $this->assertNull(PaymentReceipt::where('payment_attempt_id', $attempt->id)->first());
    }

    public function test_renewal_origin_success_creates_receipt(): void
    {
        $attempt = $this->liveTbankAttempt(origin: BillingCycleOrigin::Renewal);
        $this->succeed($attempt);

        $this->assertNotNull(PaymentReceipt::where('payment_attempt_id', $attempt->id)->first());
    }

    public function test_late_success_from_failed_terminal_still_queues_receipt(): void
    {
        $attempt = $this->liveTbankAttempt(status: PaymentAttemptStatus::FailedTerminal);

        $this->assertTrue($this->succeed($attempt)['success']);

        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->refresh()->status);
        $this->assertNotNull(PaymentReceipt::where('payment_attempt_id', $attempt->id)->first());
    }

    public function test_one_receipt_per_attempt_on_repeated_success_flow(): void
    {
        $attempt = $this->liveTbankAttempt();
        $this->succeed($attempt);

        // Force a second Succeeded through the service (same provider event
        // id would dedup; use a distinct event id to reach the state machine).
        $update = new ProviderStatusUpdate(
            provider: 'tbank',
            providerEventId: 'evt-second-'.bin2hex(random_bytes(4)),
            providerPaymentId: $attempt->provider_payment_id,
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: $attempt->amount,
            currency: 'RUB',
        );
        $this->transitionService->transition($update);

        $this->assertSame(1, PaymentReceipt::where('payment_attempt_id', $attempt->id)->count());
    }

    public function test_transaction_rollback_leaves_no_receipt_row(): void
    {
        $attempt = $this->liveTbankAttempt();

        try {
            DB::transaction(function () use ($attempt) {
                $this->succeed($attempt);

                $this->assertNotNull(
                    PaymentReceipt::where('payment_attempt_id', $attempt->id)->first()
                );

                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNull(PaymentReceipt::where('payment_attempt_id', $attempt->id)->first());
    }

    // ── Refund marking ──

    public function test_refund_marks_receipt_for_manual_review_and_keeps_url(): void
    {
        $attempt = $this->liveTbankAttempt();
        $this->succeed($attempt);

        $receipt = PaymentReceipt::where('payment_attempt_id', $attempt->id)->firstOrFail();
        $url = 'https://lknpd.nalog.ru/api/v1/receipt/5248061993/abc123/print';
        $receipt->update([
            'status' => PaymentReceiptStatus::Attached,
            'receipt_url' => $url,
            'attached_at' => now(),
        ]);

        $this->assertTrue($this->refund($attempt)['success']);

        $receipt->refresh();
        $this->assertTrue($receipt->refund_review_required);
        $this->assertSame($url, $receipt->receipt_url);
        $this->assertSame(PaymentReceiptStatus::Attached, $receipt->status);
    }

    public function test_repeat_success_does_not_clear_refund_review_flag(): void
    {
        $attempt = $this->liveTbankAttempt();
        $this->succeed($attempt);
        $this->refund($attempt);

        $receipt = PaymentReceipt::where('payment_attempt_id', $attempt->id)->firstOrFail();
        $this->assertTrue($receipt->refund_review_required);

        // Duplicate refund event — flag stays, no error.
        $this->assertTrue($this->refund($attempt)['success']);
        $this->assertTrue($receipt->refresh()->refund_review_required);
    }

    public function test_repeat_refund_after_review_closed_does_not_reopen_review(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => true]);
        $attempt = $this->liveTbankAttempt();
        $this->succeed($attempt);
        $this->refund($attempt);

        $receipt = PaymentReceipt::where('payment_attempt_id', $attempt->id)->firstOrFail();
        $url = 'https://lknpd.nalog.ru/api/v1/receipt/5248061993/abc123/print';
        $receipt->update([
            'status' => PaymentReceiptStatus::Attached,
            'receipt_url' => $url,
            'refund_review_required' => false,
            'refund_reviewed_by' => $admin->id,
            'refund_reviewed_at' => now()->subHour(),
        ]);
        $reviewedAt = $receipt->refresh()->refund_reviewed_at;

        // The same refund re-reported with a new payload fingerprint
        // (different provider_event_id) passes event dedup but is the
        // same refunded → refunded transition — the closed review must
        // stay closed and the link must survive.
        $this->assertTrue($this->refund($attempt)['success']);

        $receipt->refresh();
        $this->assertFalse($receipt->refund_review_required);
        $this->assertTrue($reviewedAt->equalTo($receipt->refund_reviewed_at));
        $this->assertSame($url, $receipt->receipt_url);
        $this->assertSame(PaymentReceiptStatus::Attached, $receipt->status);
    }

    public function test_partially_refunded_is_not_in_state_machine_today(): void
    {
        $attempt = $this->liveTbankAttempt();
        $this->succeed($attempt);

        $result = $this->transitionService->transition(new ProviderStatusUpdate(
            provider: 'tbank',
            providerEventId: 'evt-'.bin2hex(random_bytes(8)),
            providerPaymentId: $attempt->provider_payment_id,
            normalizedStatus: PaymentAttemptStatus::PartiallyRefunded,
            amount: 100,
            currency: 'RUB',
        ));

        // PartiallyRefunded is not an allowed target today; if the state
        // machine ever admits it, markRefundReview must run on the real
        // transition (status actually changes into a refund status).
        $this->assertFalse($result['success'] ?? false);
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->refresh()->status);
        $this->assertFalse(
            PaymentReceipt::where('payment_attempt_id', $attempt->id)->firstOrFail()->refund_review_required
        );
    }

    public function test_review_stays_closed_across_repeat_refund_and_second_review_press(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => true]);
        $attempt = $this->liveTbankAttempt();
        $this->succeed($attempt);
        $this->refund($attempt);

        $receipt = PaymentReceipt::where('payment_attempt_id', $attempt->id)->firstOrFail();
        $service = app(PaymentReceiptService::class);
        $service->markRefundReviewed($receipt, $admin);
        $reviewedAt = $receipt->refresh()->refund_reviewed_at;
        $auditCount = SuperAdminAuditLog::where('action', 'receipt.refund_reviewed')->count();

        // Second «Проверено» press — no-op: date, admin, audit untouched.
        $service->markRefundReviewed($receipt, $admin);
        $receipt->refresh();
        $this->assertFalse($receipt->refund_review_required);
        $this->assertTrue($reviewedAt->equalTo($receipt->refund_reviewed_at));
        $this->assertSame($auditCount, SuperAdminAuditLog::where('action', 'receipt.refund_reviewed')->count());

        // Repeat refund event (new fingerprint) — closed review stays closed.
        $this->assertTrue($this->refund($attempt)['success']);
        $receipt->refresh();
        $this->assertFalse($receipt->refund_review_required);
        $this->assertTrue($reviewedAt->equalTo($receipt->refund_reviewed_at));
        $this->assertSame($auditCount, SuperAdminAuditLog::where('action', 'receipt.refund_reviewed')->count());
    }

    // ── No network ──

    public function test_receipt_flow_makes_no_http_calls(): void
    {
        Http::preventStrayRequests();

        $attempt = $this->liveTbankAttempt();
        $this->succeed($attempt);
        $this->refund($attempt);

        $this->assertSame(1, PaymentReceipt::where('payment_attempt_id', $attempt->id)->count());
    }

    public function test_receipt_creation_does_not_send_channel_notifications(): void
    {
        Notification::fake();

        $attempt = $this->liveTbankAttempt();
        $this->succeed($attempt);

        Notification::assertNothingSent();
        $this->assertNotNull(PaymentReceipt::where('payment_attempt_id', $attempt->id)->first());
    }

    // ── Helpers ──

    private function succeed(PaymentAttempt $attempt, ?string $provider = null, ?int $amount = null): array
    {
        return $this->transitionService->transition(new ProviderStatusUpdate(
            provider: $provider ?? $attempt->provider,
            providerEventId: 'evt-'.bin2hex(random_bytes(8)),
            providerPaymentId: $attempt->provider_payment_id,
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: $amount ?? $attempt->amount,
            currency: 'RUB',
        ));
    }

    private function refund(PaymentAttempt $attempt): array
    {
        return $this->transitionService->transition(new ProviderStatusUpdate(
            provider: $attempt->provider,
            providerEventId: 'evt-'.bin2hex(random_bytes(8)),
            providerPaymentId: $attempt->provider_payment_id,
            normalizedStatus: PaymentAttemptStatus::Refunded,
            amount: $attempt->amount,
            currency: 'RUB',
        ));
    }

    private function liveTbankAttempt(
        ?PaymentEnvironment $paymentEnv = PaymentEnvironment::Live,
        string $provider = 'tbank',
        int $amount = 490,
        BillingCycleOrigin $origin = BillingCycleOrigin::Payment,
        PaymentAttemptStatus $status = PaymentAttemptStatus::Processing,
    ): PaymentAttempt {
        $billingSub = BillingSubscription::create([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::PendingInitial,
        ]);

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now(),
            'period_end' => now()->addMonth(),
            'status' => BillingCycleStatus::Pending,
            'amount' => $amount,
            'currency' => 'RUB',
            'origin' => $origin,
        ]);

        return PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => $provider,
            'payment_env' => $paymentEnv,
            'attempt_number' => 1,
            'amount' => $amount,
            'currency' => 'RUB',
            'internal_order_id' => 'core_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => 'prov_'.bin2hex(random_bytes(8)),
            'status' => $status,
            'initiated_at' => now(),
        ]);
    }
}
