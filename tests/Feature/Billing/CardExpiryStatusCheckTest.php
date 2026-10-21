<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentEnvironment;
use App\Enums\UserRole;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\PaymentAttempt;
use App\Models\PlanPrice;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingCoreWriter;
use App\Services\Payment\DTOs\ProviderStatusUpdate;
use App\Services\Payment\PaymentGatewayInterface;
use App\Services\Payment\PaymentReconciliationService;
use App\Services\Payment\PaymentTransitionService;
use App\Services\Payment\TBankPaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Карточный срок → CheckOrder после истечения RedirectDueDate.
 *
 * Банк подтвердил опцию: CheckOrder возвращает DEADLINE_EXPIRED только для
 * неоплаченного платежа с истёкшим сроком; успешно оплаченный платёж
 * продолжает отдаваться со своим успешным статусом. Поэтому после
 * достоверно наступившего card_expires_at попытка спрашивается через
 * CheckOrder по internal_order_id (даже с известным PaymentId), до срока —
 * прежний GetState. Финальный вердикт идёт только через существующий
 * PaymentTransitionService; нетерминальные ответы не разблокируют новую
 * оплату; своевременность обеспечивает card_expiry_polled_at one-shot по
 * образцу SBP-механизма.
 */
class CardExpiryStatusCheckTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.legacy_mock_webhook_secret' => 'test_secret_123']);
        config(['billing.reconciliation' => [
            'batch_size' => 50,
            'timeout_batch_size' => 10,
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

        // BillingService ходит в реальный T-Bank gateway с Http::fake.
        $this->app->instance(PaymentGatewayInterface::class, new TBankPaymentGateway(
            terminalKey: 'TestTerminal',
            password: 'test-password',
            baseUrl: 'https://securepay.tinkoff.ru',
        ));
    }

    // ── Helpers ──

    /**
     * Карточная попытка инцидента: старый backoff ещё держит строку
     * (poll_count=4 → 900s не истёк), а абсолютный срок уже наступил.
     *
     * Значение null в overrides удаляет ключ из metadata (потерянный Init:
     * без payment_method / checkout_url). $attributes переопределяет
     * колонки (status, failure_category, provider_payment_id, provider,
     * payment_env).
     *
     * @param  array<string, mixed>  $metadataOverrides
     * @param  array<string, mixed>  $attributes
     */
    private function createCardAttempt(array $metadataOverrides = [], array $attributes = []): PaymentAttempt
    {
        $subscription = BillingSubscription::create([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::PendingInitial,
        ]);

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $subscription->id,
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now(),
            'period_end' => now()->addMonth(),
            'status' => BillingCycleStatus::Pending,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Payment,
        ]);

        $metadata = array_merge([
            'payment_method' => 'card',
            'checkout_url' => 'https://securepay.tinkoff.ru/pay?card=1',
            'card_expires_at' => now()->subMinutes(2)->toIso8601String(),
            // Инцидент: старый backoff ещё держит попытку
            'poll_count' => 4,
            'last_polled_at' => now()->subMinutes(5)->toIso8601String(),
        ], $metadataOverrides);

        foreach ($metadataOverrides as $key => $value) {
            if ($value === null) {
                unset($metadata[$key]);
            }
        }

        $attempt = new PaymentAttempt(array_merge([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'tbank',
            'payment_env' => PaymentEnvironment::Live,
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'core_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => 'tbank_card_1',
            'status' => PaymentAttemptStatus::Processing,
            'initiated_at' => now()->subHour(),
            'metadata' => $metadata,
        ], $attributes));
        $attempt->created_at = now()->subHour();
        $attempt->save();

        return $attempt;
    }

    /**
     * Нейтральный банк: CheckOrder и GetState отвечают NEW, Init — успех.
     * Тело CheckOrder и статус GetState переопределяются per-test.
     *
     * @param  array<string, mixed>  $checkOrderBody
     * @param  array<string, mixed>  $initBody
     */
    private function fakeBank(
        ?PaymentAttempt $attempt = null,
        array $checkOrderBody = [],
        string $getStateStatus = 'NEW',
        array $initBody = [],
    ): void {
        $defaultPayment = [
            'PaymentId' => $attempt?->provider_payment_id,
            'OrderId' => $attempt?->internal_order_id,
            'Status' => 'NEW',
            'Amount' => 49000,
        ];

        $checkOrderBody = $checkOrderBody + [
            'Success' => true,
            'Payments' => [$defaultPayment],
        ];

        $initBody = $initBody + [
            'Success' => true,
            'PaymentId' => '700199999',
            'PaymentURL' => 'https://securepay.tinkoff.ru/pay?paymentId=700199999',
        ];

        Http::fake(function ($request) use ($checkOrderBody, $getStateStatus, $initBody) {
            $url = $request->url();

            if (str_ends_with($url, '/v2/CheckOrder')) {
                return Http::response($checkOrderBody, 200);
            }

            if (str_ends_with($url, '/v2/GetState')) {
                return Http::response([
                    'Success' => true,
                    'Status' => $getStateStatus,
                    'Amount' => 49000,
                ], 200);
            }

            if (str_ends_with($url, '/v2/Init')) {
                return Http::response($initBody, 200);
            }

            return Http::response(['Success' => false], 200);
        });
    }

    private function requestCount(string $endpoint): int
    {
        return Http::recorded()
            ->filter(fn (array $pair) => str_ends_with($pair[0]->url(), $endpoint))
            ->count();
    }

    private function assertCheckoutBlocked(PaymentAttempt $attempt): void
    {
        $inFlight = app(BillingCoreWriter::class)->findExistingInFlightAttempt(
            $attempt->billingCycle->workspace_id,
            $attempt->billingCycle->tariff_plan_id,
        );

        $this->assertNotNull($inFlight, 'Попытка обязана блокировать новый checkout');
    }

    private function assertCheckoutUnblocked(PaymentAttempt $attempt): void
    {
        $inFlight = app(BillingCoreWriter::class)->findExistingInFlightAttempt(
            $attempt->billingCycle->workspace_id,
            $attempt->billingCycle->tariff_plan_id,
        );

        $this->assertNull($inFlight, 'Подтверждённый вердикт обязан снимать блок');
    }

    // ── §2: до срока GetState, после срока CheckOrder ──

    public function test_check_before_deadline_uses_getstate_and_switches_to_checkorder_after(): void
    {
        $attempt = $this->createCardAttempt([
            'card_expires_at' => now()->addMinutes(10)->toIso8601String(),
        ]);
        $this->fakeBank($attempt);

        // До срока — прежний GetState, CheckOrder не трогается
        $this->actingAs($this->owner)
            ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
            ->assertOk()
            ->assertExactJson(['status' => 'processing', 'checked' => true]);

        $this->assertSame(1, $this->requestCount('/v2/GetState'));
        $this->assertSame(0, $this->requestCount('/v2/CheckOrder'));

        // После срока (и вне cooldown) — CheckOrder по OrderId, без GetState
        $this->travel(11)->minutes();

        $this->actingAs($this->owner)
            ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
            ->assertOk()
            ->assertExactJson(['status' => 'processing', 'checked' => true]);

        $this->assertSame(1, $this->requestCount('/v2/GetState'));
        $this->assertSame(1, $this->requestCount('/v2/CheckOrder'));
        $this->travelBack();
    }

    public function test_expired_checkorder_with_known_payment_id_validates_identity(): void
    {
        $attempt = $this->createCardAttempt();
        // Чужой PaymentId при нашем OrderId — ответ не применяется
        $this->fakeBank($attempt, [
            'Success' => true,
            'Payments' => [[
                'PaymentId' => 'tbank_foreign_999',
                'OrderId' => $attempt->internal_order_id,
                'Status' => 'DEADLINE_EXPIRED',
                'Amount' => 49000,
            ]],
        ]);

        $this->actingAs($this->owner)
            ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
            ->assertOk()
            // Отклонено: ни отказа, ни смены идентичности
            ->assertExactJson(['status' => 'processing', 'checked' => true]);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        $this->assertSame('tbank_card_1', $attempt->provider_payment_id);
        $this->assertDatabaseCount('provider_events', 0);
        $this->assertSame(1, $this->requestCount('/v2/CheckOrder'));
    }

    public function test_expired_checkorder_with_foreign_order_id_is_rejected(): void
    {
        $attempt = $this->createCardAttempt();
        $this->fakeBank($attempt, [
            'Success' => true,
            'Payments' => [[
                'PaymentId' => 'tbank_card_1',
                'OrderId' => 'core_totally_other_order',
                'Status' => 'DEADLINE_EXPIRED',
                'Amount' => 49000,
            ]],
        ]);

        $this->actingAs($this->owner)
            ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
            ->assertOk()
            ->assertExactJson(['status' => 'processing', 'checked' => true]);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        $this->assertDatabaseCount('provider_events', 0);
    }

    // ── §2: потерянный Init — без payment_method, восстановление живёт ──

    public function test_lost_init_without_payment_method_recovers_via_checkorder_after_deadline(): void
    {
        // Phase A записала срок, Phase C (с методом и ссылкой) не прошла:
        // created_at моложе age-limit, но срок уже наступил.
        $attempt = $this->createCardAttempt([
            'payment_method' => null,
            'checkout_url' => null,
            'card_expires_at' => now()->subMinutes(2)->toIso8601String(),
        ], [
            'status' => PaymentAttemptStatus::Created,
            'provider_payment_id' => null,
        ]);
        $attempt->created_at = now()->subMinutes(25);
        $attempt->save();

        $this->fakeBank($attempt, [
            'Success' => true,
            'Payments' => [[
                'PaymentId' => 'tbank_recovered_1',
                'OrderId' => $attempt->internal_order_id,
                'Status' => 'CONFIRMED',
                'Amount' => 49000,
            ]],
        ]);

        app(PaymentReconciliationService::class)->reconcile();

        // Привязка под row-lock + штатный успех; способ по-прежнему не угадан
        $attempt->refresh();
        $this->assertSame('tbank_recovered_1', $attempt->provider_payment_id);
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
        $this->assertArrayNotHasKey('payment_method', $attempt->metadata);
        $this->assertSame(1, $this->requestCount('/v2/CheckOrder'));
        $this->assertSame(0, $this->requestCount('/v2/GetState'));
        $this->assertNotEmpty($attempt->metadata['card_expiry_polled_at']);
    }

    // ── §2: вердикты через существующий PaymentTransitionService ──

    public function test_deadline_expired_is_a_confirmed_decline_and_unblocks_checkout(): void
    {
        $attempt = $this->createCardAttempt();
        $this->fakeBank($attempt, [
            'Success' => true,
            'Payments' => [[
                'PaymentId' => 'tbank_card_1',
                'OrderId' => $attempt->internal_order_id,
                'Status' => 'DEADLINE_EXPIRED',
                'Amount' => 49000,
            ]],
        ]);

        $this->actingAs($this->owner)
            ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
            ->assertOk()
            // Подтверждённый отказ — без undefined_outcome
            ->assertExactJson(['status' => 'failed_terminal', 'checked' => true]);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('provider_failed', $attempt->failure_category);
        $this->assertCheckoutUnblocked($attempt);

        // Новая оплата после подтверждённого отказа штатно возможна
        $this->actingAs($this->owner)
            ->post('/admin/checkout', [
                'tariff_plan_id' => $this->proPlan->id,
                'period_months' => 1,
                'payment_method' => 'card',
            ])
            ->assertOk();

        $this->assertDatabaseCount('payment_attempts', 2);
        // Существующая metadata попытки не потеряна
        $this->assertSame('card', $attempt->metadata['payment_method']);
    }

    public function test_confirmed_succeeds_exactly_once_without_duplicating_the_receipt(): void
    {
        $attempt = $this->createCardAttempt();
        $this->fakeBank($attempt, [
            'Success' => true,
            'Payments' => [[
                'PaymentId' => 'tbank_card_1',
                'OrderId' => $attempt->internal_order_id,
                'Status' => 'CONFIRMED',
                'Amount' => 49000,
            ]],
        ]);

        $this->actingAs($this->owner)
            ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
            ->assertOk()
            ->assertExactJson(['status' => 'succeeded', 'checked' => true]);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
        $this->assertSame(BillingCycleStatus::Paid, $attempt->billingCycle->status);
        $this->assertSame(BillingSubscriptionStatus::Active, $attempt->billingCycle->billingSubscription->status);
        $this->assertDatabaseCount('payment_receipts', 1);
        $this->assertSame(1, $this->requestCount('/v2/CheckOrder'));

        // Подтверждённая финальная попытка повторно не опрашивается —
        // ни новый HTTP, ни второй чек
        $this->travel(61)->seconds();

        $this->actingAs($this->owner)
            ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
            ->assertOk()
            ->assertExactJson(['status' => 'succeeded', 'checked' => false]);

        $this->assertSame(1, $this->requestCount('/v2/CheckOrder'));
        $this->assertDatabaseCount('payment_receipts', 1);
        $this->travelBack();
    }

    public function test_repeat_success_with_a_new_payload_keeps_one_receipt(): void
    {
        $attempt = $this->createCardAttempt();
        $this->fakeBank($attempt, [
            'Success' => true,
            'Payments' => [[
                'PaymentId' => 'tbank_card_1',
                'OrderId' => $attempt->internal_order_id,
                'Status' => 'CONFIRMED',
                'Amount' => 49000,
            ]],
        ]);

        $this->actingAs($this->owner)
            ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
            ->assertOk()
            ->assertJson(['status' => 'succeeded']);
        $this->assertDatabaseCount('payment_receipts', 1);

        // Поздний webhook CONFIRMED с другим payload — чек не дублируется
        app(PaymentTransitionService::class)->transition(new ProviderStatusUpdate(
            provider: 'tbank',
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            providerPaymentId: 'tbank_card_1',
            internalOrderId: $attempt->internal_order_id,
            amount: 490,
            currency: 'RUB',
            raw: ['Status' => 'CONFIRMED', 'PaymentId' => 'tbank_card_1', 'EchoId' => 'webhook-2'],
        ));

        $this->assertDatabaseCount('payment_receipts', 1);
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->refresh()->status);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function nonFinalAnswers(): array
    {
        return [
            'NEW' => [[
                'Success' => true,
                'Payments' => [[
                    'PaymentId' => 'tbank_card_1',
                    'OrderId' => 'PLACEHOLDER',
                    'Status' => 'NEW',
                    'Amount' => 49000,
                ]],
            ]],
            'AUTHORIZED' => [[
                'Success' => true,
                'Payments' => [[
                    'PaymentId' => 'tbank_card_1',
                    'OrderId' => 'PLACEHOLDER',
                    'Status' => 'AUTHORIZED',
                    'Amount' => 49000,
                ]],
            ]],
            '335' => [['Success' => false, 'ErrorCode' => '335']],
            'null' => [['Success' => false]],
            'ambiguity' => [['Success' => true, 'Payments' => []]],
        ];
    }

    /**
     * @param  array<string, mixed>  $checkOrderBody
     */
    #[DataProvider('nonFinalAnswers')]
    public function test_non_final_answers_keep_the_waiting_state_and_the_checkout_block(array $checkOrderBody): void
    {
        $attempt = $this->createCardAttempt();
        // Плейсхолдер OrderId в статическом списке заменяется на OrderId
        // этой попытки: ответ проходит проверку идентичности и остаётся
        // нетерминальным, а не отклоняется по несовпадению
        $checkOrderBody = json_decode(
            str_replace('PLACEHOLDER', $attempt->internal_order_id, json_encode($checkOrderBody)),
            true,
        );
        $this->fakeBank($attempt, $checkOrderBody);

        $this->assertCheckoutBlocked($attempt);

        $this->actingAs($this->owner)
            ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
            ->assertOk()
            ->assertExactJson(['status' => 'processing', 'checked' => true]);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        $this->assertDatabaseCount('provider_events', 0);
        $this->assertCheckoutBlocked($attempt);
        // Ни одного Init — только спрос банка о статусе
        $this->assertSame(0, $this->requestCount('/v2/Init'));
    }

    public function test_transport_error_keeps_waiting_and_consumes_the_one_shot(): void
    {
        $attempt = $this->createCardAttempt();
        Http::fake(function () {
            throw new \RuntimeException('network down');
        });

        app(PaymentReconciliationService::class)->reconcile();

        // Транспортная ошибка — не вердикт; фактический запрос БЫЛ, поэтому
        // one-shot потреблён и следующие прогоны держат банк закрытым
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        $this->assertSame(5, $attempt->metadata['poll_count']);
        $this->assertNotEmpty($attempt->metadata['card_expiry_polled_at']);
        $this->assertCheckoutBlocked($attempt);

        app(PaymentReconciliationService::class)->reconcile();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->refresh()->status);
        $this->assertSame(5, $attempt->refresh()->metadata['poll_count']);
    }

    public function test_webhook_success_during_checkorder_http_is_not_rolled_back(): void
    {
        $attempt = $this->createCardAttempt();

        Http::fake(function ($request) use ($attempt) {
            if (str_ends_with($request->url(), '/v2/CheckOrder')) {
                // Пока шёл HTTP, webhook подтвердил ту же попытку
                app(PaymentTransitionService::class)->transition(new ProviderStatusUpdate(
                    provider: 'tbank',
                    normalizedStatus: PaymentAttemptStatus::Succeeded,
                    providerPaymentId: 'tbank_card_1',
                    internalOrderId: $attempt->internal_order_id,
                    amount: 490,
                    currency: 'RUB',
                    raw: ['Status' => 'CONFIRMED', 'PaymentId' => 'tbank_card_1'],
                ));

                return Http::response([
                    'Success' => true,
                    'Payments' => [[
                        'PaymentId' => 'tbank_card_1',
                        'OrderId' => $attempt->internal_order_id,
                        'Status' => 'DEADLINE_EXPIRED',
                        'Amount' => 49000,
                    ]],
                ], 200);
            }

            return Http::response(['Success' => false], 200);
        });

        $this->actingAs($this->owner)
            ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
            ->assertOk()
            // Поздний отказ НЕ откатывает подтверждённый успех
            ->assertExactJson(['status' => 'succeeded', 'checked' => true]);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
        $this->assertNull($attempt->failure_category);
        $this->assertSame(BillingCycleStatus::Paid, $attempt->billingCycle->status);
    }

    // ── §3: card expiry one-shot ──

    public function test_expiry_one_shot_bypasses_the_old_backoff_and_runs_exactly_once(): void
    {
        $attempt = $this->createCardAttempt();
        $this->fakeBank($attempt);

        app(PaymentReconciliationService::class)->reconcile();

        // Один своевременный CheckOrder, несмотря на poll_count=4/900s backoff
        $this->assertSame(1, $this->requestCount('/v2/CheckOrder'));
        $this->assertSame(0, $this->requestCount('/v2/GetState'));

        $attempt->refresh();
        $metadata = $attempt->metadata;
        // poll_count не сброшен — увеличен ровно на 1; one-shot потреблён
        $this->assertSame(5, $metadata['poll_count']);
        $this->assertNotEmpty($metadata['card_expiry_polled_at']);
        $this->assertNotEmpty($metadata['last_polled_at']);
        // NEW — не финальный вердикт: попытка продолжает ждать
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        // Существующие ключи metadata целы
        $this->assertSame('card', $metadata['payment_method']);
        $this->assertNotEmpty($metadata['checkout_url']);

        // Следующие прогоны: one-shot потреблён, обычный backoff держит банк
        app(PaymentReconciliationService::class)->reconcile();
        app(PaymentReconciliationService::class)->reconcile();
        $this->assertSame(1, $this->requestCount('/v2/CheckOrder'));
    }

    public function test_one_shot_waits_for_the_deadline(): void
    {
        $attempt = $this->createCardAttempt([
            'card_expires_at' => now()->addMinutes(10)->toIso8601String(),
        ]);
        $this->fakeBank($attempt);

        app(PaymentReconciliationService::class)->reconcile();

        // До дедлайна действует только старый backoff — он ещё не истёк
        $this->assertSame(0, $this->requestCount('/v2/CheckOrder'));
        $this->assertSame(0, $this->requestCount('/v2/GetState'));
        $this->assertArrayNotHasKey('card_expiry_polled_at', $attempt->refresh()->metadata);
    }

    public function test_lock_hold_skip_does_not_consume_the_one_shot(): void
    {
        $attempt = $this->createCardAttempt();
        $this->fakeBank($attempt);

        $lock = Cache::lock('billing:payment-status-check:'.$attempt->getKey(), 30);
        $this->assertTrue($lock->get());

        try {
            app(PaymentReconciliationService::class)->reconcile();
        } finally {
            $lock->release();
        }

        // Пропуск из-за lock: ни HTTP, ни расхода one-shot
        Http::assertNothingSent();
        $metadata = $attempt->refresh()->metadata;
        $this->assertArrayNotHasKey('card_expiry_polled_at', $metadata);
        $this->assertSame(4, $metadata['poll_count']);

        // Освободили — ближайший проход дорывается до банка и потребляет one-shot
        app(PaymentReconciliationService::class)->reconcile();
        $this->assertSame(1, $this->requestCount('/v2/CheckOrder'));
        $this->assertArrayHasKey('card_expiry_polled_at', $attempt->refresh()->metadata);
    }

    public function test_manual_check_and_bypass_share_one_cooldown(): void
    {
        $attempt = $this->createCardAttempt();
        $this->fakeBank($attempt);

        // Ручная проверка первой забирает cooldown — cron в это же окно не
        // ходит в банк и one-shot не потребляет
        $this->actingAs($this->owner)
            ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
            ->assertOk()
            ->assertJson(['checked' => true]);
        $this->assertSame(1, $this->requestCount('/v2/CheckOrder'));

        app(PaymentReconciliationService::class)->reconcile();
        $this->assertSame(1, $this->requestCount('/v2/CheckOrder'));
        $this->assertArrayNotHasKey('card_expiry_polled_at', $attempt->refresh()->metadata);

        // Окно cooldown истекло — фоновая проверка доходит до банка
        // и потребляет one-shot
        $this->travel(61)->seconds();
        app(PaymentReconciliationService::class)->reconcile();
        $this->assertSame(2, $this->requestCount('/v2/CheckOrder'));
        $this->assertArrayHasKey('card_expiry_polled_at', $attempt->refresh()->metadata);
        $this->travelBack();
    }

    // ── §2/§3: age-released очередь ──

    public function test_released_timeout_card_attempt_is_checked_past_deadline_despite_backoff(): void
    {
        $attempt = $this->createCardAttempt(
            [
                'poll_count' => 6,
                'last_polled_at' => now()->subMinutes(5)->toIso8601String(),
            ],
            [
                'status' => PaymentAttemptStatus::FailedTerminal,
                'failure_category' => 'reconciliation_timeout',
            ],
        );
        $this->fakeBank($attempt, [
            'Success' => true,
            'Payments' => [[
                'PaymentId' => 'tbank_card_1',
                'OrderId' => $attempt->internal_order_id,
                'Status' => 'DEADLINE_EXPIRED',
                'Amount' => 49000,
            ]],
        ]);

        $this->assertCheckoutBlocked($attempt);

        app(PaymentReconciliationService::class)->reconcile();

        // backoff[5]=3600s не истёк, но срок карточки дал one-shot
        $this->assertSame(1, $this->requestCount('/v2/CheckOrder'));
        $this->assertSame(0, $this->requestCount('/v2/GetState'));

        $attempt->refresh();
        // Вердикт банка заменил локальный timeout штатным переходом
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('provider_failed', $attempt->failure_category);
        $this->assertNotEmpty($attempt->metadata['card_expiry_polled_at']);
        $this->assertCheckoutUnblocked($attempt);
    }

    public function test_released_card_attempt_new_answer_keeps_the_timeout_and_the_block(): void
    {
        $attempt = $this->createCardAttempt([], [
            'status' => PaymentAttemptStatus::FailedTerminal,
            'failure_category' => 'reconciliation_timeout',
        ]);
        $this->fakeBank($attempt);

        app(PaymentReconciliationService::class)->reconcile();

        $this->assertSame(1, $this->requestCount('/v2/CheckOrder'));
        $attempt->refresh();
        // NEW не терминален: локальный timeout и его блок переживают проверку
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('reconciliation_timeout', $attempt->failure_category);
        $this->assertNotEmpty($attempt->metadata['card_expiry_polled_at']);
        $this->assertCheckoutBlocked($attempt);
    }

    public function test_released_attempt_without_card_deadline_keeps_the_ordinary_backoff(): void
    {
        $attempt = $this->createCardAttempt(
            [
                'card_expires_at' => null,
                'poll_count' => 6,
                'last_polled_at' => now()->subMinutes(5)->toIso8601String(),
            ],
            [
                'status' => PaymentAttemptStatus::FailedTerminal,
                'failure_category' => 'reconciliation_timeout',
            ],
        );
        $this->fakeBank($attempt);

        app(PaymentReconciliationService::class)->reconcile();

        // Старая попытка без срока — прежний backoff, банк не тревожим
        $this->assertSame(0, $this->requestCount('/v2/CheckOrder'));
        $this->assertSame(0, $this->requestCount('/v2/GetState'));
        $this->assertArrayNotHasKey('card_expiry_polled_at', $attempt->refresh()->metadata);
    }

    // ── §5: исключения — SBP, mock, renewal, некорректный срок ──

    public function test_sbp_attempt_past_its_deadline_still_polls_getstate(): void
    {
        $attempt = $this->createCardAttempt([
            'payment_method' => 'sbp',
            'checkout_url' => null,
            'sbp_payload' => 'https://qr.nspk.ru/SAMEATTEMPT',
            'sbp_expires_at' => now()->subMinutes(5)->toIso8601String(),
            'card_expires_at' => null,
            'poll_count' => 0,
            'last_polled_at' => null,
        ]);
        $this->fakeBank($attempt);

        app(PaymentReconciliationService::class)->reconcile();

        // SBP-поведение не тронуто: его собственный bypass спрашивает GetState
        $this->assertSame(1, $this->requestCount('/v2/GetState'));
        $this->assertSame(0, $this->requestCount('/v2/CheckOrder'));
        $this->assertArrayNotHasKey('card_expiry_polled_at', $attempt->refresh()->metadata);
    }

    public function test_mock_attempt_is_never_sent_to_checkorder(): void
    {
        $attempt = $this->createCardAttempt([
            'poll_count' => 0,
            'last_polled_at' => null,
        ], ['provider' => 'mock']);
        Http::fake();

        app(PaymentReconciliationService::class)->reconcile();

        // Mock-шлюз внешнего банка не спрашивает и CheckOrder не касается
        Http::assertNothingSent();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->refresh()->status);
    }

    public function test_renewal_attempt_with_card_deadline_keeps_its_own_path(): void
    {
        $attempt = $this->createCardAttempt([
            'renewal' => true,
            'poll_count' => 0,
            'last_polled_at' => null,
        ]);
        $this->fakeBank($attempt);

        app(PaymentReconciliationService::class)->reconcile();

        // Renewal не переводится на карточный механизм — прежний GetState
        $this->assertSame(1, $this->requestCount('/v2/GetState'));
        $this->assertSame(0, $this->requestCount('/v2/CheckOrder'));
        $this->assertArrayNotHasKey('card_expiry_polled_at', $attempt->refresh()->metadata);
    }

    public function test_invalid_card_deadline_keeps_getstate(): void
    {
        $attempt = $this->createCardAttempt([
            'card_expires_at' => 'не-дата',
            'poll_count' => 0,
            'last_polled_at' => null,
        ]);
        $this->fakeBank($attempt);

        app(PaymentReconciliationService::class)->reconcile();

        // Некорректный срок — не доказательство отказа и не повод для
        // CheckOrder: строка идёт прежним путём
        $this->assertSame(1, $this->requestCount('/v2/GetState'));
        $this->assertSame(0, $this->requestCount('/v2/CheckOrder'));
        $this->assertArrayNotHasKey('card_expiry_polled_at', $attempt->refresh()->metadata);
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->refresh()->status);
    }

    public function test_contradictory_sbp_data_on_a_card_row_fails_closed_to_getstate(): void
    {
        $attempt = $this->createCardAttempt([
            'sbp_payload' => 'https://qr.nspk.ru/CONTRADICTION',
            'poll_count' => 0,
            'last_polled_at' => null,
        ]);
        $this->fakeBank($attempt);

        app(PaymentReconciliationService::class)->reconcile();

        // Противоречивый контекст → обычный путь, никогда не отказ
        $this->assertSame(1, $this->requestCount('/v2/GetState'));
        $this->assertSame(0, $this->requestCount('/v2/CheckOrder'));
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->refresh()->status);
    }

    public function test_expired_card_attempt_without_deadline_key_is_never_touched(): void
    {
        // Старая попытка без card_expires_at: и manual, и scheduler — прежний путь
        $attempt = $this->createCardAttempt([
            'card_expires_at' => null,
            'poll_count' => 0,
            'last_polled_at' => null,
        ]);
        $this->fakeBank($attempt);

        $this->actingAs($this->owner)
            ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
            ->assertOk()
            ->assertJson(['checked' => true]);
        $this->assertSame(1, $this->requestCount('/v2/GetState'));
        $this->assertSame(0, $this->requestCount('/v2/CheckOrder'));

        // Вне cooldown фоновая очередь спрашивает тем же GetState —
        // CheckOrder для попытки без срока не используется
        $this->travel(61)->seconds();
        app(PaymentReconciliationService::class)->reconcile();
        $this->assertSame(2, $this->requestCount('/v2/GetState'));
        $this->assertSame(0, $this->requestCount('/v2/CheckOrder'));
        $this->travelBack();
    }

    // ── §2: GET остаётся read-only ──

    public function test_get_payment_status_stays_local_for_expired_card_attempt(): void
    {
        $attempt = $this->createCardAttempt();
        Http::fake();

        $this->actingAs($this->owner)
            ->get("/admin/billing/payment-status/{$attempt->provider_payment_id}")
            ->assertOk()
            ->assertExactJson(['status' => 'processing']);

        Http::assertNothingSent();
        $this->assertSame(4, $attempt->refresh()->metadata['poll_count']);
    }

    public function test_manual_check_respects_the_shared_per_attempt_lock(): void
    {
        $attempt = $this->createCardAttempt();
        Http::fake();

        $lock = Cache::lock('billing:payment-status-check:'.$attempt->getKey(), 30);
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($this->owner)
                ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
                ->assertOk()
                ->assertExactJson(['status' => 'processing', 'checked' => false]);
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
    }
}
