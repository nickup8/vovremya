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
use App\Services\Payment\PaymentReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SBP expiry → timely status refresh.
 *
 * After the stored absolute sbp_expires_at the minute reconciliation owes
 * ONE GetState regardless of the old backoff (the user is still waiting);
 * every following check is bounded by the advanced backoff, the one-shot
 * marker and the shared per-attempt lock+cooldown that also guards the
 * manual POST check. poll_count is never reset, the GET status endpoint
 * stays strictly local, and only a final bank verdict goes through the
 * existing PaymentTransitionService.
 */
class SbpExpiryStatusCheckTest extends TestCase
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
    }

    // ── Helpers ──

    /**
     * In-flight SBP attempt with the incident's shape: old backoff still
     * running (poll_count=4 → 900s window not elapsed) while the stored
     * absolute link deadline has already passed.
     *
     * @param  array<string, mixed>  $metadataOverrides
     */
    private function createWaitingSbpAttempt(array $metadataOverrides = []): PaymentAttempt
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

        $attempt = new PaymentAttempt([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'tbank',
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'core_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => 'tbank_'.bin2hex(random_bytes(6)),
            'status' => PaymentAttemptStatus::Processing,
            'initiated_at' => now()->subHour(),
            'metadata' => array_merge([
                'payment_method' => 'sbp',
                'sbp_payload' => 'https://qr.nspk.ru/SAMEATTEMPT',
                'sbp_expires_at' => now()->subMinutes(2)->toIso8601String(),
                // Инцидент: старый backoff ещё держит попытку
                'poll_count' => 4,
                'last_polled_at' => now()->subMinutes(5)->toIso8601String(),
            ], $metadataOverrides),
        ]);
        $attempt->created_at = now()->subHour();
        $attempt->save();

        return $attempt;
    }

    private function fakeGetState(string $status, array $extra = []): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response(array_merge([
                'Success' => true,
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

    // ── Пункт1: своевременный GetState после дедлайна ──

    public function test_old_backoff_does_not_delay_the_first_post_expiry_check(): void
    {
        $attempt = $this->createWaitingSbpAttempt();
        $this->fakeGetState('NEW', [
            'PaymentId' => $attempt->provider_payment_id,
            'OrderId' => $attempt->internal_order_id,
        ]);

        app(PaymentReconciliationService::class)->reconcile();

        // Один своевременный GetState, несмотря на poll_count=4/900s backoff
        $this->assertSame(1, $this->getStateRequestCount());

        $attempt->refresh();
        $metadata = $attempt->metadata;
        // poll_count не сброшен — увеличен; one-shot маркер потреблён
        $this->assertSame(5, $metadata['poll_count']);
        $this->assertNotEmpty($metadata['sbp_expiry_polled_at']);
        $this->assertNotEmpty($metadata['last_polled_at']);
        // NEW — не финальный вердикт: попытка продолжает ждать
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        // Существующие ключи metadata целы
        $this->assertSame('sbp', $metadata['payment_method']);
        $this->assertSame('https://qr.nspk.ru/SAMEATTEMPT', $metadata['sbp_payload']);
    }

    public function test_post_expiry_bypass_is_one_shot_for_later_cron_passes(): void
    {
        $attempt = $this->createWaitingSbpAttempt();
        $this->fakeGetState('NEW', [
            'PaymentId' => $attempt->provider_payment_id,
            'OrderId' => $attempt->internal_order_id,
        ]);

        $service = app(PaymentReconciliationService::class);
        $service->reconcile();
        $this->assertSame(1, $this->getStateRequestCount());

        // Следующие прогоны минутного cron: one-shot потреблён, обычный
        // backoff (продвинутый после первого GetState) держит банк закрытым
        $service->reconcile();
        $service->reconcile();
        $this->assertSame(1, $this->getStateRequestCount());
    }

    public function test_bypass_does_not_run_before_the_deadline(): void
    {
        $attempt = $this->createWaitingSbpAttempt([
            'sbp_expires_at' => now()->addMinutes(10)->toIso8601String(),
        ]);
        $this->fakeGetState('NEW');

        app(PaymentReconciliationService::class)->reconcile();

        // До дедлайна действует только старый backoff — он ещё не истёк
        $this->assertSame(0, $this->getStateRequestCount());
        $this->assertArrayNotHasKey('sbp_expiry_polled_at', $attempt->refresh()->metadata);
    }

    // ── Пункт2: lock + cooldown, ручной POST ──

    public function test_manual_check_respects_the_shared_cooldown(): void
    {
        $attempt = $this->createWaitingSbpAttempt();
        $this->fakeGetState('NEW', [
            'PaymentId' => $attempt->provider_payment_id,
            'OrderId' => $attempt->internal_order_id,
        ]);

        $first = $this->actingAs($this->owner)
            ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
            ->assertOk()
            ->assertExactJson([
                'status' => 'processing',
                'checked' => true,
                // Контракт /check: доступный SBP-payload этой же попытки
                'sbp_payload' => 'https://qr.nspk.ru/SAMEATTEMPT',
                'sbp_expires_at' => $attempt->metadata['sbp_expires_at'],
            ]);
        $this->assertSame(1, $this->getStateRequestCount());

        // Повторный клик/вкладка в пределах cooldown — без HTTP, но payload
        // свежей строки по-прежнему отдаётся
        $this->actingAs($this->owner)
            ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
            ->assertOk()
            ->assertExactJson([
                'status' => 'processing',
                'checked' => false,
                'sbp_payload' => 'https://qr.nspk.ru/SAMEATTEMPT',
                'sbp_expires_at' => $attempt->metadata['sbp_expires_at'],
            ]);
        $this->assertSame(1, $this->getStateRequestCount());

        // По истечении cooldown (60s) следующая проверка снова доходит до банка
        $this->travel(61)->seconds();
        $this->actingAs($this->owner)
            ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
            ->assertOk()
            ->assertExactJson([
                'status' => 'processing',
                'checked' => true,
                'sbp_payload' => 'https://qr.nspk.ru/SAMEATTEMPT',
                'sbp_expires_at' => $attempt->metadata['sbp_expires_at'],
            ]);
        $this->assertSame(2, $this->getStateRequestCount());
        $this->travelBack();

        // poll_count ручной проверкой не тронут
        $this->assertSame(4, $attempt->refresh()->metadata['poll_count']);
    }

    public function test_held_lock_blocks_a_concurrent_check_without_http(): void
    {
        $attempt = $this->createWaitingSbpAttempt();
        Http::fake();

        $lock = Cache::lock('billing:payment-status-check:'.$attempt->getKey(), 30);
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($this->owner)
                ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
                ->assertOk()
                ->assertExactJson([
                    'status' => 'processing',
                    'checked' => false,
                    // Lock-held skip не мешает отдать payload свежей строки
                    'sbp_payload' => 'https://qr.nspk.ru/SAMEATTEMPT',
                    'sbp_expires_at' => $attempt->metadata['sbp_expires_at'],
                ]);
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
    }

    public function test_background_bypass_and_manual_check_share_one_cooldown(): void
    {
        $attempt = $this->createWaitingSbpAttempt();
        $this->fakeGetState('NEW', [
            'PaymentId' => $attempt->provider_payment_id,
            'OrderId' => $attempt->internal_order_id,
        ]);

        // Ручная проверка первой забирает cooldown — cron в это же окно
        // не ходит в банк (checked=false), one-shot не потребляется
        $this->actingAs($this->owner)
            ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
            ->assertOk()
            ->assertJson(['checked' => true]);
        $this->assertSame(1, $this->getStateRequestCount());

        app(PaymentReconciliationService::class)->reconcile();
        $this->assertSame(1, $this->getStateRequestCount());
        $this->assertArrayNotHasKey('sbp_expiry_polled_at', $attempt->refresh()->metadata);

        // Окно cooldown истекло — фоновая проверка дорывается до банка
        // и потребляет one-shot
        $this->travel(61)->seconds();
        app(PaymentReconciliationService::class)->reconcile();
        $this->assertSame(2, $this->getStateRequestCount());
        $this->assertArrayHasKey('sbp_expiry_polled_at', $attempt->refresh()->metadata);
        $this->travelBack();
    }

    // ── Пункт2: права, workspace, provider — до HTTP ──

    public function test_foreign_workspace_gets_404_without_http(): void
    {
        $attempt = $this->createWaitingSbpAttempt();
        Http::fake();

        $foreign = User::factory()->create(['role' => UserRole::Owner]);
        $foreignWorkspace = Workspace::create([
            'name' => 'ws-foreign-'.$foreign->id,
            'owner_id' => $foreign->id,
        ]);
        $foreignWorkspace->ensureSlug();
        $foreign->update(['workspace_id' => $foreignWorkspace->id]);

        $this->actingAs($foreign)
            ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_non_billing_role_gets_403_without_http(): void
    {
        $attempt = $this->createWaitingSbpAttempt();
        Http::fake();

        $this->actingAs(User::factory()->create(['role' => UserRole::Master]))
            ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_mock_provider_attempt_is_not_checked(): void
    {
        $attempt = $this->createWaitingSbpAttempt(['payment_method' => 'card']);
        $attempt->update(['provider' => 'mock']);
        Http::fake();

        $this->actingAs($this->owner)
            ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
            ->assertNotFound();

        Http::assertNothingSent();
    }

    // ── Пункт3: вердикты через существующий PaymentTransitionService ──

    public function test_deadline_expired_manual_check_is_a_confirmed_decline(): void
    {
        $attempt = $this->createWaitingSbpAttempt();
        $this->fakeGetState('DEADLINE_EXPIRED', [
            'PaymentId' => $attempt->provider_payment_id,
            'OrderId' => $attempt->internal_order_id,
        ]);

        $this->actingAs($this->owner)
            ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
            ->assertOk()
            // Подтверждённый отказ банка — без undefined_outcome
            ->assertExactJson(['status' => 'failed_terminal', 'checked' => true]);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $attempt->status);
        $this->assertSame('provider_failed', $attempt->failure_category);

        // Только подтверждённый отказ — checkout после него возможен
        // (блок снимается существующим переходом, новый Init тут не нужен)
        $this->actingAs($this->owner)
            ->post('/admin/checkout', [
                'tariff_plan_id' => $this->proPlan->id,
                'period_months' => 1,
                'payment_method' => 'sbp',
            ])
            ->assertOk();

        // Существующая metadata попытки не потеряна
        $metadata = $attempt->refresh()->metadata;
        $this->assertSame('sbp', $metadata['payment_method']);
        $this->assertNotEmpty($metadata['sbp_payload']);
    }

    public function test_confirmed_manual_check_succeeds_via_transition(): void
    {
        $attempt = $this->createWaitingSbpAttempt();
        $this->fakeGetState('CONFIRMED', [
            'PaymentId' => $attempt->provider_payment_id,
            'OrderId' => $attempt->internal_order_id,
        ]);

        $this->actingAs($this->owner)
            ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
            ->assertOk()
            ->assertExactJson(['status' => 'succeeded', 'checked' => true]);

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);
        $this->assertSame(BillingCycleStatus::Paid, $attempt->billingCycle->status);
    }

    public function test_null_answer_keeps_waiting_without_new_init(): void
    {
        $attempt = $this->createWaitingSbpAttempt();
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response(['Success' => false], 200),
        ]);

        $this->actingAs($this->owner)
            ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
            ->assertOk()
            ->assertExactJson([
                'status' => 'processing',
                'checked' => true,
                'sbp_payload' => 'https://qr.nspk.ru/SAMEATTEMPT',
                'sbp_expires_at' => $attempt->metadata['sbp_expires_at'],
            ]);

        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->refresh()->status);

        // Ни одного Init — только GetState
        $this->assertSame(0, Http::recorded()
            ->filter(fn (array $pair) => str_contains($pair[0]->url(), '/v2/Init'))
            ->count());
    }

    public function test_transport_exception_keeps_waiting(): void
    {
        $attempt = $this->createWaitingSbpAttempt();
        Http::fake(function () {
            throw new \RuntimeException('network down');
        });

        $this->actingAs($this->owner)
            ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
            ->assertOk()
            // Ошибка транспорта — не вердикт: ожидание сохраняется, а
            // payload свежей строки отдаётся
            ->assertExactJson([
                'status' => 'processing',
                'checked' => true,
                'sbp_payload' => 'https://qr.nspk.ru/SAMEATTEMPT',
                'sbp_expires_at' => $attempt->metadata['sbp_expires_at'],
            ]);

        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->refresh()->status);
    }

    public function test_new_status_never_becomes_a_transition(): void
    {
        $attempt = $this->createWaitingSbpAttempt();
        $this->fakeGetState('NEW', [
            'PaymentId' => $attempt->provider_payment_id,
            'OrderId' => $attempt->internal_order_id,
        ]);

        $this->actingAs($this->owner)
            ->post("/admin/billing/payment-status/{$attempt->provider_payment_id}/check")
            ->assertOk()
            ->assertExactJson([
                'status' => 'processing',
                'checked' => true,
                'sbp_payload' => 'https://qr.nspk.ru/SAMEATTEMPT',
                'sbp_expires_at' => $attempt->metadata['sbp_expires_at'],
            ]);

        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->refresh()->status);
        // NEW не порождает provider-события — переход не применялся
        $this->assertDatabaseCount('provider_events', 0);
    }

    // ── Пункт4: GET остаётся read-only ──

    public function test_get_payment_status_stays_local_and_read_only(): void
    {
        $attempt = $this->createWaitingSbpAttempt();
        Http::fake();

        $this->actingAs($this->owner)
            ->get("/admin/billing/payment-status/{$attempt->provider_payment_id}")
            ->assertOk()
            ->assertExactJson(['status' => 'processing']);

        Http::assertNothingSent();
        $this->assertSame(4, $attempt->refresh()->metadata['poll_count']);
    }
}
