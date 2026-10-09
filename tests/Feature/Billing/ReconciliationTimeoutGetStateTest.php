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
use App\Services\Payment\PaymentReconciliationService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GetState для попыток, уже age-released в failed_terminal +
 * reconciliation_timeout с непустым provider_payment_id: подтверждённый
 * успех/отказ проходит в существующий transition(), нетерминальный ответ,
 * null и исключение сохраняют локальный timeout и блокировку checkout,
 * продвигая backoff ровно один раз на каждый фактический вызов. Только
 * Http::fake — без реального банка.
 */
class ReconciliationTimeoutGetStateTest extends TestCase
{
    use RefreshDatabase;

    private const TIMEOUT_MESSAGE = 'Статус предыдущего платежа уточняется. Попробуйте позже.';

    private TariffPlan $proPlan;

    private User $master;

    private Workspace $workspace;

    /**
     * billing_cycles is unique on (subscription, period_start, period_end):
     * every attempt built by the helpers below gets its own period.
     */
    private int $cycleSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.legacy_mock_webhook_secret' => 'test_secret_123']);
        config(['billing.reconciliation' => [
            'batch_size' => 50,
            'timeout_batch_size' => 50,
            'fresh_grace_seconds' => 0,
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

    // ── Helpers ──

    /**
     * billing_subscriptions is unique on (workspace, plan) — all attempts of
     * a test share the single subscription of this workspace.
     */
    private function sharedSubscription(): BillingSubscription
    {
        return BillingSubscription::firstOrCreate([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
        ], [
            'status' => BillingSubscriptionStatus::PendingInitial,
        ]);
    }

    /**
     * Unique (period_start, period_end) per cycle — past start, future end,
     * so a confirmed payment grants a horizon like a real renewal would.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function nextCyclePeriod(): array
    {
        $start = now()->subDays(30)->addMinutes($this->cycleSeq);
        $this->cycleSeq++;

        return [$start, $start->copy()->addDays(60)];
    }

    /**
     * Age-released attempt: failed_terminal + reconciliation_timeout +
     * provider payment id, cycle already Failed (the age-release wrote it).
     *
     * @param  array<string, mixed>  $metadata
     */
    private function createTimeoutAttempt(
        int $ageMinutes = 60,
        array $metadata = ['payment_method' => 'card'],
        string $providerPaymentId = '',
    ): PaymentAttempt {
        $subscription = $this->sharedSubscription();
        [$periodStart, $periodEnd] = $this->nextCyclePeriod();

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $subscription->id,
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
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
            'provider_payment_id' => $providerPaymentId !== ''
                ? $providerPaymentId
                : 'tbank_'.bin2hex(random_bytes(8)),
            'status' => PaymentAttemptStatus::FailedTerminal,
            'failure_category' => 'reconciliation_timeout',
            'initiated_at' => now()->subMinutes($ageMinutes + 60),
            'finished_at' => now()->subMinutes($ageMinutes),
            'metadata' => $metadata,
        ]);
        $attempt->created_at = now()->subMinutes($ageMinutes);
        $attempt->save();

        return $attempt;
    }

    /**
     * Ordinary in-flight attempt for the untouched Created/Processing/Unknown
     * queue.
     */
    private function createOrdinaryAttempt(int $ageMinutes): PaymentAttempt
    {
        $subscription = $this->sharedSubscription();
        [$periodStart, $periodEnd] = $this->nextCyclePeriod();

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $subscription->id,
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => BillingCycleStatus::Pending,
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
            'provider_payment_id' => 'tbank_'.bin2hex(random_bytes(8)),
            'status' => PaymentAttemptStatus::Processing,
            'initiated_at' => now()->subMinutes($ageMinutes + 60),
            'metadata' => ['payment_method' => 'card'],
        ]);
        $attempt->created_at = now()->subMinutes($ageMinutes);
        $attempt->save();

