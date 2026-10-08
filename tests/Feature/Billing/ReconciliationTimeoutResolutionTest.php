<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\NotificationLog;
use App\Models\PaymentAttempt;
use App\Models\PlanPrice;
use App\Models\ProviderEvent;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingCoreWriter;
use App\Services\Billing\BillingService;
use App\Services\Notification\MasterNotificationService;
use App\Services\Payment\DTOs\ProviderStatusUpdate;
use App\Services\Payment\PaymentTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Локальный age-release (failed_terminal + reconciliation_timeout) — не
 * вердикт банка. Подтверждённый отказ провайдера (webhook/reconciliation →
 * transition()) уточняет категорию на месте, снимая checkout-блокировку и
 * undefined_outcome без повторных horizon/renewal/grace/notification
 * side effects; подтверждённый успех очищает timeout failure-поля и даёт
 * Paid. Только Http::fake — без реального банка.
 */
class ReconciliationTimeoutResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const TIMEOUT_MESSAGE = 'Статус предыдущего платежа уточняется. Попробуйте позже.';

    private TariffPlan $proPlan;

    private TariffPlan $altPlan;

    private PaymentTransitionService $transitionService;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.legacy_mock_webhook_secret' => 'test_secret_123']);

        $this->transitionService = app(PaymentTransitionService::class);

        $this->proPlan = TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'max_appointments_per_month' => null,
            'max_masters' => 1,
            'features' => ['unlimited_appointments'],
            'is_active' => true,
        ]);

        $this->altPlan = TariffPlan::create([
            'code' => 'plus',
            'name' => 'Плюс',
            'price_monthly' => 990,
            'max_appointments_per_month' => null,
            'max_masters' => 2,
            'features' => ['unlimited_appointments'],
            'is_active' => true,
        ]);

        foreach ([$this->proPlan, $this->altPlan] as $plan) {
            foreach ([1, 3, 6, 12] as $months) {
                PlanPrice::create([
                    'tariff_plan_id' => $plan->id,
                    'period_months' => $months,
                    'base_amount' => $plan->price_monthly * $months,
                    'discount_percent' => 0,
                    'final_amount' => $plan->price_monthly * $months,
                    'currency' => 'RUB',
                    'version' => 1,
                    'valid_from' => now(),
                    'is_active' => true,
                ]);
            }
        }
    }

    // ── Helpers ──

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

    /**
     * Age-released checkout attempt: failed_terminal + reconciliation_timeout
     * + provider_payment_id (status Pending/Failed is decided by caller).
     *
     * @param  array<string, mixed>  $metadata
     */
    private function createTimeoutAttempt(
        Workspace $workspace,
        TariffPlan $plan,
        BillingCycleStatus $cycleStatus = BillingCycleStatus::Failed,
        ?string $providerPaymentId = 'tbank_700123456',
        array $metadata = ['payment_method' => 'card', 'checkout_url' => 'https://example.test/pay'],
        BillingSubscriptionStatus $subscriptionStatus = BillingSubscriptionStatus::PendingInitial,
    ): PaymentAttempt {
        $subscription = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'status' => $subscriptionStatus,
        ]);

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $subscription->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'period_start' => now(),
            'period_end' => now()->addMonth(),
            'status' => $cycleStatus,
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
            'provider_payment_id' => $providerPaymentId,
            'status' => PaymentAttemptStatus::FailedTerminal,
            'failure_category' => 'reconciliation_timeout',
            'initiated_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(30),
            'metadata' => $metadata,
        ]);
        $attempt->created_at = now()->subHour();
        $attempt->save();

        return $attempt;
    }

    /**
     * Confirmed T-Bank decline exactly as normalizeWebhook would produce it
     * (REJECTED → failed_terminal; ErrorCode 5106 → no special category).
     */
    private function declineUpdate(PaymentAttempt $attempt, string $providerEventId): ProviderStatusUpdate
    {
        return new ProviderStatusUpdate(
            provider: 'tbank',
            providerEventId: $providerEventId,
            providerPaymentId: $attempt->provider_payment_id,
            internalOrderId: $attempt->internal_order_id,
            normalizedStatus: PaymentAttemptStatus::FailedTerminal,
            raw: [
                'Status' => 'REJECTED',
                'ErrorCode' => '5106',
                'PaymentId' => $attempt->provider_payment_id,
                'TerminalKey' => 'test_terminal',
            ],
            failureCode: '5106',
            failureMessage: 'Отказ платежной системы',
        );
    }

    /**
     * Confirmed T-Bank success (CONFIRMED → succeeded) with matching money.
     */
    private function successUpdate(PaymentAttempt $attempt, string $providerEventId): ProviderStatusUpdate
    {
        return new ProviderStatusUpdate(
            provider: 'tbank',
            providerEventId: $providerEventId,
            providerPaymentId: $attempt->provider_payment_id,
            internalOrderId: $attempt->internal_order_id,
            normalizedStatus: PaymentAttemptStatus::Succeeded,
            amount: 490,
            currency: 'RUB',
            raw: [
                'Status' => 'CONFIRMED',
                'PaymentId' => $attempt->provider_payment_id,
                'Amount' => 49000,
            ],
        );
    }

    private function assertPaymentStatusJson(User $master, PaymentAttempt $attempt, array $expected): void
    {
        $this->actingAs($master)
            ->get("/admin/billing/payment-status/{$attempt->provider_payment_id}")
            ->assertOk()
            ->assertExactJson($expected);
    }

    // ── 1. Timeout → confirmed decline: checkout unblocked, no undefined_outcome ──

    public function test_confirmed_decline_resolves_timeout_and_unblocks_checkout(): void
    {
        Http::fake();
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $attempt = $this->createTimeoutAttempt($workspace, $this->proPlan);
        $originalMetadata = $attempt->metadata;

        // До уточнения: блокировка + undefined_outcome.
        try {
            app(BillingService::class)->subscribe($master, $this->proPlan, 1, false, 'card');
            $this->fail('Expected ValidationException while the timeout is unresolved');
        } catch (ValidationException $e) {
            $this->assertSame(self::TIMEOUT_MESSAGE, $e->errors()['plan'][0]);
        }
        $this->assertNotNull(app(BillingCoreWriter::class)->findExistingInFlightAttempt($workspace->id, $this->proPlan->id));
        $this->assertPaymentStatusJson($master, $attempt, [
            'status' => 'failed_terminal',
            'undefined_outcome' => true,
        ]);

        // Подтверждённый отказ банка (webhook → transition()).
        $result = $this->transitionService->transition($this->declineUpdate($attempt, 'evt_decline_1'));
        $this->assertTrue($result['success']);

        // Категория уточнена: локальный timeout снят, блокировки нет.
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('provider_failed', $attempt->failure_category);
        $this->assertSame('5106', $attempt->failure_code);
        $this->assertNull(app(BillingCoreWriter::class)->findExistingInFlightAttempt($workspace->id, $this->proPlan->id));
        $this->assertSame($originalMetadata, $attempt->metadata);

        // undefined_outcome снят; цикл остался Failed.
        $this->assertPaymentStatusJson($master, $attempt, ['status' => 'failed_terminal']);
        $this->assertSame(BillingCycleStatus::Failed, $attempt->billingCycle->status);

        // Checkout снова проходит (новая попытка, старая не тронута).
        $checkout = app(BillingService::class)->subscribe($master, $this->proPlan, 1, false, 'card');
        $this->assertNotNull($checkout['payment_id']);
        $this->assertNotSame($attempt->provider_payment_id, $checkout['payment_id']);
        $this->assertDatabaseCount('payment_attempts', 2);

        // Событие связано с попыткой и обработано без ошибки.
        $event = ProviderEvent::where('provider_event_id', 'evt_decline_1')->firstOrFail();
        $this->assertSame($attempt->id, $event->payment_attempt_id);
        $this->assertNull($event->processing_error);
        $this->assertNotNull($event->processed_at);
    }

    // ── 2. Timeout → confirmed success: Paid, no timeout flag ──

    public function test_confirmed_success_after_timeout_gives_paid_without_timeout_flag(): void
    {
        Http::fake();
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $attempt = $this->createTimeoutAttempt($workspace, $this->proPlan);
        $originalMetadata = $attempt->metadata;

        $this->assertPaymentStatusJson($master, $attempt, [
            'status' => 'failed_terminal',
            'undefined_outcome' => true,
        ]);

        $result = $this->transitionService->transition($this->successUpdate($attempt, 'evt_success_1'));
        $this->assertTrue($result['success']);

        // Paid + timeout failure-поля очищены success flow'ом.
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
        $this->assertNull($attempt->failure_code);
        $this->assertNull($attempt->failure_category);
        $this->assertNull($attempt->failure_message);
        $this->assertSame($originalMetadata, $attempt->metadata);
        $this->assertSame(BillingCycleStatus::Paid, $attempt->billingCycle->status);

        // Sub подписана по горизонту успеха.
        $sub = $attempt->billingCycle->billingSubscription;
        $sub->refresh();
        $this->assertSame(BillingSubscriptionStatus::Active, $sub->status);
        $this->assertTrue($sub->current_period_end->equalTo($attempt->billingCycle->period_end));

        // Нет undefined_outcome при succeeded.
        $this->assertPaymentStatusJson($master, $attempt, ['status' => 'succeeded']);

        // Повторное подтверждение успеха идемпотентно.
        $again = $this->transitionService->transition($this->successUpdate($attempt, 'evt_success_1'));
        $this->assertTrue($again['success']);
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
        $this->assertNull($attempt->failure_category);
        $this->assertDatabaseCount('provider_events', 1);
    }

    public function test_stale_timeout_category_after_success_is_not_flagged(): void
    {
        Http::fake();
        [$master, $workspace] = $this->createMasterWithWorkspace();

        // Старая запись «задним числом»: успех записан, но категория
        // reconciliation_timeout осталась (записи до этого фикса).
        $attempt = $this->createTimeoutAttempt($workspace, $this->proPlan);
        $attempt->update([
            'status' => PaymentAttemptStatus::Succeeded,
            'failure_category' => 'reconciliation_timeout',
        ]);

        $this->assertPaymentStatusJson($master, $attempt, ['status' => 'succeeded']);
    }

    // ── 3. Повторные события идемпотентны ──

    public function test_repeated_decline_events_are_idempotent(): void
    {
        Http::fake();
        [, $workspace] = $this->createMasterWithWorkspace();
        $attempt = $this->createTimeoutAttempt($workspace, $this->proPlan);

        // Первый отказ снимает timeout.
        $this->assertTrue($this->transitionService->transition($this->declineUpdate($attempt, 'evt_decline_1'))['success']);
        $attempt->refresh();
        $this->assertSame('provider_failed', $attempt->failure_category);
        $failureCode = $attempt->failure_code;

        // Точный дубль event → dedup на claim, состояние не меняется.
        $this->assertTrue($this->transitionService->transition($this->declineUpdate($attempt, 'evt_decline_1'))['success']);
        $attempt->refresh();
        $this->assertSame('provider_failed', $attempt->failure_category);
        $this->assertSame($failureCode, $attempt->failure_code);
        $this->assertDatabaseCount('provider_events', 1);

        // Новый event с другим payload → обычный same-status no-op.
        $differentPayload = new ProviderStatusUpdate(
            provider: 'tbank',
            providerEventId: 'evt_decline_2',
            providerPaymentId: $attempt->provider_payment_id,
            internalOrderId: $attempt->internal_order_id,
            normalizedStatus: PaymentAttemptStatus::FailedTerminal,
            raw: ['Status' => 'CANCELED', 'PaymentId' => $attempt->provider_payment_id],
            failureMessage: 'Платеж отменен',
        );
        $this->assertTrue($this->transitionService->transition($differentPayload)['success']);
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('provider_failed', $attempt->failure_category);
        $this->assertSame($failureCode, $attempt->failure_code);
        $this->assertSame('Отказ платежной системы', $attempt->failure_message);
        $this->assertDatabaseCount('provider_events', 2);
    }

    // ── 4. Обычный same-status no-op сохранён ──

    public function test_ordinary_confirmed_failure_same_status_stays_noop(): void
    {
        Http::fake();
        [, $workspace] = $this->createMasterWithWorkspace();

        // Уже подтверждённый отказ (не timeout) — уточнять нечего.
        $attempt = $this->createTimeoutAttempt($workspace, $this->proPlan);
        $attempt->update([
            'failure_category' => 'provider_failed',
            'failure_code' => '999',
            'failure_message' => 'Оригинальный отказ',
        ]);

        $this->assertTrue($this->transitionService->transition($this->declineUpdate($attempt, 'evt_decline_x'))['success']);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('provider_failed', $attempt->failure_category);
        // Same-status no-op: поля НЕ перезаписаны вторым событием.
        $this->assertSame('999', $attempt->failure_code);
        $this->assertSame('Оригинальный отказ', $attempt->failure_message);
        $this->assertDatabaseCount('provider_events', 1);
    }

    // ── 5. Incoming reconciliation_timeout is not a provider verdict ──

    public function test_update_claiming_timeout_category_does_not_resolve_local_timeout(): void
    {
        Http::fake();
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $attempt = $this->createTimeoutAttempt($workspace, $this->proPlan);
        $originalMetadata = $attempt->metadata;

        // Update, который САМ заявляет локальную категорию: провайдер её не
        // присылает, вердикта здесь нет — разрешать локальный timeout нельзя.
        $update = new ProviderStatusUpdate(
            provider: 'tbank',
            providerEventId: 'evt_timeout_echo',
            providerPaymentId: $attempt->provider_payment_id,
            internalOrderId: $attempt->internal_order_id,
            normalizedStatus: PaymentAttemptStatus::FailedTerminal,
            raw: ['Status' => 'REJECTED', 'PaymentId' => $attempt->provider_payment_id],
            failureCode: '9999',
            failureCategory: 'reconciliation_timeout',
            failureMessage: self::TIMEOUT_MESSAGE,
        );

        $this->assertTrue($this->transitionService->transition($update)['success']);

        // Обычный same-status no-op: поля не тронуты, timeout на месте.
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('reconciliation_timeout', $attempt->failure_category);
        $this->assertNull($attempt->failure_code);
        $this->assertSame($originalMetadata, $attempt->metadata);

        // Блокировка checkout сохраняется.
        $this->assertNotNull(app(BillingCoreWriter::class)->findExistingInFlightAttempt($workspace->id, $this->proPlan->id));
        try {
            app(BillingService::class)->subscribe($master, $this->proPlan, 1, false, 'card');
            $this->fail('Expected ValidationException while the timeout is unresolved');
        } catch (ValidationException $e) {
            $this->assertSame(self::TIMEOUT_MESSAGE, $e->errors()['plan'][0]);
        }

        // undefined_outcome сохраняется.
        $this->assertPaymentStatusJson($master, $attempt, [
            'status' => 'failed_terminal',
            'undefined_outcome' => true,
        ]);

        // Событие обработано как no-op, без ошибки.
        $event = ProviderEvent::where('provider_event_id', 'evt_timeout_echo')->firstOrFail();
        $this->assertSame($attempt->id, $event->payment_attempt_id);
        $this->assertNull($event->processing_error);
        $this->assertNotNull($event->processed_at);
        $this->assertDatabaseCount('provider_events', 1);
    }

    // ── 6. Renewal: grace, dispatch marker, metadata и notification сохранены ──

    public function test_renewal_grace_marker_and_notifications_survive_resolution(): void
    {
        Http::fake();
        Notification::fake();
        $notifier = $this->spy(MasterNotificationService::class);

        [$master, $workspace] = $this->createMasterWithWorkspace();

        $sub = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::PastDue,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->addDays(10),
            'renewal_period_months' => 1,
            'auto_renew_consent_at' => now(),
            'auto_renew_consent_version' => config('billing.recurring_terms_version'),
            'cancel_at_period_end' => false,
            'next_charge_at' => null,
            'grace_until' => now()->addDays(2),
        ]);

        $paidCycle = BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => $sub->current_period_start,
            'period_end' => $sub->current_period_end,
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Payment,
        ]);

        $renewalCycle = BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => $sub->current_period_end,
            'period_end' => now()->addDays(40),
            'status' => BillingCycleStatus::Failed,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Renewal,
        ]);

        $dispatchMarker = now()->subMinutes(5)->toISOString();
        $attempt = PaymentAttempt::create([
            'billing_cycle_id' => $renewalCycle->id,
            'provider' => 'tbank',
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'renew_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => 'tbank_renew_1',
            'status' => PaymentAttemptStatus::FailedTerminal,
            'failure_category' => 'reconciliation_timeout',
            'initiated_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(30),
            'metadata' => [
                'renewal' => true,
                'billing_subscription_id' => $sub->id,
                'charge_dispatch_started_at' => $dispatchMarker,
                'payment_method' => 'card',
            ],
        ]);

        $graceBefore = $sub->grace_until;

        // Отказ провайдера с категорией insufficient_funds — тот, что ЗАПУСТИЛ
        // бы renewal side effects, будь они подключены к уточнению.
        $decline = new ProviderStatusUpdate(
            provider: 'tbank',
            providerEventId: 'evt_renew_decline',
            providerPaymentId: $attempt->provider_payment_id,
            internalOrderId: $attempt->internal_order_id,
            normalizedStatus: PaymentAttemptStatus::FailedTerminal,
            raw: ['Status' => 'REJECTED', 'ErrorCode' => '103', 'PaymentId' => 'tbank_renew_1'],
            failureCode: '103',
            failureCategory: 'insufficient_funds',
            failureMessage: 'Недостаточно средств на карте',
        );

        $this->assertTrue($this->transitionService->transition($decline)['success']);

        // Категория уточнена провайдером.
        $attempt->refresh();
        $this->assertSame('insufficient_funds', $attempt->failure_category);
        $this->assertSame('103', $attempt->failure_code);

        // Циклы не тронуты (horizon/updateCycle к уточнению не подключены).
        $renewalCycle->refresh();
        $this->assertSame(BillingCycleStatus::Failed, $renewalCycle->status);
        $paidCycle->refresh();
        $this->assertSame(BillingCycleStatus::Paid, $paidCycle->status);

        // Grace не продлён и не переоткрыт, статус и marker не тронуты.
        $sub->refresh();
        $this->assertSame(BillingSubscriptionStatus::PastDue, $sub->status);
        $this->assertTrue($sub->grace_until->equalTo($graceBefore));
        $this->assertNull($sub->next_charge_at);
        $this->assertFalse($sub->cancel_at_period_end);

        // Metadata и dispatch marker сохранены байт в байт.
        $this->assertSame($dispatchMarker, $attempt->metadata['charge_dispatch_started_at']);
        $this->assertTrue($attempt->metadata['renewal']);
        $this->assertSame($renewalCycle->id, $attempt->billing_cycle_id);

        // Никаких notification side effects от уточнения.
        Notification::assertNothingSent();
        $notifier->shouldNotHaveReceived('sendToMaster');
        $this->assertSame(0, NotificationLog::where('type', 'renewal_insufficient_funds')->count());
    }

    public function test_resolution_does_not_open_technical_grace(): void
    {
        Http::fake();
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->assertNotNull($master);

        // Renewal timeout, у которого по какой-то причине нет grace-окна.
        $sub = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::Active,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->addDays(10),
            'renewal_period_months' => 1,
            'auto_renew_consent_at' => now(),
            'auto_renew_consent_version' => config('billing.recurring_terms_version'),
            'cancel_at_period_end' => false,
            'grace_until' => null,
        ]);

        $renewalCycle = BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => $sub->current_period_end,
            'period_end' => now()->addDays(40),
            'status' => BillingCycleStatus::Failed,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Renewal,
        ]);

        $attempt = PaymentAttempt::create([
            'billing_cycle_id' => $renewalCycle->id,
            'provider' => 'tbank',
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'renew_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => 'tbank_renew_2',
            'status' => PaymentAttemptStatus::FailedTerminal,
            'failure_category' => 'reconciliation_timeout',
            'initiated_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(30),
            'metadata' => ['renewal' => true, 'billing_subscription_id' => $sub->id],
        ]);

        $this->assertTrue($this->transitionService->transition($this->declineUpdate($attempt, 'evt_renew_decline_2'))['success']);

        // Уточнение отказа — не технический сбой: grace не открывается,
        // подписка не уходит в PastDue.
        $sub->refresh();
        $this->assertNull($sub->grace_until);
        $this->assertSame(BillingSubscriptionStatus::Active, $sub->status);

        $attempt->refresh();
        $this->assertSame('provider_failed', $attempt->failure_category);
    }
}
