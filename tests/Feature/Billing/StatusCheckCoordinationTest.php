<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\UserRole;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\PaymentAttempt;
use App\Models\PlanPrice;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Payment\DTOs\ProviderStatusUpdate;
use App\Services\Payment\PaymentReconciliationService;
use App\Services\Payment\PaymentTransitionService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

/**
 * Координация проверок статуса ОДНОЙ попытки.
 *
 * Все четыре пути (ручной POST, post-expiry bypass, обычная очередь,
 * timeout-очередь) идут через один per-attempt lock + cooldown: строка
 * перечитывается из БД после захвата lock, свежие status/metadata решают,
 * дорачивает ли проверка до банка, а cooldown, poll metadata и one-shot
 * маркер фиксируются ДО освобождения lock. HTTP живёт вне DB-транзакции,
 * один фактический вызов gateway даёт ровно один инкремент poll_count в
 * автоматическом пути. Только Http::fake — без реального банка.
 */
class StatusCheckCoordinationTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;

    private Workspace $workspace;

    private User $owner;

    /**
     * billing_subscriptions is unique on (workspace, plan): every attempt of
     * a test shares the single subscription of this workspace.
     */
    private BillingSubscription $subscription;

    /**
     * billing_cycles is unique on (subscription, period_start, period_end):
     * every attempt gets its own period.
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
            'backoff' => [60, 120, 300, 900, 1800, 3600],
            'max_age_with_provider_id' => 86400,
            'max_age_without_provider_id' => 1800,
            'status_check_cooldown_seconds' => 60,
        ]]);

        $this->owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->workspace = Workspace::create([
            'name' => 'ws-'.$this->owner->id,
            'owner_id' => $this->owner->id,
        ]);
        $this->workspace->ensureSlug();
        $this->owner->update(['workspace_id' => $this->workspace->id]);

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

        $this->subscription = BillingSubscription::create([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::PendingInitial,
        ]);
    }

    // ── Helpers ──

    /**
     * Свежий период цикла — уникален для каждой попытки.
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
     * @param  array<string, mixed>  $metadata
     */
    private function createCycle(BillingCycleStatus $status = BillingCycleStatus::Pending): BillingCycle
    {
        [$periodStart, $periodEnd] = $this->nextCyclePeriod();

        return BillingCycle::create([
            'billing_subscription_id' => $this->subscription->id,
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => $status,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Payment,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function createAttempt(
        PaymentAttemptStatus $status,
        int $ageMinutes,
        array $metadata = [],
        ?BillingCycle $cycle = null,
        ?string $failureCategory = null,
    ): PaymentAttempt {
        $cycle ??= $this->createCycle(
            $status === PaymentAttemptStatus::FailedTerminal
                ? BillingCycleStatus::Failed
                : BillingCycleStatus::Pending,
        );

        $attempt = new PaymentAttempt([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'tbank',
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'core_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => 'tbank_'.bin2hex(random_bytes(8)),
            'status' => $status,
            'failure_category' => $failureCategory,
            'initiated_at' => now()->subMinutes($ageMinutes + 60),
            'finished_at' => $status === PaymentAttemptStatus::FailedTerminal
                ? now()->subMinutes($ageMinutes)
                : null,
            'metadata' => $metadata,
        ]);
        $attempt->created_at = now()->subMinutes($ageMinutes);
        $attempt->save();

        return $attempt;
    }

    /**
     * In-flight row of the ordinary queue.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function createOrdinaryAttempt(int $ageMinutes = 60, array $metadata = []): PaymentAttempt
    {
        return $this->createAttempt(PaymentAttemptStatus::Processing, $ageMinutes, $metadata);
    }

    /**
     * Row already age-released to failed_terminal + reconciliation_timeout —
     * the timeout queue's own candidate.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function createTimeoutAttempt(int $ageMinutes = 60, array $metadata = []): PaymentAttempt
    {
        return $this->createAttempt(
            PaymentAttemptStatus::FailedTerminal,
            $ageMinutes,
            $metadata,
            failureCategory: 'reconciliation_timeout',
        );
    }

    /**
     * SBP attempt whose link has ALREADY expired while the old backoff
     * (poll_count=4 → 900s) still holds it: the bypass owes it one GetState.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function createExpiredSbpAttempt(int $ageMinutes = 60, array $metadata = []): PaymentAttempt
    {
        return $this->createOrdinaryAttempt($ageMinutes, array_merge([
            'payment_method' => 'sbp',
            'sbp_payload' => 'https://qr.nspk.ru/COORDINATION',
            'sbp_expires_at' => now()->subMinutes(2)->toIso8601String(),
            'poll_count' => 4,
            'last_polled_at' => now()->subMinutes(5)->toIso8601String(),
        ], $metadata));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function fakeGetState(string $status, PaymentAttempt $attempt, array $extra = []): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response(array_merge([
                'Success' => true,
                'PaymentId' => $attempt->provider_payment_id,
                'OrderId' => $attempt->internal_order_id,
                'Status' => $status,
                'Amount' => 49000,
            ], $extra), 200),
        ]);
    }

    private function getStateRequestCount(): int
    {
        return Http::recorded()
            ->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/v2/GetState'))
            ->count();
    }

    private function statusCheckLockKey(PaymentAttempt $attempt): string
    {
        return 'billing:payment-status-check:'.$attempt->getKey();
    }

    /**
     * Смог ли сторонний наблюдатель захватить lock: false = он всё ещё
     * удерживается проверкой, которая в этот момент пишет metadata.
     */
    private function lockIsHeldFor(PaymentAttempt $attempt): bool
    {
        $probe = Cache::lock($this->statusCheckLockKey($attempt), 5);
        $acquired = $probe->get();

        if ($acquired) {
            $probe->release();
        }

        return ! $acquired;
    }

    /**
     * Подтверждение успеха тем же переходом, которым пользуется webhook.
     */
    private function confirmSuccess(PaymentAttempt $attempt): void
    {
        app(PaymentTransitionService::class)->transition(new ProviderStatusUpdate(
            provider: 'tbank',
            internalOrderId: $attempt->internal_order_id,
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: $attempt->amount,
            currency: $attempt->currency,
            raw: [
                'Status' => 'CONFIRMED',
                'OrderId' => $attempt->internal_order_id,
            ],
        ));
    }

    // ── 1. Lock живёт весь вызов GetState и переживает его бюджет ──

    public function test_lock_is_held_for_the_whole_getstate_and_outlives_its_budget(): void
    {
        $attempt = $this->createOrdinaryAttempt();

        $heldDuringCall = null;
        Http::fake(function () use ($attempt, &$heldDuringCall) {
            $heldDuringCall = $this->lockIsHeldFor($attempt);

            return Http::response(['Success' => false], 200);
        });

        app(PaymentReconciliationService::class)->reconcile();

        // Пока идёт HTTP, попытка уже никому не отдаётся
        $this->assertTrue($heldDuringCall, 'lock должен удерживаться на весь вызов');
        $this->assertSame(1, $this->getStateRequestCount());

        // GetState легально занимает весь бюджет ретраев: 6 транспортных
        // попыток × 20s таймаута + 9.3s задержек ≈ 129.3s. Recovery-проверка
        // держит lock на ПОСЛЕДОВАТЕЛЬНУЮ пару методов (status lookup +
        // GetQr), поэтому TTL обязан покрывать двойной бюджет ≈ 258.6s с
        // запасом (retry-policy здесь не трогаем).
        $ttl = (new ReflectionClass(PaymentReconciliationService::class))
            ->getConstant('CHECK_LOCK_TTL_SECONDS');

        $this->assertIsInt($ttl);
        $this->assertGreaterThan(2 * 129.3, $ttl);
    }

    // ── 2. Все записи до освобождения lock ──

    public function test_one_shot_poll_metadata_and_cooldown_land_before_the_lock_is_released(): void
    {
        $attempt = $this->createExpiredSbpAttempt();
        $this->fakeGetState('NEW', $attempt);

        $lockHeldAtWrite = [];
        $listener = function ($updated) use (&$lockHeldAtWrite, $attempt) {
            if (! $updated instanceof PaymentAttempt
                || $updated->getKey() !== $attempt->getKey()
                || ! array_key_exists('sbp_expiry_polled_at', $updated->metadata ?? [])) {
                return;
            }

            $lockHeldAtWrite[] = $this->lockIsHeldFor($attempt);
        };

        Event::listen('eloquent.updated: '.PaymentAttempt::class, $listener);

        try {
            app(PaymentReconciliationService::class)->reconcile();
        } finally {
            Event::forget('eloquent.updated: '.PaymentAttempt::class);
        }

        // Маркер фиксируется в БД, пока lock ещё удерживается проверкой
        $this->assertNotEmpty($lockHeldAtWrite, 'запись маркера должна состояться под lock');
        $this->assertNotContains(false, $lockHeldAtWrite);

        $metadata = $attempt->refresh()->metadata;
        $this->assertNotEmpty($metadata['sbp_expiry_polled_at']);
        $this->assertNotEmpty($metadata['last_status_check_at']);
        // Один вызов gateway — один инкремент, старый backoff не сброшен
        $this->assertSame(5, $metadata['poll_count']);
        $this->assertSame(1, $this->getStateRequestCount());
        // Существующая metadata цела
        $this->assertSame('sbp', $metadata['payment_method']);
        $this->assertSame('https://qr.nspk.ru/COORDINATION', $metadata['sbp_payload']);
    }

    // ── 3. Пропуск из-за lock не потребляет one-shot ──

    public function test_a_lock_skip_costs_no_http_and_consumes_no_expiry_one_shot(): void
    {
        $attempt = $this->createExpiredSbpAttempt();
        Http::fake();

        $lock = Cache::lock($this->statusCheckLockKey($attempt), 30);
        $this->assertTrue($lock->get());

        try {
            app(PaymentReconciliationService::class)->reconcile();
        } finally {
            $lock->release();
        }

        $this->assertSame(0, $this->getStateRequestCount());
        $metadata = $attempt->refresh()->metadata;
        $this->assertArrayNotHasKey('sbp_expiry_polled_at', $metadata);
        $this->assertArrayNotHasKey('last_status_check_at', $metadata);
        $this->assertSame(4, $metadata['poll_count']);

        // Lock свободен — обход добирается до банка ровно один раз
        $this->fakeGetState('NEW', $attempt);
        app(PaymentReconciliationService::class)->reconcile();

        $this->assertSame(1, $this->getStateRequestCount());
        $metadata = $attempt->refresh()->metadata;
        $this->assertArrayHasKey('sbp_expiry_polled_at', $metadata);
        $this->assertSame(5, $metadata['poll_count']);
    }

    // ── 4. Ручной запрос против обычной очереди ──

    public function test_manual_check_and_the_ordinary_queue_share_one_cooldown(): void
    {
        // backoff[0] = 60s уже истёк (polled 5 минут назад) — очередь готова
        $attempt = $this->createOrdinaryAttempt(60, [
            'poll_count' => 1,
            'last_polled_at' => now()->subMinutes(5)->toIso8601String(),
        ]);
        $this->fakeGetState('NEW', $attempt);

        $this->actingAs($this->owner)
            ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
            ->assertOk()
            ->assertExactJson(['status' => 'processing', 'checked' => true]);
        $this->assertSame(1, $this->getStateRequestCount());
        // Ручной запрос backoff очереди не трогает
        $this->assertSame(1, $attempt->refresh()->metadata['poll_count']);

        // Cron в том же окне не ходит в банк
        app(PaymentReconciliationService::class)->reconcile();
        $this->assertSame(1, $this->getStateRequestCount());
        $this->assertSame(1, $attempt->refresh()->metadata['poll_count']);

        // Окно прошло — очередь дорывается до банка сама
        $this->travel(61)->seconds();
        app(PaymentReconciliationService::class)->reconcile();
        $this->travelBack();

        $this->assertSame(2, $this->getStateRequestCount());
        $this->assertSame(2, $attempt->refresh()->metadata['poll_count']);
    }

    // ── 5. Ручной запрос против timeout-очереди ──

    public function test_manual_check_and_the_timeout_queue_share_one_cooldown(): void
    {
        $attempt = $this->createTimeoutAttempt();
        $this->fakeGetState('NEW', $attempt);

        $this->actingAs($this->owner)
            ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
            ->assertOk()
            ->assertExactJson([
                'status' => 'failed_terminal',
                'checked' => true,
                'undefined_outcome' => true,
            ]);
        $this->assertSame(1, $this->getStateRequestCount());
        $this->assertArrayNotHasKey('poll_count', $attempt->refresh()->metadata);

        // Cron в том же окне: cooldown ручной проверки держит очередь
        app(PaymentReconciliationService::class)->reconcile();
        $this->assertSame(1, $this->getStateRequestCount());
        $this->assertArrayNotHasKey('poll_count', $attempt->refresh()->metadata);

        $this->travel(61)->seconds();
        app(PaymentReconciliationService::class)->reconcile();
        $this->travelBack();

        $this->assertSame(2, $this->getStateRequestCount());
        $this->assertSame(1, $attempt->refresh()->metadata['poll_count']);
    }

    // ── 6. Stale snapshot после завершённой проверки ──

    public function test_stale_snapshot_after_a_completed_check_fires_no_http(): void
    {
        // Старшая строка обрабатывается первой — именно её GetState и
        // открывает окно, в котором младшая строка успевает «сама» провериться
        $first = $this->createOrdinaryAttempt(120);
        $stale = $this->createOrdinaryAttempt(60);

        $polled = [];
        Http::fake(function ($request) use (&$polled, $stale) {
            if (str_ends_with($request->url(), '/v2/GetState')) {
                $polled[] = $request['PaymentId'];

                if (count($polled) === 1) {
                    // «Другой актор» как раз закончил проверку младшей строки,
                    // пока батч уже держит её снимок
                    $fresh = PaymentAttempt::findOrFail($stale->getKey());
                    $fresh->update(['metadata' => array_merge($fresh->metadata ?? [], [
                        'last_status_check_at' => now()->toIso8601String(),
                    ])]);
                }
            }

            return Http::response([
                'Success' => true,
                'Status' => 'NEW',
                'Amount' => 49000,
            ], 200);
        });

        app(PaymentReconciliationService::class)->reconcile();

        // Старый снимок не запустил повторную проверку
        $this->assertSame([$first->provider_payment_id], $polled);
        $this->assertSame(1, $this->getStateRequestCount());

        $stale->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $stale->status);
        $this->assertArrayNotHasKey('poll_count', $stale->metadata);
        $this->assertArrayNotHasKey('sbp_expiry_polled_at', $stale->metadata);

        // Старшая строка отработала как обычно: один вызов — один инкремент
        $this->assertSame(1, $first->refresh()->metadata['poll_count']);
    }

    // ── 7. Stale snapshot после подтверждённого успеха ──

    public function test_stale_snapshot_after_a_confirmed_success_fires_no_http(): void
    {
        $first = $this->createOrdinaryAttempt(120);
        $stale = $this->createOrdinaryAttempt(60);

        $confirmed = false;
        Http::fake(function () use (&$confirmed, $stale) {
            if (! $confirmed) {
                $confirmed = true;
                // Webhook подтверждает младшую строку, пока батч держит её снимок
                $this->confirmSuccess($stale);
            }

            return Http::response([
                'Success' => true,
                'Status' => 'NEW',
                'Amount' => 49000,
            ], 200);
        });

        app(PaymentReconciliationService::class)->reconcile();

        // Разрешённую попытку больше никто не опрашивает
        $this->assertSame(1, $this->getStateRequestCount());
        Http::assertSent(fn ($request) => $request['PaymentId'] === $first->provider_payment_id);

        $stale->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $stale->status);
        $this->assertSame(BillingCycleStatus::Paid, $stale->billingCycle->status);
        $this->assertArrayNotHasKey('poll_count', $stale->metadata);
        $this->assertDatabaseCount('provider_events', 1);
    }

    // ── 8. Stale snapshot разрешённой строки timeout-очереди ──

    public function test_stale_snapshot_of_a_resolved_timeout_row_fires_no_http(): void
    {
        $first = $this->createTimeoutAttempt(120);
        $stale = $this->createTimeoutAttempt(60);

        $confirmed = false;
        Http::fake(function () use (&$confirmed, $stale) {
            if (! $confirmed) {
                $confirmed = true;
                $this->confirmSuccess($stale);
            }

            return Http::response([
                'Success' => true,
                'Status' => 'NEW',
                'Amount' => 49000,
            ], 200);
        });

        app(PaymentReconciliationService::class)->reconcile();

        $this->assertSame(1, $this->getStateRequestCount());
        $this->assertSame(1, $first->refresh()->metadata['poll_count']);

        $stale->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $stale->status);
        $this->assertArrayNotHasKey('poll_count', $stale->metadata);
        $this->assertDatabaseCount('provider_events', 1);
    }

    // ── 9. Ручной запрос со stale snapshot после успеха ──

    public function test_manual_check_with_a_stale_snapshot_after_success_fires_no_http(): void
    {
        $attempt = $this->createOrdinaryAttempt();
        // Снимок, снятый до подтверждения
        $snapshot = $attempt->fresh();

        $this->confirmSuccess($attempt);
        Http::fake();

        $result = app(PaymentReconciliationService::class)->checkAttemptNow($snapshot);

        $this->assertFalse($result['checked']);
        $this->assertSame(0, $this->getStateRequestCount());
        $this->assertSame(PaymentAttemptStatus::Succeeded, $result['attempt']->status);
        // Снимок остался старым — именно его и нельзя опрашивать
        $this->assertSame(PaymentAttemptStatus::Processing, $snapshot->status);
    }

    // ── 10. Один вызов gateway — один инкремент poll_count ──

    public function test_one_actual_getstate_advances_poll_metadata_exactly_once(): void
    {
        $attempt = $this->createOrdinaryAttempt();
        $this->fakeGetState('CONFIRMED', $attempt);

        app(PaymentReconciliationService::class)->reconcile();

        $this->assertSame(1, $this->getStateRequestCount());

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
        // Ровно один инкремент на фактический вызов — даже сквозь переход
        $this->assertSame(1, $attempt->metadata['poll_count']);
        $this->assertNotEmpty($attempt->metadata['last_polled_at']);
        $this->assertNotEmpty($attempt->metadata['last_status_check_at']);

        // Терминальная строка ушла из батча: повторный прогон молчит
        app(PaymentReconciliationService::class)->reconcile();
        $this->assertSame(1, $this->getStateRequestCount());
    }

    // ── 11. Ошибка транспорта в обычной очереди ──

    public function test_a_transport_failure_counts_an_error_and_still_advances_the_backoff_once(): void
    {
        $attempt = $this->createOrdinaryAttempt();

        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            throw new \RuntimeException('network down');
        });

        $result = app(PaymentReconciliationService::class)->reconcile();

        // Ошибка по-прежнему видна вызывающему …
        $this->assertSame(1, $result['errors']);
        $this->assertSame(0, $result['processed']);
        $this->assertSame(1, $calls);

        $attempt->refresh();
        // … а фактический вызов уже оплачен: ровно один инкремент и cooldown
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        $this->assertSame(1, $attempt->metadata['poll_count']);
        $this->assertNotEmpty($attempt->metadata['last_polled_at']);
        $this->assertNotEmpty($attempt->metadata['last_status_check_at']);

        // Следующий прогон ждёт окна — ни HTTP, ни новой ошибки
        $again = app(PaymentReconciliationService::class)->reconcile();
        $this->assertSame(0, $again['errors']);
        $this->assertSame(1, $calls);
        $this->assertSame(1, $attempt->refresh()->metadata['poll_count']);
    }
}
