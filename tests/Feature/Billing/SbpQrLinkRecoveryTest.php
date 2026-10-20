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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionClass;
use Tests\TestCase;

/**
 * Восстановление SBP-ссылки СУЩЕСТВУЮЩЕЙ tbank-попытки повторным GetQr.
 *
 * Никакого нового Init и никакого продления RedirectDueDate: повторный
 * GetQr идёт только для уже привязанного PaymentId (известного или
 * восстановленного через CheckOrder в той же проверке), только при
 * фактическом ответе банка NEW/AUTHORIZED и только пока исходный
 * sbp_expires_at ещё в будущем, payload отсутствует и способ не
 * противоречит sbp. Общий status-check lock удерживается на всю
 * последовательность из двух банковских методов (lookup + GetQr).
 * Сохранение — row-lock-транзакция: чужой payload, webhook-вердикт или
 * истёкший срок во время GetQr не дают затереть строку. Выдача: GET
 * checkout read-only, Unknown отдаёт только проверенную восстановленную
 * ссылку, POST /check несёт доступный payload из свежей строки.
 */
class SbpQrLinkRecoveryTest extends TestCase
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

        config(['billing.core_entitlement' => false]);
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
        array $metadata = [],
        ?string $providerPaymentId = 'tbank_777',
        ?string $failureCategory = null,
        int $ageMinutes = 60,
    ): PaymentAttempt {
        $cycle = $this->createCycle(
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
            'provider_payment_id' => $providerPaymentId,
            'status' => $status,
            'failure_category' => $failureCategory,
            'initiated_at' => now()->subMinutes($ageMinutes + 60),
            'metadata' => $metadata,
        ]);
        $attempt->created_at = now()->subMinutes($ageMinutes);
        $attempt->save();

        return $attempt;
    }

    /**
     * Валидные ворота восстановления: tbank-попытка sbp-способа с будущим
     * исходным сроком (контракт Phase A) и без сохранённого payload.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function sbpRecoveryMetadata(array $overrides = []): array
    {
        return array_merge([
            'payment_method' => 'sbp',
            'sbp_expires_at' => now()->addMinutes(10)->toIso8601String(),
        ], $overrides);
    }

    /**
     * Реальный контракт Phase A попытки, у которой Init/GetQr не прошёл:
     * sbp_expires_at уже записан, payment_method / sbp_payload / PaymentId
     * ещё нет — они появляются только в Phase C. Способ сюда не подставлять.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function phaseAMetadata(array $overrides = []): array
    {
        return array_merge([
            'sbp_expires_at' => now()->addMinutes(10)->toIso8601String(),
        ], $overrides);
    }

    private function reconcile(): void
    {
        app(PaymentReconciliationService::class)->reconcile();
    }

    /**
     * @param  callable(Request):  array<string, mixed>  $getQrAnswer
     */
    private function fakeBank(callable $getStateAnswer, ?callable $getQrAnswer = null, ?callable $checkOrderAnswer = null): void
    {
        Http::fake(function ($request) use ($getStateAnswer, $getQrAnswer, $checkOrderAnswer) {
            $url = $request->url();

            if (str_ends_with($url, '/v2/GetQr')) {
                if ($getQrAnswer === null) {
                    $this->fail('GetQr не должен вызываться');
                }

                return Http::response($getQrAnswer($request), 200);
            }

            if (str_ends_with($url, '/v2/CheckOrder')) {
                if ($checkOrderAnswer === null) {
                    $this->fail('CheckOrder не должен вызываться');
                }

                return Http::response($checkOrderAnswer($request), 200);
            }

            if (str_ends_with($url, '/v2/GetState')) {
                return Http::response($getStateAnswer($request), 200);
            }

            return Http::response(['Success' => false, 'ErrorCode' => '9999'], 200);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function getQrAnswer(string $payload = 'https://qr.nspk.ru/RECOVERED'): array
    {
        return [
            'Success' => true,
            'Data' => $payload,
            'RequestKey' => 'rk_recovered',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function newStateAnswer(PaymentAttempt $attempt, string $status = 'NEW', string $paymentId = 'tbank_777'): array
    {
        return [
            'Success' => true,
            'PaymentId' => $paymentId,
            'OrderId' => $attempt->internal_order_id,
            'Status' => $status,
            'Amount' => 49000,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkOrderAnswer(PaymentAttempt $attempt, string $status = 'NEW', string $paymentId = 'tbank_777'): array
    {
        return [
            'Success' => true,
            'Payments' => [[
                'PaymentId' => $paymentId,
                'OrderId' => $attempt->internal_order_id,
                'Status' => $status,
                'Amount' => 49000,
            ]],
        ];
    }

    private function requestCount(string $path): int
    {
        return Http::recorded()
            ->filter(fn (array $pair) => str_ends_with($pair[0]->url(), $path))
            ->count();
    }

    private function statusCheckLockKey(PaymentAttempt $attempt): string
    {
        return 'billing:payment-status-check:'.$attempt->getKey();
    }

    private function lockIsHeldFor(PaymentAttempt $attempt): bool
    {
        $probe = Cache::lock($this->statusCheckLockKey($attempt), 5);
        $acquired = $probe->get();

        if ($acquired) {
            $probe->release();
        }

        return ! $acquired;
    }

    // ── 1. CheckOrder + GetQr, без Init ──

    public function test_checkorder_then_getqr_restores_link_without_init(): void
    {
        $expiresAt = now()->addMinutes(10)->toIso8601String();
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Created,
            $this->sbpRecoveryMetadata(['sbp_expires_at' => $expiresAt]),
            providerPaymentId: null,
            ageMinutes: 10,
        );

        $this->fakeBank(
            getStateAnswer: fn () => $this->fail('GetState не должен вызываться'),
            getQrAnswer: fn () => $this->getQrAnswer(),
            checkOrderAnswer: fn () => $this->checkOrderAnswer($attempt),
        );

        $this->reconcile();

        $attempt->refresh();
        $this->assertSame('tbank_777', $attempt->provider_payment_id);
        $this->assertSame('https://qr.nspk.ru/RECOVERED', $attempt->metadata['sbp_payload']);
        $this->assertSame('rk_recovered', $attempt->metadata['request_key']);
        // Исходный срок не затёрт
        $this->assertSame($expiresAt, $attempt->metadata['sbp_expires_at']);

        $this->assertSame(1, $this->requestCount('/v2/CheckOrder'));
        $this->assertSame(1, $this->requestCount('/v2/GetQr'));
        // Никакого нового Init
        $this->assertSame(0, $this->requestCount('/v2/Init'));
    }

    /**
     * Обязательная регрессия: реальная попытка Phase A после ошибки
     * Init/GetQr — sbp_expires_at есть, payment_method/payload/PaymentId
     * нет. Такая строка основной сценарий восстановления, поэтому он
     * должен быть достижим: CheckOrder → NEW → привязка ID → GetQr →
     * method=sbp и payload в merge-записи, исходный срок не тронут, GET
     * checkout отдаёт ссылку. Ни одного Init.
     */
    public function test_phase_a_attempt_without_method_recovers_link_end_to_end(): void
    {
        $expiresAt = now()->addMinutes(10)->toIso8601String();
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Created,
            $this->phaseAMetadata(['sbp_expires_at' => $expiresAt]),
            providerPaymentId: null,
            ageMinutes: 10,
        );

        // Контракт Phase A в исходной фикстуре: способа, payload и id нет
        $this->assertArrayNotHasKey('payment_method', $attempt->metadata);
        $this->assertArrayNotHasKey('sbp_payload', $attempt->metadata);
        $this->assertNull($attempt->provider_payment_id);

        $this->fakeBank(
            getStateAnswer: fn () => $this->fail('GetState не должен вызываться'),
            getQrAnswer: fn () => $this->getQrAnswer(),
            checkOrderAnswer: fn () => $this->checkOrderAnswer($attempt),
        );

        $this->reconcile();

        $attempt->refresh();
        $this->assertSame('tbank_777', $attempt->provider_payment_id);
        // Merge-запись добрала способ вместе с payload
        $this->assertSame('sbp', $attempt->metadata['payment_method']);
        $this->assertSame('https://qr.nspk.ru/RECOVERED', $attempt->metadata['sbp_payload']);
        $this->assertSame('rk_recovered', $attempt->metadata['request_key']);
        // Исходный срок не изменён
        $this->assertSame($expiresAt, $attempt->metadata['sbp_expires_at']);

        $this->assertSame(1, $this->requestCount('/v2/CheckOrder'));
        $this->assertSame(1, $this->requestCount('/v2/GetQr'));
        $this->assertSame(0, $this->requestCount('/v2/Init'));

        $this->actingAs($this->owner)
            ->get('/admin/billing/checkout?period_months=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing-checkout')
                ->where('pending_attempt.method', 'sbp')
                ->where('pending_attempt.sbp_payload', 'https://qr.nspk.ru/RECOVERED')
                ->where('pending_attempt.sbp_expires_at', $expiresAt)
            );

        $this->assertSame(0, $this->requestCount('/v2/Init'));
    }

    // ── 2. Известный PaymentId + NEW/AUTHORIZED ──

    public function test_known_payment_id_new_getstate_restores_link(): void
    {
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Processing,
            $this->sbpRecoveryMetadata(),
        );

        $this->fakeBank(
            getStateAnswer: fn () => $this->newStateAnswer($attempt, 'NEW'),
            getQrAnswer: fn () => $this->getQrAnswer(),
        );

        $this->reconcile();

        $this->assertSame('https://qr.nspk.ru/RECOVERED', $attempt->refresh()->metadata['sbp_payload']);
        $this->assertSame(1, $this->requestCount('/v2/GetState'));
        $this->assertSame(1, $this->requestCount('/v2/GetQr'));
        $this->assertSame(0, $this->requestCount('/v2/Init'));
    }

    public function test_authorized_getstate_restores_link(): void
    {
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Processing,
            $this->sbpRecoveryMetadata(),
        );

        $this->fakeBank(
            getStateAnswer: fn () => $this->newStateAnswer($attempt, 'AUTHORIZED'),
            getQrAnswer: fn () => $this->getQrAnswer(),
        );

        $this->reconcile();

        $this->assertSame('https://qr.nspk.ru/RECOVERED', $attempt->refresh()->metadata['sbp_payload']);
        $this->assertSame(1, $this->requestCount('/v2/GetQr'));
    }

    // ── 3. Ворота: GetQr не вызывается ──

    public function test_unrecognised_bank_status_never_requests_getqr(): void
    {
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Processing,
            $this->sbpRecoveryMetadata(),
        );

        // Нераспознанный статус нормализуется в Unknown — но NEW это не
        // значит, основания для GetQr нет
        $this->fakeBank(
            getStateAnswer: fn () => $this->newStateAnswer($attempt, 'SOMETHING_ELSE'),
        );

        $this->reconcile();

        $this->assertNull($attempt->refresh()->metadata['sbp_payload'] ?? null);
        $this->assertSame(1, $this->requestCount('/v2/GetState'));
        $this->assertSame(0, $this->requestCount('/v2/GetQr'));
    }

    public function test_null_bank_answer_never_requests_getqr(): void
    {
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Processing,
            $this->sbpRecoveryMetadata(),
        );

        $this->fakeBank(getStateAnswer: fn () => ['Success' => false]);

        $this->reconcile();

        $this->assertNull($attempt->refresh()->metadata['sbp_payload'] ?? null);
        $this->assertSame(0, $this->requestCount('/v2/GetQr'));
    }

    public function test_transport_error_never_requests_getqr(): void
    {
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Processing,
            $this->sbpRecoveryMetadata(),
        );

        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/v2/GetState')) {
                throw new \RuntimeException('bank down');
            }

            return Http::response(['Success' => false], 200);
        });

        $result = app(PaymentReconciliationService::class)->reconcile();

        $this->assertSame(1, $result['errors']);
        $this->assertSame(0, $this->requestCount('/v2/GetQr'));
        $fresh = $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $fresh->status);
        $this->assertSame('tbank_777', $fresh->provider_payment_id);
    }

    public function test_missing_deadline_never_requests_getqr(): void
    {
        // Старый attempt до Phase A: ключа sbp_expires_at нет вовсе —
        // способ и срок неизвестны, GetQr закрыт
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Processing,
            $this->sbpRecoveryMetadata(['sbp_expires_at' => null]),
        );

        $this->fakeBank(
            getStateAnswer: fn () => $this->newStateAnswer($attempt, 'NEW'),
        );

        $this->reconcile();

        $this->assertNull($attempt->refresh()->metadata['sbp_payload'] ?? null);
        $this->assertSame(0, $this->requestCount('/v2/GetQr'));
    }

    public function test_expired_deadline_never_requests_getqr(): void
    {
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Processing,
            $this->sbpRecoveryMetadata([
                'sbp_expires_at' => now()->subMinute()->toIso8601String(),
            ]),
        );

        $this->fakeBank(
            getStateAnswer: fn () => $this->newStateAnswer($attempt, 'NEW'),
        );

        $this->reconcile();

        $this->assertNull($attempt->refresh()->metadata['sbp_payload'] ?? null);
        $this->assertSame(0, $this->requestCount('/v2/GetQr'));
    }

    public function test_malformed_deadline_never_requests_getqr(): void
    {
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Processing,
            $this->sbpRecoveryMetadata(['sbp_expires_at' => 'not-a-date']),
        );

        $this->fakeBank(
            getStateAnswer: fn () => $this->newStateAnswer($attempt, 'NEW'),
        );

        $this->reconcile();

        $this->assertNull($attempt->refresh()->metadata['sbp_payload'] ?? null);
        $this->assertSame(0, $this->requestCount('/v2/GetQr'));
    }

    public function test_card_method_never_requests_getqr(): void
    {
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Processing,
            $this->sbpRecoveryMetadata([
                'payment_method' => 'card',
                'checkout_url' => 'https://pay.tinkoff.ru/xyz',
            ]),
        );

        $this->fakeBank(
            getStateAnswer: fn () => $this->newStateAnswer($attempt, 'NEW'),
        );

        $this->reconcile();

        $this->assertNull($attempt->refresh()->metadata['sbp_payload'] ?? null);
        $this->assertSame(0, $this->requestCount('/v2/GetQr'));
    }

    public function test_checkout_url_blocks_getqr_even_for_sbp_method(): void
    {
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Processing,
            $this->sbpRecoveryMetadata([
                'checkout_url' => 'https://pay.tinkoff.ru/contradiction',
            ]),
        );

        $this->fakeBank(
            getStateAnswer: fn () => $this->newStateAnswer($attempt, 'NEW'),
        );

        $this->reconcile();

        $this->assertNull($attempt->refresh()->metadata['sbp_payload'] ?? null);
        $this->assertSame(0, $this->requestCount('/v2/GetQr'));
    }

    public function test_missing_method_with_card_checkout_url_never_requests_getqr(): void
    {
        // Способ не записан (Phase A), но checkout_url выдаёт карточную
        // попытку — противоречие, GetQr закрыт
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Processing,
            $this->phaseAMetadata([
                'checkout_url' => 'https://pay.tinkoff.ru/card-without-method',
            ]),
        );

        $this->fakeBank(
            getStateAnswer: fn () => $this->newStateAnswer($attempt, 'NEW'),
        );

        $this->reconcile();

        $this->assertNull($attempt->refresh()->metadata['sbp_payload'] ?? null);
        $this->assertSame(0, $this->requestCount('/v2/GetQr'));
    }

    public function test_unknown_method_never_requests_getqr(): void
    {
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Processing,
            $this->phaseAMetadata(['payment_method' => 'apple_pay']),
        );

        $this->fakeBank(
            getStateAnswer: fn () => $this->newStateAnswer($attempt, 'NEW'),
        );

        $this->reconcile();

        $fresh = $attempt->refresh();
        $this->assertNull($fresh->metadata['sbp_payload'] ?? null);
        $this->assertSame('apple_pay', $fresh->metadata['payment_method']);
        $this->assertSame(0, $this->requestCount('/v2/GetQr'));
    }

    public function test_non_string_method_never_requests_getqr(): void
    {
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Processing,
            $this->phaseAMetadata(['payment_method' => ['sbp']]),
        );

        $this->fakeBank(
            getStateAnswer: fn () => $this->newStateAnswer($attempt, 'NEW'),
        );

        $this->reconcile();

        $this->assertNull($attempt->refresh()->metadata['sbp_payload'] ?? null);
        $this->assertSame(0, $this->requestCount('/v2/GetQr'));
    }

    public function test_missing_method_expired_deadline_never_requests_getqr(): void
    {
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Processing,
            $this->phaseAMetadata([
                'sbp_expires_at' => now()->subMinute()->toIso8601String(),
            ]),
        );

        $this->fakeBank(
            getStateAnswer: fn () => $this->newStateAnswer($attempt, 'NEW'),
        );

        $this->reconcile();

        $this->assertNull($attempt->refresh()->metadata['sbp_payload'] ?? null);
        $this->assertSame(0, $this->requestCount('/v2/GetQr'));
    }

    public function test_renewal_never_requests_getqr(): void
    {
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Processing,
            $this->sbpRecoveryMetadata(['renewal' => true]),
        );

        $this->fakeBank(
            getStateAnswer: fn () => $this->newStateAnswer($attempt, 'NEW'),
        );

        $this->reconcile();

        $this->assertNull($attempt->refresh()->metadata['sbp_payload'] ?? null);
        $this->assertSame(0, $this->requestCount('/v2/GetQr'));
    }

    public function test_timeout_queue_never_requests_getqr(): void
    {
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::FailedTerminal,
            $this->sbpRecoveryMetadata(),
            failureCategory: 'reconciliation_timeout',
        );

        $this->fakeBank(
            getStateAnswer: fn () => $this->newStateAnswer($attempt, 'NEW'),
        );

        $this->reconcile();

        $this->assertNull($attempt->refresh()->metadata['sbp_payload'] ?? null);
        $this->assertSame(1, $this->requestCount('/v2/GetState'));
        $this->assertSame(0, $this->requestCount('/v2/GetQr'));
    }

    public function test_existing_payload_is_not_requested_again(): void
    {
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Processing,
            $this->sbpRecoveryMetadata([
                'sbp_payload' => 'https://qr.nspk.ru/ORIGINAL',
            ]),
        );

        $this->fakeBank(
            getStateAnswer: fn () => $this->newStateAnswer($attempt, 'NEW'),
        );

        $this->reconcile();

        $this->assertSame(
            'https://qr.nspk.ru/ORIGINAL',
            $attempt->refresh()->metadata['sbp_payload'],
        );
        $this->assertSame(1, $this->requestCount('/v2/GetState'));
        $this->assertSame(0, $this->requestCount('/v2/GetQr'));
    }

    // ── 4. Гонки во время GetQr ──

    public function test_webhook_verdict_during_getqr_prevents_saving(): void
    {
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Processing,
            $this->sbpRecoveryMetadata(),
        );

        $this->fakeBank(
            getStateAnswer: fn () => $this->newStateAnswer($attempt, 'NEW'),
            getQrAnswer: function () use ($attempt) {
                // Webhook пришёл, пока банк отдавал QR: попытка уже
                // подтверждена — восстановленная ссылка не сохраняется
                app(PaymentTransitionService::class)->transition(new ProviderStatusUpdate(
                    provider: 'tbank',
                    normalizedStatus: PaymentAttemptStatus::Succeeded,
                    providerPaymentId: 'tbank_777',
                    internalOrderId: $attempt->internal_order_id,
                    amount: 490,
                    currency: 'RUB',
                    raw: ['Status' => 'CONFIRMED', 'OrderId' => $attempt->internal_order_id],
                ));

                return $this->getQrAnswer();
            },
        );

        $this->reconcile();

        $fresh = $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $fresh->status);
        $this->assertNull($fresh->metadata['sbp_payload'] ?? null);
    }

    public function test_webhook_verdict_during_getqr_never_writes_missing_method(): void
    {
        // Фикстура Phase A без способа: если webhook пришёл, пока банк
        // отдавал QR, под row lock не пишется НИ payload, НИ способ
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Created,
            $this->phaseAMetadata(),
            providerPaymentId: null,
            ageMinutes: 10,
        );

        $this->fakeBank(
            getStateAnswer: fn () => $this->fail('GetState не должен вызываться'),
            getQrAnswer: function () use ($attempt) {
                app(PaymentTransitionService::class)->transition(new ProviderStatusUpdate(
                    provider: 'tbank',
                    normalizedStatus: PaymentAttemptStatus::Succeeded,
                    providerPaymentId: 'tbank_777',
                    internalOrderId: $attempt->internal_order_id,
                    amount: 490,
                    currency: 'RUB',
                    raw: ['Status' => 'CONFIRMED', 'OrderId' => $attempt->internal_order_id],
                ));

                return $this->getQrAnswer();
            },
            checkOrderAnswer: fn () => $this->checkOrderAnswer($attempt),
        );

        $this->reconcile();

        $fresh = $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $fresh->status);
        $this->assertNull($fresh->metadata['sbp_payload'] ?? null);
        // Способ не дописан: сохранение было отклонено целиком
        $this->assertArrayNotHasKey('payment_method', $fresh->metadata);
    }

    public function test_deadline_passing_during_getqr_prevents_saving(): void
    {
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Processing,
            $this->sbpRecoveryMetadata(),
        );

        $this->fakeBank(
            getStateAnswer: fn () => $this->newStateAnswer($attempt, 'NEW'),
            getQrAnswer: function () use ($attempt) {
                // Срок истёк, пока банк отдавал QR — ссылка не сохраняется
                $metadata = $attempt->refresh()->metadata;
                $metadata['sbp_expires_at'] = now()->subSecond()->toIso8601String();
                $attempt->update(['metadata' => $metadata]);

                return $this->getQrAnswer();
            },
        );

        $this->reconcile();

        $this->assertNull($attempt->refresh()->metadata['sbp_payload'] ?? null);
    }

    public function test_payload_saved_by_another_process_is_kept(): void
    {
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Processing,
            $this->sbpRecoveryMetadata(),
        );

        $this->fakeBank(
            getStateAnswer: fn () => $this->newStateAnswer($attempt, 'NEW'),
            getQrAnswer: function () use ($attempt) {
                // Другой процесс успел сохранить свою ссылку раньше
                $metadata = $attempt->refresh()->metadata;
                $metadata['sbp_payload'] = 'https://qr.nspk.ru/WINNER';
                $attempt->update(['metadata' => $metadata]);

                return $this->getQrAnswer('https://qr.nspk.ru/LATE');
            },
        );

        $this->reconcile();

        $this->assertSame(
            'https://qr.nspk.ru/WINNER',
            $attempt->refresh()->metadata['sbp_payload'],
        );
    }

    public function test_getqr_error_preserves_payment_id_status_and_metadata(): void
    {
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Processing,
            $this->sbpRecoveryMetadata(['poll_count' => 2, 'keep_me' => 'yes']),
        );

        $this->fakeBank(
            getStateAnswer: fn () => $this->newStateAnswer($attempt, 'NEW'),
            getQrAnswer: fn () => ['Success' => false, 'ErrorCode' => '188'],
        );

        $result = app(PaymentReconciliationService::class)->reconcile();

        // Ошибка GetQr — не отказ и не ошибка прогона очереди
        $this->assertSame(0, $result['errors']);

        $fresh = $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $fresh->status);
        // Уже восстановленный через CheckOrder/Init PaymentId не потерян
        $this->assertSame('tbank_777', $fresh->provider_payment_id);
        $this->assertNull($fresh->failure_category);
        $this->assertNull($fresh->metadata['sbp_payload'] ?? null);
        // Poll metadata и чужие ключи на месте
        $this->assertSame(3, $fresh->metadata['poll_count']);
        $this->assertSame('yes', $fresh->metadata['keep_me']);
        $this->assertNotEmpty($fresh->metadata['last_status_check_at']);
    }

    // ── 5. Lock покрывает последовательный бюджет двух HTTP-методов ──

    public function test_lock_covers_status_lookup_and_getqr_sequentially(): void
    {
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Processing,
            $this->sbpRecoveryMetadata(),
        );

        $lockHeldDuring = [];
        Http::fake(function ($request) use ($attempt, &$lockHeldDuring) {
            if (str_ends_with($request->url(), '/v2/GetState')) {
                $lockHeldDuring['GetState'] = $this->lockIsHeldFor($attempt);

                return Http::response($this->newStateAnswer($attempt, 'NEW'), 200);
            }

            if (str_ends_with($request->url(), '/v2/GetQr')) {
                $lockHeldDuring['GetQr'] = $this->lockIsHeldFor($attempt);

                return Http::response($this->getQrAnswer(), 200);
            }

            return Http::response(['Success' => false], 200);
        });

        $this->reconcile();

        $this->assertTrue($lockHeldDuring['GetState'], 'lock должен удерживаться на весь lookup');
        $this->assertTrue($lockHeldDuring['GetQr'], 'lock должен удерживаться на весь GetQr');
        $this->assertSame('https://qr.nspk.ru/RECOVERED', $attempt->refresh()->metadata['sbp_payload']);

        // Расчёт бюджета закреплён: два последовательных метода по
        // ≈129.3s (6 × 20s + 9.3s) должны укладываться в TTL lock'а
        $ttl = (new ReflectionClass(PaymentReconciliationService::class))
            ->getConstant('CHECK_LOCK_TTL_SECONDS');

        $this->assertIsInt($ttl);
        $this->assertGreaterThan(2 * (6 * 20 + 9.3), $ttl);
    }

    // ── 6. POST /check: payload из свежей строки ──

    public function test_post_check_returns_recovered_payload(): void
    {
        $expiresAt = now()->addMinutes(10)->toIso8601String();
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Processing,
            $this->sbpRecoveryMetadata(['sbp_expires_at' => $expiresAt]),
        );

        $this->fakeBank(
            getStateAnswer: fn () => $this->newStateAnswer($attempt, 'NEW'),
            getQrAnswer: fn () => $this->getQrAnswer(),
        );

        $response = $this->actingAs($this->owner)
            ->postJson('/admin/billing/payment-status/tbank_777/check');

        $response->assertOk()
            ->assertJsonPath('checked', true)
            ->assertJsonPath('status', 'processing')
            ->assertJsonPath('sbp_payload', 'https://qr.nspk.ru/RECOVERED')
            ->assertJsonPath('sbp_expires_at', $expiresAt);
    }

    public function test_post_check_cooldown_skip_still_serves_recovered_payload(): void
    {
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Processing,
            $this->sbpRecoveryMetadata([
                'sbp_payload' => 'https://qr.nspk.ru/ALREADY',
                'last_status_check_at' => now()->toIso8601String(),
            ]),
        );

        Http::fake();

        $response = $this->actingAs($this->owner)
            ->postJson('/admin/billing/payment-status/tbank_777/check');

        $response->assertOk()
            ->assertJsonPath('checked', false)
            ->assertJsonPath('status', 'processing')
            ->assertJsonPath('sbp_payload', 'https://qr.nspk.ru/ALREADY');

        Http::assertNothingSent();
    }

    public function test_post_check_final_status_outranks_payload(): void
    {
        $this->createAttempt(
            PaymentAttemptStatus::Succeeded,
            $this->sbpRecoveryMetadata([
                'sbp_payload' => 'https://qr.nspk.ru/DONE',
            ]),
        );

        Http::fake();

        $response = $this->actingAs($this->owner)
            ->postJson('/admin/billing/payment-status/tbank_777/check');

        $response->assertOk()
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonMissingPath('sbp_payload');

        Http::assertNothingSent();
    }

    public function test_post_check_null_answer_never_returns_payload(): void
    {
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Processing,
            $this->sbpRecoveryMetadata(),
        );

        $this->fakeBank(getStateAnswer: fn () => ['Success' => false]);

        $response = $this->actingAs($this->owner)
            ->postJson('/admin/billing/payment-status/tbank_777/check');

        $response->assertOk()
            ->assertJsonPath('checked', true)
            ->assertJsonPath('status', 'processing')
            ->assertJsonMissingPath('sbp_payload');

        $this->assertSame(0, $this->requestCount('/v2/GetQr'));
        $this->assertNull($attempt->refresh()->metadata['sbp_payload'] ?? null);
    }

    public function test_manual_check_triggers_recovery_and_returns_payload(): void
    {
        $attempt = $this->createAttempt(
            PaymentAttemptStatus::Processing,
            $this->sbpRecoveryMetadata(),
        );

        $this->fakeBank(
            getStateAnswer: fn () => $this->newStateAnswer($attempt, 'NEW'),
            getQrAnswer: fn () => $this->getQrAnswer(),
        );

        $response = $this->actingAs($this->owner)
            ->postJson('/admin/billing/payment-status/tbank_777/check');

        $response->assertOk()
            ->assertJsonPath('sbp_payload', 'https://qr.nspk.ru/RECOVERED');

        $this->assertSame(
            'https://qr.nspk.ru/RECOVERED',
            $attempt->refresh()->metadata['sbp_payload'],
        );
        $this->assertSame(1, $this->requestCount('/v2/GetQr'));
        $this->assertSame(0, $this->requestCount('/v2/Init'));
    }

    // ── 7. GET checkout: Unknown отдаёт только проверенную ссылку ──

    public function test_get_pending_attempt_serves_unknown_recovered_payload(): void
    {
        $this->createAttempt(
            PaymentAttemptStatus::Unknown,
            $this->sbpRecoveryMetadata([
                'sbp_payload' => 'https://qr.nspk.ru/RECOVERED',
            ]),
        );

        Http::fake();

        $this->actingAs($this->owner)
            ->get('/admin/billing/checkout?period_months=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing-checkout')
                ->where('pending_attempt.status', 'unknown')
                ->where('pending_attempt.sbp_payload', 'https://qr.nspk.ru/RECOVERED')
                ->whereNotNull('pending_attempt.sbp_expires_at')
            );

        Http::assertNothingSent();
    }

    public function test_get_pending_attempt_withholds_unknown_expired_payload(): void
    {
        $this->createAttempt(
            PaymentAttemptStatus::Unknown,
            $this->sbpRecoveryMetadata([
                'sbp_payload' => 'https://qr.nspk.ru/DEAD',
                'sbp_expires_at' => now()->subMinute()->toIso8601String(),
            ]),
        );

        Http::fake();

        $this->actingAs($this->owner)
            ->get('/admin/billing/checkout?period_months=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing-checkout')
                ->where('pending_attempt.status', 'unknown')
                ->where('pending_attempt.sbp_payload', null)
            );
    }

    public function test_get_pending_attempt_withholds_unknown_payload_without_deadline(): void
    {
        $this->createAttempt(
            PaymentAttemptStatus::Unknown,
            [
                'payment_method' => 'sbp',
                'sbp_payload' => 'https://qr.nspk.ru/UNVERIFIED',
            ],
        );

        Http::fake();

        $this->actingAs($this->owner)
            ->get('/admin/billing/checkout?period_months=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing-checkout')
                ->where('pending_attempt.sbp_payload', null)
            );
    }

    public function test_get_pending_attempt_keeps_awaiting_sbp_serving_unchanged(): void
    {
        $this->createAttempt(
            PaymentAttemptStatus::Processing,
            $this->sbpRecoveryMetadata([
                'sbp_payload' => 'https://qr.nspk.ru/RECOVERED',
            ]),
        );

        Http::fake();

        $this->actingAs($this->owner)
            ->get('/admin/billing/checkout?period_months=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing-checkout')
                ->where('pending_attempt.status', 'processing')
                ->where('pending_attempt.sbp_payload', 'https://qr.nspk.ru/RECOVERED')
            );
    }
}
