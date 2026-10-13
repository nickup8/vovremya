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
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingCoreWriter;
use App\Services\Payment\DTOs\ProviderStatusUpdate;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\PaymentReconciliationService;
use App\Services\Payment\PaymentTransitionService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Recovery PaymentId первого checkout: CheckOrder для tbank-попыток без
 * provider_payment_id (NULL или '') — created/processing/unknown либо
 * released failed_terminal + reconciliation_timeout. Renewal и mock
 * исключены по реальным признакам кода. Привязка идёт под row-lock с
 * повторным чтением; NEW/AUTHORIZED только привязывают id; финальный
 * вердикт сразу уходит в существующий PaymentTransitionService. Только
 * Http::fake — без реального банка.
 */
class PaymentIdRecoveryTest extends TestCase
{
    use RefreshDatabase;

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
            'status_check_cooldown_seconds' => 60,
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

    // ── 1. Найденный ответ: привязка + финальный вердикт ──

    public function test_inflight_null_id_confirmed_checkorder_binds_id_and_gets_paid_in_one_run(): void
    {
        $attempt = $this->createCheckoutAttempt(PaymentAttemptStatus::Created, providerPaymentId: null);

        $this->fakeCheckOrder('CONFIRMED', $attempt);

        $result = $this->reconcile();

        $this->assertSame(0, $result['errors']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/CheckOrder'));

        $attempt->refresh();
        $this->assertNotNull($attempt->provider_payment_id);
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
        $this->assertNotNull($attempt->finished_at);

        $attempt->billingCycle->refresh();
        $this->assertSame(BillingCycleStatus::Paid, $attempt->billingCycle->status);

        // Один фактический запрос — один инкремент poll metadata.
        $this->assertSame(1, $attempt->metadata['poll_count']);
        $this->assertNotEmpty($attempt->metadata['last_polled_at']);
        $this->assertNotEmpty($attempt->metadata['last_status_check_at']);

        $this->assertDatabaseCount('provider_events', 1);
    }

    public function test_inflight_empty_string_id_is_recovered_too(): void
    {
        $attempt = $this->createCheckoutAttempt(PaymentAttemptStatus::Created, providerPaymentId: '');

        $this->fakeCheckOrder('CONFIRMED', $attempt);

        $result = $this->reconcile();

        $this->assertSame(0, $result['errors']);

        $attempt->refresh();
        $this->assertNotSame('', $attempt->provider_payment_id);
        $this->assertNotNull($attempt->provider_payment_id);
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
    }

    public function test_released_null_id_confirmed_checkorder_resolves_timeout_and_unblocks(): void
    {
        $attempt = $this->createReleasedTimeoutAttempt(providerPaymentId: null);
        $this->assertCheckoutBlocked($attempt, true);

        $this->fakeCheckOrder('CONFIRMED', $attempt);

        $result = $this->reconcile();

        $this->assertSame(0, $result['errors']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/CheckOrder'));

        $attempt->refresh();
        $this->assertNotNull($attempt->provider_payment_id);
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
        // Подтверждённый успех гасит локальные failure-поля таймаута.
        $this->assertNull($attempt->failure_category);

        $attempt->billingCycle->refresh();
        $this->assertSame(BillingCycleStatus::Paid, $attempt->billingCycle->status);

        $this->assertCheckoutBlocked($attempt, false);
    }

    public function test_released_null_id_declined_checkorder_resolves_local_timeout_as_provider_verdict(): void
    {
        $attempt = $this->createReleasedTimeoutAttempt(providerPaymentId: null);
        $this->assertCheckoutBlocked($attempt, true);

        $this->fakeCheckOrder('REJECTED', $attempt);

        $result = $this->reconcile();

        $this->assertSame(0, $result['errors']);

        $attempt->refresh();
        $this->assertNotNull($attempt->provider_payment_id);
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        // Отказ пришёл от банка — категория заменяет локальный таймаут.
        $this->assertSame('provider_failed', $attempt->failure_category);

        // Подтверждённый отказ больше не блокирует новый checkout.
        $this->assertCheckoutBlocked($attempt, false);
    }

    // ── 2. NEW / AUTHORIZED: только привязка ID ──

    public function test_released_new_status_binds_id_only_and_keeps_timeout_block(): void
    {
        $attempt = $this->createReleasedTimeoutAttempt(
            providerPaymentId: null,
            metadata: ['payment_method' => 'card', 'keep_me' => 'yes'],
        );
        // Снимаем значение, реально лежащее в БД (без микросекунд).
        $attempt->refresh();
        $finishedAt = $attempt->finished_at;

        $this->fakeCheckOrder('NEW', $attempt);

        $result = $this->reconcile();

        $this->assertSame(0, $result['errors']);
        Http::assertSentCount(1);

        $attempt->refresh();
        $this->assertNotNull($attempt->provider_payment_id);
        // Статус, failure-поля, finished_at и не-poll metadata не тронуты.
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('reconciliation_timeout', $attempt->failure_category);
        $this->assertTrue($attempt->finished_at->equalTo($finishedAt));
        $this->assertSame('card', $attempt->metadata['payment_method']);
        $this->assertSame('yes', $attempt->metadata['keep_me']);
        $this->assertSame(1, $attempt->metadata['poll_count']);

        // Блокировка новой оплаты сохраняется.
        $this->assertCheckoutBlocked($attempt, true);
        $this->assertDatabaseCount('provider_events', 0);
    }

    public function test_inflight_authorized_status_binds_id_only(): void
    {
        $attempt = $this->createCheckoutAttempt(PaymentAttemptStatus::Created, providerPaymentId: null);

        $this->fakeCheckOrder('AUTHORIZED', $attempt);

        $result = $this->reconcile();

        $this->assertSame(0, $result['errors']);

        $attempt->refresh();
        $this->assertNotNull($attempt->provider_payment_id);
        $this->assertSame(PaymentAttemptStatus::Created, $attempt->status);
        $this->assertNull($attempt->finished_at);
        $this->assertNull($attempt->failure_category);
        $this->assertDatabaseCount('provider_events', 0);

        // Попытка осталась in-flight — новая оплата блокируется.
        $this->assertNotNull(
            app(BillingCoreWriter::class)->findExistingInFlightAttempt($this->workspace->id, $this->proPlan->id),
        );
    }

    public function test_inflight_new_status_binds_id_only(): void
    {
        $attempt = $this->createCheckoutAttempt(PaymentAttemptStatus::Created, providerPaymentId: null);

        $this->fakeCheckOrder('NEW', $attempt);

        $result = $this->reconcile();

        $this->assertSame(0, $result['errors']);

        $attempt->refresh();
        $this->assertNotNull($attempt->provider_payment_id);
        $this->assertSame(PaymentAttemptStatus::Created, $attempt->status);
        $this->assertDatabaseCount('provider_events', 0);
    }

    // ── 3. 335 / ошибка / неоднозначность: только backoff и диагностика ──

    public function test_checkorder_335_keeps_attempt_waiting_with_backoff_and_block(): void
    {
        $attempt = $this->createReleasedTimeoutAttempt(providerPaymentId: null);
        $this->assertCheckoutBlocked($attempt, true);

        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => false,
                'ErrorCode' => '335',
                'ErrorMessage' => 'Order not found',
            ], 200),
        ]);

        $result = $this->reconcile();

        $this->assertSame(0, $result['errors']);
        Http::assertSentCount(1);

        $attempt->refresh();
        $this->assertNull($attempt->provider_payment_id);
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('reconciliation_timeout', $attempt->failure_category);
        $this->assertSame(1, $attempt->metadata['poll_count']);
        $this->assertDatabaseCount('provider_events', 0);

        // Блокировка остаётся; никакого нового Init/Cancel/переклассификации.
        $this->assertCheckoutBlocked($attempt, true);

        // Backoff: немедленный второй прогон не шлёт второй запрос.
        $again = $this->reconcile();
        $this->assertSame(0, $again['errors']);
        Http::assertSentCount(1);
        $attempt->refresh();
        $this->assertSame(1, $attempt->metadata['poll_count']);
    }

    public function test_checkorder_ambiguous_payments_counts_error_and_keeps_attempt(): void
    {
        $attempt = $this->createReleasedTimeoutAttempt(providerPaymentId: null);

        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => true,
                'Payments' => [],
            ], 200),
        ]);

        $result = $this->reconcile();

        $this->assertSame(1, $result['errors']);
        Http::assertSentCount(1);

        $attempt->refresh();
        $this->assertNull($attempt->provider_payment_id);
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('reconciliation_timeout', $attempt->failure_category);
        // Запрос случился — poll metadata продвинут ровно один раз.
        $this->assertSame(1, $attempt->metadata['poll_count']);
        $this->assertDatabaseCount('provider_events', 0);
        $this->assertCheckoutBlocked($attempt, true);
    }

    public function test_checkorder_transport_error_counts_error_and_advances_backoff(): void
    {
        $attempt = $this->createReleasedTimeoutAttempt(providerPaymentId: null);

        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response('server error', 500),
        ]);

        $result = $this->reconcile();

        $this->assertSame(1, $result['errors']);

        $attempt->refresh();
        $this->assertNull($attempt->provider_payment_id);
        $this->assertSame(1, $attempt->metadata['poll_count']);
        $this->assertDatabaseCount('provider_events', 0);
    }

    public function test_checkorder_non_335_error_code_counts_error(): void
    {
        $attempt = $this->createCheckoutAttempt(PaymentAttemptStatus::Created, providerPaymentId: null);

        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => false,
                'ErrorCode' => '101',
                'ErrorMessage' => 'Terminal not found',
            ], 200),
        ]);

        $result = $this->reconcile();

        $this->assertSame(1, $result['errors']);

        $attempt->refresh();
        $this->assertNull($attempt->provider_payment_id);
        $this->assertSame(PaymentAttemptStatus::Created, $attempt->status);
        $this->assertSame(1, $attempt->metadata['poll_count']);
    }

    // ── 4. Lock / cooldown / backoff ──

    public function test_recovery_respects_status_check_cooldown(): void
    {
        $attempt = $this->createReleasedTimeoutAttempt(
            providerPaymentId: null,
            metadata: [
                'payment_method' => 'card',
                'last_status_check_at' => now()->subSeconds(30)->toIso8601String(),
            ],
        );

        $this->fakeCheckOrder('CONFIRMED', $attempt);

        $result = $this->reconcile();

        $this->assertSame(0, $result['errors']);
        Http::assertNothingSent();

        $attempt->refresh();
        $this->assertNull($attempt->provider_payment_id);
        // Skip без HTTP: poll metadata не продвинут.
        $this->assertArrayNotHasKey('poll_count', $attempt->metadata);
    }

    public function test_recovery_respects_backoff_window(): void
    {
        $attempt = $this->createReleasedTimeoutAttempt(
            providerPaymentId: null,
            metadata: [
                'payment_method' => 'card',
                'poll_count' => 1,
                'last_polled_at' => now()->subSeconds(30)->toIso8601String(),
            ],
        );

        $this->fakeCheckOrder('CONFIRMED', $attempt);

        $result = $this->reconcile();

        $this->assertSame(0, $result['errors']);
        Http::assertNothingSent();

        $attempt->refresh();
        $this->assertNull($attempt->provider_payment_id);
        $this->assertSame(1, $attempt->metadata['poll_count']);
    }

    public function test_held_per_attempt_lock_prevents_checkorder(): void
    {
        $attempt = $this->createReleasedTimeoutAttempt(providerPaymentId: null);

        $lock = Cache::lock('billing:payment-status-check:'.$attempt->getKey(), 30);
        $this->assertTrue($lock->get());

        try {
            $this->fakeCheckOrder('CONFIRMED', $attempt);

            $result = $this->reconcile();

            $this->assertSame(0, $result['errors']);
            Http::assertNothingSent();

            $attempt->refresh();
            $this->assertNull($attempt->provider_payment_id);
        } finally {
            $lock->release();
        }

        // После освобождения lock та же попытка спрашивается нормально.
        $second = $this->reconcile();
        $this->assertSame(0, $second['errors']);
        Http::assertSentCount(1);

        $attempt->refresh();
        $this->assertNotNull($attempt->provider_payment_id);
    }

    public function test_second_run_after_elapsed_backoff_polls_once_more(): void
    {
        $attempt = $this->createReleasedTimeoutAttempt(
            providerPaymentId: null,
            metadata: [
                'payment_method' => 'card',
                'poll_count' => 1,
                'last_polled_at' => now()->subSeconds(120)->toIso8601String(),
            ],
        );

        $this->fakeCheckOrder('NEW', $attempt);

        $result = $this->reconcile();

        $this->assertSame(0, $result['errors']);
        Http::assertSentCount(1);

        $attempt->refresh();
        $this->assertSame(2, $attempt->metadata['poll_count']);
    }

    // ── 5. Renewal / mock исключены из recovery ──

    public function test_renewal_attempt_without_id_is_excluded_from_recovery(): void
    {
        // Released renewal без id: раньше не входил в timeout-очередь (требовался
        // id), теперь входит только как НЕ-кандидат — CheckOrder не шлётся.
        [$sub, $cycle, $released] = $this->createRenewalNoIdAttempt(
            PaymentAttemptStatus::FailedTerminal,
            released: true,
        );

        // In-flight renewal без id тоже не спрашивается через CheckOrder.
        [, , $inFlight] = $this->createRenewalNoIdAttempt(
            PaymentAttemptStatus::Created,
            released: false,
        );

        $this->fakeCheckOrder('CONFIRMED', $released);

        $result = $this->reconcile();

        $this->assertSame(0, $result['errors']);
        Http::assertNothingSent();

        $released->refresh();
        $this->assertNull($released->provider_payment_id);
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $released->status);
        $this->assertSame('reconciliation_timeout', $released->failure_category);

        $inFlight->refresh();
        $this->assertNull($inFlight->provider_payment_id);
        $this->assertSame(PaymentAttemptStatus::Created, $inFlight->status);
    }

    public function test_mock_provider_attempt_without_id_is_excluded_from_recovery(): void
    {
        $attempt = $this->createCheckoutAttempt(
            PaymentAttemptStatus::FailedTerminal,
            providerPaymentId: null,
            provider: 'mock',
            released: true,
        );

        $this->fakeCheckOrder('CONFIRMED', $attempt);

        $result = $this->reconcile();

        $this->assertSame(0, $result['errors']);
        Http::assertNothingSent();

        $attempt->refresh();
        $this->assertNull($attempt->provider_payment_id);
    }

    // ── 6. Гонки ──

    public function test_unique_violation_conflicts_after_rollback_without_transition(): void
    {
        // PaymentId уже занят ДРУГОЙ попыткой — DB-гарантия не допускает дубль.
        $holder = $this->createCheckoutAttempt(
            PaymentAttemptStatus::Processing,
            providerPaymentId: 'tbank_taken_123',
        );

        $attempt = $this->createReleasedTimeoutAttempt(providerPaymentId: null);

        $this->fakeCheckOrder('CONFIRMED', $attempt, [
            'PaymentId' => 'tbank_taken_123',
        ]);

        $result = $this->reconcile();

        // Конфликт не должен ронять прогон и не должен тронуть чужую попытку.
        $this->assertSame(0, $result['errors']);
        // CheckOrder для recovery + GetState для holder-попытки с id.
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/CheckOrder'));
        Http::assertSentCount(2);

        $attempt->refresh();
        $this->assertNull($attempt->provider_payment_id);
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('reconciliation_timeout', $attempt->failure_category);
        $this->assertDatabaseCount('provider_events', 0);

        $holder->refresh();
        $this->assertSame('tbank_taken_123', $holder->provider_payment_id);
        $this->assertSame(PaymentAttemptStatus::Processing, $holder->status);
    }

    public function test_webhook_bound_different_id_during_checkorder_is_conflict_without_transition(): void
    {
        $attempt = $this->createCheckoutAttempt(PaymentAttemptStatus::Created, providerPaymentId: null);

        Http::fake(function ($request) use ($attempt) {
            if (str_ends_with($request->url(), '/v2/CheckOrder')) {
                // Пока шёл HTTP, webhook успел привязать другой id.
                PaymentAttempt::whereKey($attempt->getKey())
                    ->update(['provider_payment_id' => 'tbank_webhook_777']);

                return Http::response([
                    'Success' => true,
                    'Payments' => [[
                        'PaymentId' => 'tbank_checkorder_888',
                        'OrderId' => $attempt->internal_order_id,
                        'Status' => 'CONFIRMED',
                        'Amount' => 49000,
                    ]],
                ], 200);
            }

            return Http::response(['Success' => false], 200);
        });

        $result = $this->reconcile();

        $this->assertSame(0, $result['errors']);

        $attempt->refresh();
        // Привязка webhook не перезаписана, transition recovery не выполнялся.
        $this->assertSame('tbank_webhook_777', $attempt->provider_payment_id);
        $this->assertSame(PaymentAttemptStatus::Created, $attempt->status);
        $this->assertDatabaseCount('provider_events', 0);
    }

    public function test_webhook_finished_attempt_during_http_is_not_rolled_back(): void
    {
        $attempt = $this->createCheckoutAttempt(PaymentAttemptStatus::Created, providerPaymentId: null);
        $recoveredId = 'tbank_recovered_1';

        Http::fake(function ($request) use ($attempt, $recoveredId) {
            if (str_ends_with($request->url(), '/v2/CheckOrder')) {
                // Пока шёл HTTP, webhook подтвердил ту же попытку с тем же id.
                app(PaymentTransitionService::class)->transition(new ProviderStatusUpdate(
                    provider: 'tbank',
                    normalizedStatus: PaymentAttemptStatus::Succeeded,
                    providerPaymentId: $recoveredId,
                    internalOrderId: $attempt->internal_order_id,
                    amount: $attempt->amount,
                    currency: $attempt->currency,
                    raw: ['Status' => 'CONFIRMED', 'PaymentId' => $recoveredId],
                ));
            }

            return Http::response([
                'Success' => true,
                'Payments' => [[
                    'PaymentId' => $recoveredId,
                    'OrderId' => $attempt->internal_order_id,
                    'Status' => 'CONFIRMED',
                    'Amount' => 49000,
                ]],
            ], 200);
        });

        $result = $this->reconcile();

        $this->assertSame(0, $result['errors']);

        $attempt->refresh();
        // Результат webhook не откачен: succeeded остаётся succeeded.
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
        $this->assertSame($recoveredId, $attempt->provider_payment_id);
        $this->assertNull($attempt->failure_category);

        $attempt->billingCycle->refresh();
        $this->assertSame(BillingCycleStatus::Paid, $attempt->billingCycle->status);
    }

    public function test_late_webhook_after_recovery_confirmed_does_not_reschedule_next_charge(): void
    {
        $sub = $this->sharedSubscription();
        $sub->forceFill([
            'status' => BillingSubscriptionStatus::Active,
            'renewal_period_months' => 1,
            'auto_renew_consent_at' => now(),
            'auto_renew_consent_version' => config('billing.recurring_terms_version'),
            'cancel_at_period_end' => false,
        ])->save();

        $attempt = $this->createCheckoutAttempt(
            PaymentAttemptStatus::Created,
            providerPaymentId: null,
            billingSubscription: $sub,
        );

        $this->fakeCheckOrder('CONFIRMED', $attempt);

        $result = $this->reconcile();
        $this->assertSame(0, $result['errors']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);

        $sub->refresh();
        $this->assertNotNull($sub->next_charge_at);
        $nextChargeAt = $sub->next_charge_at;
        $periodEnd = $sub->current_period_end;

        // Поздний webhook с тем же вердиктом: идемпотентный no-op.
        $late = app(PaymentGatewayManager::class)
            ->getGateway('tbank')
            ->normalizeWebhook([
                'PaymentId' => $attempt->provider_payment_id,
                'OrderId' => $attempt->internal_order_id,
                'Status' => 'CONFIRMED',
                'Amount' => 49000,
            ]);

        $transition = app(PaymentTransitionService::class)->transition($late);
        $this->assertTrue($transition['success']);

        $sub->refresh();
        // Продление подписки не повторяется: маркеры ровно те же.
        $this->assertTrue($sub->next_charge_at->equalTo($nextChargeAt));
        $this->assertTrue($sub->current_period_end->equalTo($periodEnd));
        $this->assertNull($sub->grace_until);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
    }

    public function test_age_release_is_not_repeated_while_recovery_polls_released_attempt(): void
    {
        $attempt = $this->createReleasedTimeoutAttempt(providerPaymentId: null);
        $finishedAt = $attempt->finished_at->copy();

        // Даже с нулевым age-окном released-попытка не проходит через
        // age-release второй раз: она больше не в ordinary-очереди.
        config(['billing.reconciliation.max_age_without_provider_id' => 0]);

        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => false,
                'ErrorCode' => '335',
            ], 200),
        ]);

        $result = $this->reconcile();

        $this->assertSame(0, $result['errors']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/CheckOrder'));

        $attempt->refresh();
        $this->assertTrue($attempt->finished_at->equalTo($finishedAt));
        $this->assertSame('reconciliation_timeout', $attempt->failure_category);
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
    }

    // ── 7. Прочие исходы ──

    public function test_amount_mismatch_recovery_binds_but_never_succeeds(): void
    {
        $attempt = $this->createCheckoutAttempt(PaymentAttemptStatus::Created, providerPaymentId: null);

        $this->fakeCheckOrder('CONFIRMED', $attempt, ['Amount' => 10000]);

        $result = $this->reconcile();

        $this->assertSame(0, $result['errors']);

        $attempt->refresh();
        // Id привязан, но успеха нет: валидация суммы отклонила вердикт.
        $this->assertNotNull($attempt->provider_payment_id);
        $this->assertSame(PaymentAttemptStatus::Created, $attempt->status);

        $attempt->billingCycle->refresh();
        $this->assertSame(BillingCycleStatus::Pending, $attempt->billingCycle->status);

        $event = ProviderEvent::first();
        $this->assertNotNull($event);
        $this->assertSame('amount_mismatch', $event->processing_error);
    }

    public function test_answer_with_foreign_order_id_is_rejected_without_binding(): void
    {
        $attempt = $this->createCheckoutAttempt(PaymentAttemptStatus::Created, providerPaymentId: null);

        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => true,
                'Payments' => [[
                    'PaymentId' => 'tbank_foreign_1',
                    'OrderId' => 'some_other_order',
                    'Status' => 'CONFIRMED',
                    'Amount' => 49000,
                ]],
            ], 200),
        ]);

        $result = $this->reconcile();

        $this->assertSame(0, $result['errors']);

        $attempt->refresh();
        $this->assertNull($attempt->provider_payment_id);
        $this->assertSame(PaymentAttemptStatus::Created, $attempt->status);
        $this->assertDatabaseCount('provider_events', 0);
        // Запрос случился — backoff продвинут.
        $this->assertSame(1, $attempt->metadata['poll_count']);
    }

    public function test_without_id_checkorder_with_id_getstate_in_one_pass(): void
    {
        $noId = $this->createCheckoutAttempt(PaymentAttemptStatus::Created, providerPaymentId: null);
        $withId = $this->createCheckoutAttempt(
            PaymentAttemptStatus::Processing,
            providerPaymentId: 'tbank_has_1',
        );

        Http::fake(function ($request) use ($noId, $withId) {
            if (str_ends_with($request->url(), '/v2/CheckOrder')) {
                return Http::response([
                    'Success' => true,
                    'Payments' => [[
                        'PaymentId' => 'tbank_recovered_2',
                        'OrderId' => $noId->internal_order_id,
                        'Status' => 'NEW',
                        'Amount' => 49000,
                    ]],
                ], 200);
            }

            return Http::response([
                'Success' => true,
                'PaymentId' => $withId->provider_payment_id,
                'OrderId' => $withId->internal_order_id,
                'Status' => 'AUTHORIZED',
                'Amount' => 49000,
            ], 200);
        });

        $result = $this->reconcile();

        $this->assertSame(0, $result['errors']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/CheckOrder'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/GetState'));

        $noId->refresh();
        $this->assertSame('tbank_recovered_2', $noId->provider_payment_id);
        $this->assertSame(PaymentAttemptStatus::Created, $noId->status);

        $withId->refresh();
        $this->assertSame('tbank_has_1', $withId->provider_payment_id);
        $this->assertSame(PaymentAttemptStatus::Processing, $withId->status);
    }

    public function test_released_attempt_with_id_keeps_getstate_path(): void
    {
        $attempt = $this->createReleasedTimeoutAttempt(providerPaymentId: 'tbank_has_9');

        $this->fakeGetState('CONFIRMED', $attempt);

        $result = $this->reconcile();

        $this->assertSame(0, $result['errors']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/GetState'));
        Http::assertSentCount(1);

        $attempt->refresh();
        $this->assertSame('tbank_has_9', $attempt->provider_payment_id);
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
    }

    // ── Helpers ──

    private function reconcile(): array
    {
        return app(PaymentReconciliationService::class)->reconcile();
    }

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
     * so a confirmed payment grants a horizon like a real checkout would.
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
     * Checkout attempt of the ordinary queue. Young enough (10 min) to stay
     * under the 30-minute no-id age window, so it is polled instead of
     * age-released.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function createCheckoutAttempt(
        PaymentAttemptStatus $status,
        ?string $providerPaymentId,
        string $provider = 'tbank',
        array $metadata = ['payment_method' => 'card'],
        bool $released = false,
        ?BillingSubscription $billingSubscription = null,
    ): PaymentAttempt {
        $subscription = $billingSubscription ?? $this->sharedSubscription();
        [$periodStart, $periodEnd] = $this->nextCyclePeriod();

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $subscription->id,
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => $released ? BillingCycleStatus::Failed : BillingCycleStatus::Pending,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Payment,
        ]);

        $attempt = new PaymentAttempt([
            'billing_cycle_id' => $cycle->id,
            'provider' => $provider,
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'core_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => $providerPaymentId,
            'status' => $released ? PaymentAttemptStatus::FailedTerminal : $status,
            'failure_category' => $released ? 'reconciliation_timeout' : null,
            'finished_at' => $released ? now()->subMinutes(30) : null,
            'initiated_at' => now()->subMinutes(70),
            'metadata' => $metadata,
        ]);
        $attempt->created_at = $released ? now()->subMinutes(40) : now()->subMinutes(10);
        $attempt->save();

        return $attempt;
    }

    /**
     * Age-released checkout attempt: failed_terminal + reconciliation_timeout,
     * optionally WITHOUT a provider payment id — the CheckOrder recovery
     * target. Blocks the new checkout through the existing timeout finder.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function createReleasedTimeoutAttempt(
        ?string $providerPaymentId = null,
        array $metadata = ['payment_method' => 'card'],
    ): PaymentAttempt {
        return $this->createCheckoutAttempt(
            PaymentAttemptStatus::FailedTerminal,
            $providerPaymentId,
            metadata: $metadata,
            released: true,
        );
    }

    /**
     * Renewal attempt without a PaymentId (metadata.renewal + Renewal origin).
     * The subscription is shared (unique on workspace+plan); cycles get
     * unique periods via nextCyclePeriod().
     *
     * @return array{0: BillingSubscription, 1: BillingCycle, 2: PaymentAttempt}
     */
    private function createRenewalNoIdAttempt(
        PaymentAttemptStatus $status,
        bool $released,
    ): array {
        $sub = BillingSubscription::firstOrCreate([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
        ], [
            'status' => BillingSubscriptionStatus::Active,
            'renewal_period_months' => 1,
            'auto_renew_consent_at' => now()->subDays(2),
            'auto_renew_consent_version' => config('billing.recurring_terms_version'),
            'cancel_at_period_end' => false,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->subDay(),
            'next_charge_at' => now()->subDay(),
            'grace_until' => null,
        ]);

        [$periodStart, $periodEnd] = $this->nextCyclePeriod();

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => BillingCycleStatus::Failed,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Renewal,
        ]);

        $attempt = new PaymentAttempt([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'tbank',
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'renew_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => null,
            'status' => $released ? PaymentAttemptStatus::FailedTerminal : $status,
            'failure_category' => $released ? 'reconciliation_timeout' : null,
            'finished_at' => $released ? now()->subMinutes(30) : null,
            'initiated_at' => now()->subMinutes($released ? 70 : 20),
            'metadata' => [
                'renewal' => true,
                'billing_subscription_id' => $sub->id,
            ],
        ]);
        // Released-строки старше 30-минутного окна; in-flight — моложе,
        // чтобы ordinary-очередь поллила их, а не age-released.
        $attempt->created_at = now()->subMinutes($released ? 40 : 10);
        $attempt->save();

        return [$sub, $cycle, $attempt];
    }

    /**
     * CheckOrder answer straight from the official contract: Success +
     * Payments[0] with PaymentId + OrderId + Status (+ Amount in kopecks).
     * Any other path (e.g. GetState of a neighbour attempt) gets a neutral
     * Success=false so it resolves to "no answer" without side effects.
     *
     * @param  array<string, mixed>  $paymentOverrides
     */
    private function fakeCheckOrder(string $status, PaymentAttempt $attempt, array $paymentOverrides = []): void
    {
        Http::fake(function ($request) use ($status, $attempt, $paymentOverrides) {
            if (str_ends_with($request->url(), '/v2/CheckOrder')) {
                return Http::response([
                    'Success' => true,
                    'Payments' => [array_merge([
                        'PaymentId' => 'tbank_recovered_1',
                        'OrderId' => $attempt->internal_order_id,
                        'Status' => $status,
                        'Amount' => 49000,
                        'ErrorCode' => '0',
                    ], $paymentOverrides)],
                ], 200);
            }

            return Http::response(['Success' => false], 200);
        });
    }

    private function fakeGetState(string $status, PaymentAttempt $attempt): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => true,
                'PaymentId' => $attempt->provider_payment_id,
                'OrderId' => $attempt->internal_order_id,
                'Status' => $status,
                'Amount' => 49000,
                'ErrorCode' => '0',
            ], 200),
        ]);
    }

    private function assertCheckoutBlocked(PaymentAttempt $attempt, bool $blocked): void
    {
        $inFlight = app(BillingCoreWriter::class)->findExistingInFlightAttempt(
            $this->workspace->id,
            $this->proPlan->id,
        );

        if ($blocked) {
            $this->assertNotNull($inFlight);
        } else {
            $this->assertNull($inFlight);
        }
    }
}