        return $attempt;
    }

    /**
     * GetState answer straight from the official contract: Success + PaymentId
     * + OrderId + Status (+ Amount in kopecks for a confirmed payment).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function fakeGetState(string $status, PaymentAttempt $attempt, array $overrides = []): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response(array_merge([
                'Success' => true,
                'PaymentId' => $attempt->provider_payment_id,
                'OrderId' => $attempt->internal_order_id,
                'Status' => $status,
                'Amount' => 49000,
                'ErrorCode' => '0',
            ], $overrides), 200),
        ]);
    }

    private function reconcile(): array
    {
        return app(PaymentReconciliationService::class)->reconcile();
    }

    private function assertCheckoutBlocked(PaymentAttempt $attempt, bool $blocked): void
    {
        $inFlight = app(BillingCoreWriter::class)->findExistingInFlightAttempt(
            $this->workspace->id,
            $this->proPlan->id,
        );

        if ($blocked) {
            $this->assertNotNull($inFlight);
            $this->assertPaymentStatusJson($attempt, [
                'status' => 'failed_terminal',
                'undefined_outcome' => true,
            ]);
        } else {
            $this->assertNull($inFlight);
        }
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function assertPaymentStatusJson(PaymentAttempt $attempt, array $expected): void
    {
        $this->actingAs($this->master)
            ->get("/admin/billing/payment-status/{$attempt->provider_payment_id}")
            ->assertOk()
            ->assertExactJson($expected);
    }

    // ── 1. Timeout → CONFIRMED: Paid, block gone ──

    public function test_timeout_attempt_with_confirmed_success_gets_paid_and_unblocks_checkout(): void
    {
        $attempt = $this->createTimeoutAttempt();
        $this->assertCheckoutBlocked($attempt, true);

        $this->fakeGetState('CONFIRMED', $attempt);

        $result = $this->reconcile();

        $this->assertSame(0, $result['errors']);
        $this->assertGreaterThan(0, $result['processed']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/GetState'));

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
        $this->assertNull($attempt->failure_category);
        $this->assertNull($attempt->failure_code);
        $this->assertSame(1, $attempt->metadata['poll_count']);
        $this->assertNotEmpty($attempt->metadata['last_polled_at']);

        $attempt->billingCycle->refresh();
        $this->assertSame(BillingCycleStatus::Paid, $attempt->billingCycle->status);

        $sub = $attempt->billingCycle->billingSubscription;
        $sub->refresh();
        $this->assertSame(BillingSubscriptionStatus::Active, $sub->status);

        $this->assertCheckoutBlocked($attempt, false);
        $this->assertPaymentStatusJson($attempt, ['status' => 'succeeded']);
        $this->assertDatabaseCount('provider_events', 1);
    }

    // ── 2. Timeout → DEADLINE_EXPIRED: confirmed refusal, block gone ──

    public function test_timeout_attempt_with_deadline_expired_resolves_and_unblocks_checkout(): void
    {
        $attempt = $this->createTimeoutAttempt();
        $this->assertCheckoutBlocked($attempt, true);

        $this->fakeGetState('DEADLINE_EXPIRED', $attempt);

        $result = $this->reconcile();

        $this->assertSame(0, $result['errors']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/GetState'));

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        // Bank verdict — the local category is replaced, never kept.
        $this->assertSame('provider_failed', $attempt->failure_category);
        $this->assertSame(1, $attempt->metadata['poll_count']);

        $this->assertCheckoutBlocked($attempt, false);
        // Confirmed decline is reported verbatim: no undefined_outcome.
        $this->assertPaymentStatusJson($attempt, ['status' => 'failed_terminal']);
        $this->assertDatabaseCount('provider_events', 1);
    }

    // ── 3. No final verdict: NEW keeps timeout and block ──

    public function test_timeout_attempt_with_new_status_keeps_timeout_and_block(): void
    {
        $attempt = $this->createTimeoutAttempt();

        $this->fakeGetState('NEW', $attempt);

        $result = $this->reconcile();

        $this->assertSame(0, $result['errors']);
        $this->assertSame(1, $result['processed']);
        Http::assertSentCount(1);

        $attempt->refresh();
        // Still the local timeout — a non-terminal status never reached
        // transition(), so no provider event and no category overwrite.
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('reconciliation_timeout', $attempt->failure_category);
        $this->assertSame(1, $attempt->metadata['poll_count']);
        $this->assertNotEmpty($attempt->metadata['last_polled_at']);
        $this->assertDatabaseCount('provider_events', 0);

        $this->assertCheckoutBlocked($attempt, true);
    }

    // ── 4. No verdict at all: null answer keeps timeout and block ──

    public function test_timeout_attempt_with_null_answer_keeps_timeout_and_block(): void
    {
        $attempt = $this->createTimeoutAttempt();

        // Success=false / non-2xx — getPaymentStatus answers null.
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response(['Success' => false], 200),
        ]);

        $result = $this->reconcile();

        $this->assertSame(0, $result['errors']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/GetState'));

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('reconciliation_timeout', $attempt->failure_category);
        $this->assertSame(1, $attempt->metadata['poll_count']);
        $this->assertDatabaseCount('provider_events', 0);

        $this->assertCheckoutBlocked($attempt, true);
    }

    // ── 5. HTTP exception: error visible, backoff still advances ──

    public function test_timeout_attempt_with_http_exception_counts_error_and_advances_backoff(): void
    {
        $attempt = $this->createTimeoutAttempt();

        Http::fake(fn () => throw new \RuntimeException('T-Bank is unreachable'));

        $result = $this->reconcile();

        // The failure stays visible to the caller …
        $this->assertSame(1, $result['errors']);
        $this->assertSame(0, $result['processed']);

        $attempt->refresh();
        // … while the attempt itself is untouched and the backoff moved on.
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('reconciliation_timeout', $attempt->failure_category);
        $this->assertSame(1, $attempt->metadata['poll_count']);
        $this->assertNotEmpty($attempt->metadata['last_polled_at']);

        $this->assertCheckoutBlocked($attempt, true);
    }

    // ── 6. Backoff: the next run before the window makes no HTTP call ──

    public function test_second_run_inside_backoff_makes_no_http_call(): void
    {
        $attempt = $this->createTimeoutAttempt();

        $this->fakeGetState('NEW', $attempt);

        $first = $this->reconcile();
        $this->assertSame(0, $first['errors']);
        Http::assertSentCount(1);

        $second = $this->reconcile();
        $this->assertSame(0, $second['errors']);
        // backoff[0] = 60s not elapsed → no second GetState, no double
        // increment of the poll counter.
        Http::assertSentCount(1);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('reconciliation_timeout', $attempt->failure_category);
        $this->assertSame(1, $attempt->metadata['poll_count']);

        $this->assertCheckoutBlocked($attempt, true);
    }

    // ── 7. Metadata merge keeps markers, flags and the original grace ──

    public function test_poll_metadata_merge_preserves_markers_flags_and_grace(): void
    {
        $sub = BillingSubscription::create([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::PastDue,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->addDays(10),
            'renewal_period_months' => 1,
            'auto_renew_consent_at' => now(),
            'auto_renew_consent_version' => config('billing.recurring_terms_version'),
            'cancel_at_period_end' => false,
            'grace_until' => now()->addDays(3),
        ]);

        $attempt = $this->createTimeoutAttempt(60, [
            'renewal' => true,
            'billing_subscription_id' => $sub->id,
            'charge_dispatch_started_at' => '2026-01-01T00:00:00+00:00',
            'technical_retry' => true,
            'payment_method' => 'card',
        ]);

        $graceBefore = $sub->grace_until;

        $this->fakeGetState('NEW', $attempt);

        $result = $this->reconcile();
        $this->assertSame(0, $result['errors']);

        $attempt->refresh();
        $this->assertSame(1, $attempt->metadata['poll_count']);
        $this->assertNotEmpty($attempt->metadata['last_polled_at']);
        // Only the two polling keys were written: everything else survives
        // byte for byte, including the dispatch marker.
        $this->assertSame('2026-01-01T00:00:00+00:00', $attempt->metadata['charge_dispatch_started_at']);
        $this->assertTrue($attempt->metadata['renewal']);
        $this->assertTrue($attempt->metadata['technical_retry']);
        $this->assertSame('card', $attempt->metadata['payment_method']);

        // The poll never touches grace or the subscription state.
        $sub->refresh();
        $this->assertSame(BillingSubscriptionStatus::PastDue, $sub->status);
        $this->assertTrue($sub->grace_until->equalTo($graceBefore));
        $this->assertNull($sub->next_charge_at);
    }

    // ── 8. Fair queue: a row inside backoff never holds the head ──

    public function test_row_inside_backoff_does_not_block_a_due_timeout_row(): void
    {
        config(['billing.reconciliation.timeout_batch_size' => 1]);

        // Oldest row of the queue, but polled 10s ago → still inside
        // backoff[0] = 60s, so it must not take the single batch slot.
        $polledAt = now()->subSeconds(10)->toIso8601String();
        $inBackoff = $this->createTimeoutAttempt(180, [
            'poll_count' => 1,
            'last_polled_at' => $polledAt,
        ], 'tbank_backoff_1');

        // Younger, never polled → due right now.
        $due = $this->createTimeoutAttempt(60, ['payment_method' => 'card'], 'tbank_due_1');

        $this->fakeGetState('NEW', $due);

        $result = $this->reconcile();
        $this->assertSame(0, $result['errors']);
        $this->assertSame(1, $result['processed']);

        // The single GetState went to the due row, not to the older one.
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['PaymentId'] === 'tbank_due_1');

        $due->refresh();
        $this->assertSame(1, $due->metadata['poll_count']);

        $inBackoff->refresh();
        $this->assertSame(1, $inBackoff->metadata['poll_count']);
        // Untouched: no second write, so its backoff window still stands.
        $this->assertSame($polledAt, $inBackoff->metadata['last_polled_at']);
        $this->assertSame('reconciliation_timeout', $inBackoff->failure_category);
    }

    // ── 9. Separate budgets: neither queue starves the other ──

    public function test_ordinary_and_timeout_queues_both_progress_when_batch_is_one(): void
    {
        config([
            'billing.reconciliation.batch_size' => 1,
            'billing.reconciliation.timeout_batch_size' => 1,
        ]);

        $ordinary = $this->createOrdinaryAttempt(300);
        $this->createOrdinaryAttempt(240);
        $timeoutX = $this->createTimeoutAttempt(300);
        $timeoutY = $this->createTimeoutAttempt(240);

        $this->fakeGetState('NEW', $ordinary);

        $first = $this->reconcile();
        $this->assertSame(0, $first['errors']);

        // One slot per queue: neither crowd the other out.
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['PaymentId'] === $ordinary->provider_payment_id);
        Http::assertSent(fn ($request) => $request['PaymentId'] === $timeoutX->provider_payment_id);

        $ordinary->refresh();
        $timeoutX->refresh();
        $timeoutY->refresh();
        $this->assertSame(1, $ordinary->metadata['poll_count']);
        $this->assertSame(1, $timeoutX->metadata['poll_count']);
        $this->assertArrayNotHasKey('poll_count', $timeoutY->metadata);

        // Next pass: the timeout queue rotates to the row it has not served
        // yet (least-recently-polled first), while the ordinary queue keeps
        // getting its own slot.
        $this->travel(61)->seconds();

        $second = $this->reconcile();
        $this->assertSame(0, $second['errors']);

        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => $request['PaymentId'] === $timeoutY->provider_payment_id);

        $ordinary->refresh();
        $timeoutX->refresh();
        $timeoutY->refresh();
        $this->assertSame(2, $ordinary->metadata['poll_count']);
        $this->assertSame(1, $timeoutX->metadata['poll_count']);
        $this->assertSame(1, $timeoutY->metadata['poll_count']);

        // Nothing was resolved: every answer was non-terminal.
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $timeoutX->status);
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $timeoutY->status);
        $this->assertSame('reconciliation_timeout', $timeoutX->failure_category);
        $this->assertSame('reconciliation_timeout', $timeoutY->failure_category);
        $this->assertDatabaseCount('provider_events', 0);
    }

    // ── 10. Readiness gates the batch fill: blocked rows never take the slot ──

    public function test_row_inside_long_backoff_never_takes_the_single_slot_of_a_due_row(): void
    {
        config([
            'billing.reconciliation.timeout_batch_size' => 1,
            'billing.reconciliation.backoff' => [60, 120, 300, 900, 1800, 3600],
        ]);

        // Three rows ahead of the due one in fair-queue order (older
        // last_polled_at): poll_count 6 → backoff 3600s, and their last poll
        // is only 10 minutes old, so none of them is due.
        $blocked = [];
        $blockedMetadataBefore = [];
        foreach ([1, 2, 3] as $n) {
            $row = $this->createTimeoutAttempt(180, [
                'poll_count' => 6,
                'last_polled_at' => now()->subMinutes(10)->toIso8601String(),
            ], 'tbank_blocked_'.$n);

            $blocked[] = $row;
            $blockedMetadataBefore[$row->id] = $row->metadata;
        }

        // Fresher last_polled_at, so it sorts BEHIND the blocked rows —
        // poll_count 1 with backoff 60s means its five-minute-old poll is
        // long due.
        $due = $this->createTimeoutAttempt(60, [
            'poll_count' => 1,
            'last_polled_at' => now()->subMinutes(5)->toIso8601String(),
        ], 'tbank_due_now');
        $duePolledAtBefore = $due->metadata['last_polled_at'];

        $this->fakeGetState('NEW', $due);

        $result = $this->reconcile();

        $this->assertSame(0, $result['errors']);
        $this->assertSame(1, $result['processed']);

        // The single batch slot and the single HTTP call go to the due row:
        // the blocked rows ahead of it neither consume the limit nor fire a
        // premature GetState.
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['PaymentId'] === 'tbank_due_now');

        $due->refresh();
        $this->assertSame(2, $due->metadata['poll_count']);
        $this->assertNotSame($duePolledAtBefore, $due->metadata['last_polled_at']);

        foreach ($blocked as $row) {
            $row->refresh();
            // Passed over without a single write: poll metadata byte for
            // byte identical, and the local timeout still in place.
            $this->assertSame($blockedMetadataBefore[$row->id], $row->metadata);
            $this->assertSame(6, $row->metadata['poll_count']);
            $this->assertSame(PaymentAttemptStatus::FailedTerminal, $row->status);
            $this->assertSame('reconciliation_timeout', $row->failure_category);
        }
    }

    // ── 11. Bounded walk: a due row behind a full page of blocked rows ──

    public function test_due_row_behind_a_full_page_of_blocked_rows_is_still_polled(): void
    {
        config([
            'billing.reconciliation.timeout_batch_size' => 1,
            'billing.reconciliation.backoff' => [60, 120, 300, 900, 1800, 3600],
        ]);

        // One full scan page (max(limit, 100) candidates in memory) of rows
        // all still inside their 3600s window …
        $blocked = [];
        $blockedMetadataBefore = [];
        for ($n = 1; $n <= 100; $n++) {
            $row = $this->createTimeoutAttempt(180, [
                'poll_count' => 6,
                'last_polled_at' => now()->subMinutes(10)->toIso8601String(),
            ], 'tbank_blocked_'.$n);

            $blocked[] = $row;
            $blockedMetadataBefore[$row->id] = $row->metadata;
        }

        // … and the due row right behind that page: the queue must be walked
        // page by page instead of stopping at an empty first page, without
        // ever materialising all 101 candidates at once.
        $due = $this->createTimeoutAttempt(60, [
            'poll_count' => 1,
            'last_polled_at' => now()->subMinutes(5)->toIso8601String(),
        ], 'tbank_due_paged');

        // The due row really sits behind a full page, not inside page one.
        $this->assertSame(101, PaymentAttempt::where('status', PaymentAttemptStatus::FailedTerminal)
            ->where('failure_category', 'reconciliation_timeout')
            ->count());

        $this->fakeGetState('NEW', $due);

        $result = $this->reconcile();

        $this->assertSame(0, $result['errors']);
        $this->assertSame(1, $result['processed']);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['PaymentId'] === 'tbank_due_paged');

        $due->refresh();
        $this->assertSame(2, $due->metadata['poll_count']);

        foreach ($blocked as $row) {
            $row->refresh();
            $this->assertSame($blockedMetadataBefore[$row->id], $row->metadata);
        }
    }
}
