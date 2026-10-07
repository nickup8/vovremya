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
use App\Services\Billing\EntitlementService;
use App\Services\Payment\DTOs\ProviderStatusUpdate;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\PaymentReconciliationService;
use App\Services\Payment\PaymentTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;
    private Workspace $workspace;
    private User $master;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.legacy_mock_webhook_secret' => 'test_secret_123']);
        config(['billing.reconciliation' => [
            'batch_size' => 50,
            'fresh_grace_seconds' => 0, // No grace for tests
            'backoff' => [60, 120, 300],
            'max_age_with_provider_id' => 86400,
            'max_age_without_provider_id' => 1800,
        ]]);

        $this->master = User::factory()->master()->create();
        $this->workspace = Workspace::create([
            'name' => 'ws-'.$this->master->id,
            'owner_id' => $this->master->id,
        ]);
        $this->workspace->ensureSlug();
        $this->master->update(['workspace_id' => $this->workspace->id]);

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
    }

    public function test_max_age_with_provider_id_failed_terminal(): void
    {
        config(['billing.reconciliation.max_age_with_provider_id' => 0]); // Immediate

        $attempt = $this->createAttempt(PaymentAttemptStatus::Unknown, 490);

        $reconciliationService = app(PaymentReconciliationService::class);
        $result = $reconciliationService->reconcile();

        $this->assertGreaterThan(0, $result['processed']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('reconciliation_timeout', $attempt->failure_category);
    }

    public function test_max_age_without_provider_id_failed_terminal(): void
    {
        config(['billing.reconciliation.max_age_without_provider_id' => 0]); // Immediate

        $attempt = $this->createAttemptWithoutProviderPaymentId(PaymentAttemptStatus::Unknown, 490);

        $reconciliationService = app(PaymentReconciliationService::class);
        $result = $reconciliationService->reconcile();

        $this->assertGreaterThan(0, $result['processed']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('reconciliation_timeout', $attempt->failure_category);
    }

    public function test_fresh_attempt_not_polled(): void
    {
        config(['billing.reconciliation.fresh_grace_seconds' => 3600]); // 1 hour

        $attempt = $this->createAttempt(PaymentAttemptStatus::Unknown, 490);

        $reconciliationService = app(PaymentReconciliationService::class);
        $result = $reconciliationService->reconcile();

        $this->assertSame(0, $result['processed']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Unknown, $attempt->status);
    }

    public function test_failed_terminal_no_longer_blocks_new_checkout(): void
    {
        $attempt = $this->createAttempt(PaymentAttemptStatus::FailedTerminal, 490);

        // Check that in-flight lookup doesn't find failed_terminal
        $inFlight = app(\App\Services\Billing\BillingCoreWriter::class)
            ->findExistingInFlightAttempt($this->workspace->id, $this->proPlan->id);

        $this->assertNull($inFlight);
    }

    public function test_created_attempt_age_released(): void
    {
        config(['billing.reconciliation.max_age_with_provider_id' => 0]); // Immediate

        $attempt = $this->createAttempt(PaymentAttemptStatus::Created, 490);

        $reconciliationService = app(PaymentReconciliationService::class);
        $result = $reconciliationService->reconcile();

        $this->assertGreaterThan(0, $result['processed']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('reconciliation_timeout', $attempt->failure_category);
    }

    public function test_processing_attempt_age_released(): void
    {
        config(['billing.reconciliation.max_age_with_provider_id' => 0]); // Immediate

        $attempt = $this->createAttempt(PaymentAttemptStatus::Processing, 490);

        $reconciliationService = app(PaymentReconciliationService::class);
        $result = $reconciliationService->reconcile();

        $this->assertGreaterThan(0, $result['processed']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('reconciliation_timeout', $attempt->failure_category);
    }

    // ── Reconciliation success schedules next_charge_at ──

    public function test_reconciliation_success_schedules_next_charge(): void
    {
        $sub = BillingSubscription::create([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::Active,
            'renewal_period_months' => 1,
            'auto_renew_consent_at' => now(),
            'auto_renew_consent_version' => config('billing.recurring_terms_version'),
            'cancel_at_period_end' => false,
        ]);

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now(),
            'period_end' => now()->addMonth(),
            'status' => BillingCycleStatus::Pending,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => 'payment',
        ]);

        $attempt = new PaymentAttempt([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'tbank',
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'core_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => 'tbank_recon_'.bin2hex(random_bytes(4)),
            'status' => PaymentAttemptStatus::Processing,
            'initiated_at' => now()->subHour(),
        ]);
        $attempt->created_at = now()->subHour();
        $attempt->save();

        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => true,
                'PaymentId' => $attempt->provider_payment_id,
                'OrderId' => $attempt->internal_order_id,
                'Status' => 'CONFIRMED',
                'Amount' => 49000,
            ], 200),
        ]);

        $result = app(PaymentReconciliationService::class)->reconcile();

        $this->assertGreaterThan(0, $result['processed']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);

        $sub->refresh();
        $this->assertNotNull($sub->current_period_end);
        $this->assertNotNull($sub->next_charge_at);
        $this->assertTrue($sub->next_charge_at->equalTo($sub->current_period_end));
    }

    // ── last_polled_at backoff parsing ──

    public function test_iso8601_last_polled_inside_backoff_skips_provider(): void
    {
        $attempt = $this->createAttempt(PaymentAttemptStatus::Processing, 490, 'tbank');
        // poll_count=1 → backoff[0] = 60s; polled 30s ago → still inside backoff
        $attempt->update(['metadata' => [
            'poll_count' => 1,
            'last_polled_at' => now()->subSeconds(30)->toIso8601String(),
        ]]);

        Http::fake();

        $result = app(PaymentReconciliationService::class)->reconcile();

        $this->assertSame(0, $result['errors']);
        Http::assertNothingSent();

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        $this->assertSame(1, $attempt->metadata['poll_count']);
    }

    public function test_iso8601_last_polled_after_backoff_polls_provider(): void
    {
        $attempt = $this->createAttempt(PaymentAttemptStatus::Processing, 490, 'tbank');
        // poll_count=1 → backoff[0] = 60s; polled 120s ago → due
        $attempt->update(['metadata' => [
            'poll_count' => 1,
            'last_polled_at' => now()->subSeconds(120)->toIso8601String(),
        ]]);

        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response(['Success' => false], 200),
        ]);

        $result = app(PaymentReconciliationService::class)->reconcile();

        $this->assertSame(0, $result['errors']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/GetState'));

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        $this->assertSame(2, $attempt->metadata['poll_count']);
    }

    public function test_malformed_last_polled_at_does_not_crash_command(): void
    {
        $attempt = $this->createAttempt(PaymentAttemptStatus::Processing, 490, 'tbank');
        $attempt->update(['metadata' => [
            'poll_count' => 3,
            'last_polled_at' => 'not-a-timestamp',
        ]]);

        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response(['Success' => false], 200),
        ]);

        // Backoff unverifiable → current poll allowed, no crash, no errors.
        $this->artisan('billing:reconcile-payment-attempts')
            ->assertExitCode(0);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/GetState'));

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        $this->assertSame(4, $attempt->metadata['poll_count']);
        // The poll itself rewrote last_polled_at with a fresh valid timestamp.
        $this->assertNotSame('not-a-timestamp', $attempt->metadata['last_polled_at']);
    }

    public function test_null_last_polled_at_keeps_previous_behavior(): void
    {
        $attempt = $this->createAttempt(PaymentAttemptStatus::Processing, 490, 'tbank');
        $attempt->update(['metadata' => ['poll_count' => 3]]); // no last_polled_at

        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response(['Success' => false], 200),
        ]);

        $result = app(PaymentReconciliationService::class)->reconcile();

        $this->assertSame(0, $result['errors']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/GetState'));

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        $this->assertSame(4, $attempt->metadata['poll_count']);
        $this->assertNotEmpty($attempt->metadata['last_polled_at']);
    }

    // ── Processing attempt + provider Unknown (in-flight recurring payment) ──

    public function test_processing_attempt_with_provider_unknown_stays_processing(): void
    {
        $attempt = $this->createAttempt(PaymentAttemptStatus::Processing, 490, 'tbank');

        // T-Bank GetState for Status=NEW normalizes as Unknown.
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => true,
                'PaymentId' => $attempt->provider_payment_id,
                'OrderId' => $attempt->internal_order_id,
                'Status' => 'NEW',
                'Amount' => 49000,
            ], 200),
        ]);

        $result = app(PaymentReconciliationService::class)->reconcile();

        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['errors']);
        Http::assertSentCount(1);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        // Poll metadata advanced through the existing backoff mechanism.
        $this->assertSame(1, $attempt->metadata['poll_count']);
        $this->assertNotEmpty($attempt->metadata['last_polled_at']);

        // Transition service never ran: no provider event claimed, no cycle change.
        $this->assertDatabaseCount('provider_events', 0);
        $attempt->billingCycle->refresh();
        $this->assertSame(BillingCycleStatus::Pending, $attempt->billingCycle->status);
    }

    public function test_processing_unknown_respects_backoff_on_next_reconcile(): void
    {
        $attempt = $this->createAttempt(PaymentAttemptStatus::Processing, 490, 'tbank');

        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => true,
                'PaymentId' => $attempt->provider_payment_id,
                'OrderId' => $attempt->internal_order_id,
                'Status' => 'NEW',
                'Amount' => 49000,
            ], 200),
        ]);

        $service = app(PaymentReconciliationService::class);

        $first = $service->reconcile();
        $this->assertSame(0, $first['errors']);
        Http::assertSentCount(1);

        // Immediately after: backoff[0] = 60s not elapsed → provider not called.
        $second = $service->reconcile();
        $this->assertSame(0, $second['errors']);
        Http::assertSentCount(1);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        $this->assertSame(1, $attempt->metadata['poll_count']);
        $this->assertDatabaseCount('provider_events', 0);
    }

    public function test_processing_attempt_with_provider_succeeded_transitions(): void
    {
        $attempt = $this->createAttempt(PaymentAttemptStatus::Processing, 490, 'tbank');

        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => true,
                'PaymentId' => $attempt->provider_payment_id,
                'OrderId' => $attempt->internal_order_id,
                'Status' => 'CONFIRMED',
                'Amount' => 49000,
            ], 200),
        ]);

        $result = app(PaymentReconciliationService::class)->reconcile();

        $this->assertSame(0, $result['errors']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
        // Transition service ran: provider event claimed.
        $this->assertDatabaseCount('provider_events', 1);
    }

    public function test_processing_attempt_with_provider_failed_terminal_transitions(): void
    {
        $attempt = $this->createAttempt(PaymentAttemptStatus::Processing, 490, 'tbank');

        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => true,
                'PaymentId' => $attempt->provider_payment_id,
                'OrderId' => $attempt->internal_order_id,
                'Status' => 'REJECTED',
                'Amount' => 49000,
            ], 200),
        ]);

        $result = app(PaymentReconciliationService::class)->reconcile();

        $this->assertSame(0, $result['errors']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertDatabaseCount('provider_events', 1);
    }

    public function test_unknown_attempt_with_provider_unknown_keeps_current_behavior(): void
    {
        $attempt = $this->createAttempt(PaymentAttemptStatus::Unknown, 490, 'tbank');

        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => true,
                'PaymentId' => $attempt->provider_payment_id,
                'OrderId' => $attempt->internal_order_id,
                'Status' => 'NEW',
                'Amount' => 49000,
            ], 200),
        ]);

        $result = app(PaymentReconciliationService::class)->reconcile();

        // Existing behavior: goes through PaymentTransitionService as an
        // idempotent same-status no-op — not an error, event is journaled.
        $this->assertSame(0, $result['errors']);
        $this->assertDatabaseCount('provider_events', 1);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Unknown, $attempt->status);
    }

    // ── DEADLINE_EXPIRED: bank-reported SBP timeout ──

    /**
     * Official test scenario «Платеж — отказ по таймауту»
     * (developer.tbank.ru/eacq/intro/errors/test-sbp): GetState answers with
     * Status=DEADLINE_EXPIRED. The refusal comes FROM THE BANK through the
     * existing GetState → normalizeWebhook → transition path, so it is a
     * provider verdict: failed_terminal with failure_category=provider_failed,
     * cycle Failed, no paid entitlement, and no undefined_outcome flag (that
     * flag is reserved for the local reconciliation_timeout age-release).
     */
    public function test_deadline_expired_from_getstate_fails_sbp_attempt_as_provider_verdict(): void
    {
        $attempt = $this->createAttempt(PaymentAttemptStatus::Processing, 490, 'tbank');
        $attempt->update(['metadata' => ['payment_method' => 'sbp']]);

        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => true,
                'PaymentId' => $attempt->provider_payment_id,
                'OrderId' => $attempt->internal_order_id,
                'Status' => 'DEADLINE_EXPIRED',
                'Amount' => 49000,
                'ErrorCode' => '0',
            ], 200),
        ]);

        $result = app(PaymentReconciliationService::class)->reconcile();

        $this->assertSame(0, $result['errors']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/GetState'));

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        // Bank verdict — never the local age-release category.
        $this->assertSame('provider_failed', $attempt->failure_category);
        $this->assertNotSame('reconciliation_timeout', $attempt->failure_category);
        $this->assertNotNull($attempt->finished_at);

        // The provider event was journaled through the transition service.
        $this->assertDatabaseCount('provider_events', 1);

        $attempt->billingCycle->refresh();
        $this->assertSame(BillingCycleStatus::Failed, $attempt->billingCycle->status);

        // No paid access is granted for a terminally failed payment.
        $this->assertFalse(app(EntitlementService::class)
            ->hasPlan($this->workspace, 'pro'));

        // A confirmed decline is reported verbatim: no undefined_outcome flag.
        $this->actingAs($this->master)
            ->get("/admin/billing/payment-status/{$attempt->provider_payment_id}")
            ->assertOk()
            ->assertExactJson(['status' => 'failed_terminal']);
    }

    /**
     * A confirmed success is never downgraded by a late DEADLINE_EXPIRED:
     * succeeded → failed_terminal is outside the state machine, the event is
     * journaled as invalid_transition and both attempt and cycle keep the
     * paid outcome.
     */
    public function test_late_deadline_expired_does_not_downgrade_confirmed_success(): void
    {
        $attempt = $this->createAttempt(PaymentAttemptStatus::Processing, 490, 'tbank');

        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => true,
                'PaymentId' => $attempt->provider_payment_id,
                'OrderId' => $attempt->internal_order_id,
                'Status' => 'CONFIRMED',
                'Amount' => 49000,
            ], 200),
        ]);

        $result = app(PaymentReconciliationService::class)->reconcile();
        $this->assertSame(0, $result['errors']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
        $attempt->billingCycle->refresh();
        $this->assertSame(BillingCycleStatus::Paid, $attempt->billingCycle->status);

        // The bank reports DEADLINE_EXPIRED too late — through the very same
        // normalizeWebhook / transition path a webhook would take.
        $late = app(PaymentGatewayManager::class)
            ->getGateway('tbank')
            ->normalizeWebhook([
                'PaymentId' => $attempt->provider_payment_id,
                'OrderId' => $attempt->internal_order_id,
                'Status' => 'DEADLINE_EXPIRED',
                'Amount' => 49000,
            ]);

        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $late->normalizedStatus);

        $transition = app(PaymentTransitionService::class)->transition($late);

        $this->assertFalse($transition['success']);
        $this->assertSame('invalid_transition', $transition['error']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
        $this->assertNull($attempt->failure_category);

        $attempt->billingCycle->refresh();
        $this->assertSame(BillingCycleStatus::Paid, $attempt->billingCycle->status);
    }

    // ── Helpers ──

    // ── Races between the batch snapshot and concurrent writers ──

    public function test_age_release_does_not_overwrite_success_confirmed_after_batch_read(): void
    {
        // Renewal attempt: age-releasable (no provider id → 30min window),
        // but handled second because its created_at is younger.
        [$sub, $cycle, $renewal] = $this->createRenewalAttempt();

        // Oldest row in the batch: polled first, still inside the 24h age
        // window, so it goes to GetState instead of an age-release.
        $inFlightCycle = BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now()->subMonths(2),
            'period_end' => now()->subMonth(),
            'status' => BillingCycleStatus::Pending,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Payment,
        ]);

        $inFlight = new PaymentAttempt([
            'billing_cycle_id' => $inFlightCycle->id,
            'provider' => 'tbank',
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'core_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => 'tbank_'.bin2hex(random_bytes(8)),
            'status' => PaymentAttemptStatus::Processing,
            'initiated_at' => now()->subHour(),
            'metadata' => ['billing_subscription_id' => $sub->id],
        ]);
        $inFlight->created_at = now()->subHour();
        $inFlight->save();

        $confirmed = false;
        Http::fake(function ($request) use (&$confirmed, $inFlight, $renewal) {
            // While reconcile() is mid-batch, the webhook confirms the
            // renewal payment — exactly the window ageRelease() used to
            // decide on its stale model.
            if (! $confirmed) {
                $confirmed = true;

                app(PaymentTransitionService::class)->transition(new ProviderStatusUpdate(
                    provider: 'tbank',
                    internalOrderId: $renewal->internal_order_id,
                    normalizedStatus: PaymentAttemptStatus::Succeeded,
                    amount: $renewal->amount,
                    currency: $renewal->currency,
                    raw: [
                        'Status' => 'CONFIRMED',
                        'OrderId' => $renewal->internal_order_id,
                    ],
                ));
            }

            return Http::response([
                'Success' => true,
                'PaymentId' => $inFlight->provider_payment_id,
                'OrderId' => $inFlight->internal_order_id,
                'Status' => 'NEW',
                'Amount' => 49000,
            ], 200);
        });

        $result = app(PaymentReconciliationService::class)->reconcile();

        $this->assertSame(2, $result['processed']);
        $this->assertSame(0, $result['errors']);
        Http::assertSentCount(1);

        // The confirmed outcome is never overwritten by the age-release.
        $renewal->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $renewal->status);

        $cycle->refresh();
        $this->assertSame(BillingCycleStatus::Paid, $cycle->status);

        // Subscription stays renewed: horizon not rolled back, no grace.
        $sub->refresh();
        $this->assertSame(BillingSubscriptionStatus::Active, $sub->status);
        $this->assertTrue($sub->current_period_end->equalTo($cycle->period_end));
        $this->assertNotNull($sub->next_charge_at);
        $this->assertTrue($sub->next_charge_at->equalTo($sub->current_period_end));
        $this->assertNull($sub->grace_until);

        $inFlight->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $inFlight->status);
        $this->assertSame(1, $inFlight->metadata['poll_count']);
    }

    public function test_poll_metadata_write_preserves_dispatch_marker_written_in_between(): void
    {
        $attempt = $this->createAttempt(PaymentAttemptStatus::Processing, 490, 'tbank');
        $attempt->update([
            'metadata' => [
                'renewal' => true,
                'billing_subscription_id' => $attempt->billingCycle->billing_subscription_id,
                'technical_retry' => true,
            ],
        ]);

        Http::fake(function ($request) use ($attempt) {
            if (str_ends_with($request->url(), '/v2/GetState')) {
                // The executor commits its dispatch marker while the batch
                // still holds the pre-marker metadata snapshot.
                $fresh = PaymentAttempt::findOrFail($attempt->getKey());
                $fresh->update([
                    'metadata' => array_merge($fresh->metadata ?? [], [
                        'charge_dispatch_started_at' => now()->toISOString(),
                    ]),
                ]);
            }

            return Http::response([
                'Success' => true,
                'PaymentId' => $attempt->provider_payment_id,
                'OrderId' => $attempt->internal_order_id,
                'Status' => 'NEW',
                'Amount' => 49000,
            ], 200);
        });

        $result = app(PaymentReconciliationService::class)->reconcile();

        $this->assertSame(0, $result['errors']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/GetState'));

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        // Only the polling keys were written: the marker and the retry flag
        // that landed after the batch read both survive.
        $this->assertNotNull($attempt->metadata['charge_dispatch_started_at'] ?? null);
        $this->assertTrue($attempt->metadata['technical_retry'] ?? false);
        $this->assertSame(1, $attempt->metadata['poll_count']);
        $this->assertNotEmpty($attempt->metadata['last_polled_at']);
    }

    // ── Ordinary age-release keeps working ──

    public function test_age_release_still_fails_cycle_and_opens_bounded_grace(): void
    {
        // DB timestamps have second precision — freeze so grace_until and
        // the assertion below are computed from the same instant.
        $this->freezeSecond();

        [$sub, $cycle, $attempt] = $this->createRenewalAttempt();

        $result = app(PaymentReconciliationService::class)->reconcile();

        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['errors']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('reconciliation_timeout', $attempt->failure_category);

        $cycle->refresh();
        $this->assertSame(BillingCycleStatus::Failed, $cycle->status);

        $sub->refresh();
        $this->assertSame(BillingSubscriptionStatus::PastDue, $sub->status);
        // Bounded window from config, measured from now (period already ended).
        $this->assertTrue($sub->grace_until->equalTo(now()->addDays(
            (int) config('billing.renewal.technical_grace_days'),
        )));
        $this->assertNull($sub->next_charge_at);
        $this->assertFalse($sub->cancel_at_period_end);
        $this->assertTrue($sub->current_period_end->equalTo(now()->subDay()));

        $grace = $sub->grace_until->copy();

        // A terminal attempt is out of the batch: no second release, no
        // grace extension.
        $again = app(PaymentReconciliationService::class)->reconcile();
        $this->assertSame(0, $again['processed']);

        $sub->refresh();
        $this->assertTrue($sub->grace_until->equalTo($grace));
    }

    // ── Helpers ──

    /**
     * Active renewal subscription + pending renewal cycle + in-flight
     * renewal attempt without a provider payment id, backdated so the
     * 30-minute age window applies.
     *
     * @return array{0: BillingSubscription, 1: BillingCycle, 2: PaymentAttempt}
     */
    private function createRenewalAttempt(array $attemptOverrides = [], int $ageMinutes = 40): array
    {
        $periodEnd = now()->subDay();

        $sub = BillingSubscription::create([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::Active,
            'renewal_period_months' => 1,
            'auto_renew_consent_at' => now()->subDays(2),
            'auto_renew_consent_version' => config('billing.recurring_terms_version'),
            'cancel_at_period_end' => false,
            'current_period_start' => $periodEnd->copy()->subMonth(),
            'current_period_end' => $periodEnd,
            'next_charge_at' => $periodEnd,
            'grace_until' => null,
        ]);

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => $periodEnd,
            'period_end' => $periodEnd->copy()->addMonth(),
            'status' => BillingCycleStatus::Pending,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Renewal,
        ]);

        $attempt = new PaymentAttempt(array_merge([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'tbank',
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'renew_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => null,
            'status' => PaymentAttemptStatus::Processing,
            'initiated_at' => now()->subHour(),
            'metadata' => [
                'renewal' => true,
                'billing_subscription_id' => $sub->id,
            ],
        ], $attemptOverrides));
        $attempt->created_at = now()->subMinutes($ageMinutes);
        $attempt->save();

        return [$sub, $cycle, $attempt];
    }

    private function createAttempt(
        PaymentAttemptStatus $status,
        int $amount,
        string $provider = 'mock',
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
            'origin' => 'payment',
        ]);

        $attempt = new PaymentAttempt([
            'billing_cycle_id' => $cycle->id,
            'provider' => $provider,
            'attempt_number' => 1,
            'amount' => $amount,
            'currency' => 'RUB',
            'internal_order_id' => 'core_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => 'mock_'.bin2hex(random_bytes(8)),
            'status' => $status,
            'initiated_at' => now()->subHour(),
        ]);
        $attempt->created_at = now()->subHour();
        $attempt->save();

        return $attempt;
    }

    private function createAttemptWithoutProviderPaymentId(
        PaymentAttemptStatus $status,
        int $amount,
        string $provider = 'mock',
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
            'origin' => 'payment',
        ]);

        $attempt = new PaymentAttempt([
            'billing_cycle_id' => $cycle->id,
            'provider' => $provider,
            'attempt_number' => 1,
            'amount' => $amount,
            'currency' => 'RUB',
            'internal_order_id' => 'core_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => null,
            'status' => $status,
            'initiated_at' => now()->subHour(),
        ]);
        $attempt->created_at = now()->subHour();
        $attempt->save();

        return $attempt;
    }
}
