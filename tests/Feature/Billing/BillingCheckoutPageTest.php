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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Own checkout page (PR1): read-only pricing only.
 *
 * No Subscription/PaymentAttempt may be created and T-Bank must never be
 * called here — POST /admin/checkout keeps owning the payment contract.
 */
class BillingCheckoutPageTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.core_entitlement' => false]);

        $this->proPlan = TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'max_appointments_per_month' => null,
            'max_masters' => 1,
            'features' => ['unlimited_appointments'],
            'is_active' => true,
        ]);
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Owner]);
    }

    public function test_checkout_requires_auth(): void
    {
        $this->get('/admin/billing/checkout?period_months=3')
            ->assertRedirect('/login');
    }

    public function test_checkout_requires_billing_permission(): void
    {
        $withoutPermission = User::factory()->create(['role' => UserRole::Master]);

        $this->actingAs($withoutPermission)
            ->get('/admin/billing/checkout?period_months=3')
            ->assertForbidden();
    }

    public function test_admin_role_also_gets_403(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->get('/admin/billing/checkout?period_months=3')
            ->assertForbidden();
    }

    public function test_invalid_period_returns_404_controlled(): void
    {
        $this->actingAs($this->owner())
            ->get('/admin/billing/checkout?period_months=5')
            ->assertNotFound();

        $this->actingAs($this->owner())
            ->get('/admin/billing/checkout?period_months=1.5')
            ->assertNotFound();
    }

    public function test_missing_period_returns_404_controlled(): void
    {
        $this->actingAs($this->owner())
            ->get('/admin/billing/checkout')
            ->assertNotFound();
    }

    public function test_inactive_pro_plan_returns_404(): void
    {
        $this->proPlan->update(['is_active' => false]);

        $this->actingAs($this->owner())
            ->get('/admin/billing/checkout?period_months=3')
            ->assertNotFound();
    }

    public function test_checkout_page_exposes_correct_price(): void
    {
        $this->actingAs($this->owner())
            ->get('/admin/billing/checkout?period_months=3')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing-checkout')
                ->where('plan.code', 'pro')
                ->where('plan.name', 'Профи')
                ->where('plan.price_monthly', 490)
                ->where('period_months', 3)
                ->where('price.base', 1470)
                ->where('price.discount_percent', 0)
                ->where('price.final', 1470)
                ->where('price.currency', 'RUB')
            );
    }

    public function test_all_allowed_periods_are_accepted(): void
    {
        foreach ([1, 3, 6, 12] as $months) {
            $this->actingAs($this->owner())
                ->get("/admin/billing/checkout?period_months={$months}")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('admin/billing-checkout')
                    ->where('period_months', $months)
                    ->where('price.final', 490 * $months)
                );
        }
    }

    public function test_checkout_page_is_read_only_no_side_effects(): void
    {
        Http::fake();

        $this->actingAs($this->owner())
            ->get('/admin/billing/checkout?period_months=6')
            ->assertOk();

        // No T-Bank / provider traffic.
        Http::assertNothingSent();

        // No billing records created.
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('billing_subscriptions', 0);
        $this->assertDatabaseCount('billing_cycles', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('provider_events', 0);
    }

    // ── pending_attempt: read-only identification of an in-flight attempt ──

    private function ownerWithWorkspace(): User
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $workspace = Workspace::create([
            'name' => 'ws-'.$owner->id,
            'owner_id' => $owner->id,
        ]);
        $workspace->ensureSlug();
        $owner->update(['workspace_id' => $workspace->id]);

        return $owner;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function seedAttempt(
        User $owner,
        ?string $providerPaymentId,
        PaymentAttemptStatus $status,
        array $metadata = [],
        ?string $failureCategory = null,
        ?int $planPricePeriodMonths = null,
    ): PaymentAttempt {
        $workspaceId = $owner->workspace_id;

        $subscription = BillingSubscription::create([
            'workspace_id' => $workspaceId,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::PendingInitial,
        ]);

        $planPriceId = null;

        if ($planPricePeriodMonths !== null) {
            $planPriceId = PlanPrice::create([
                'tariff_plan_id' => $this->proPlan->id,
                'period_months' => $planPricePeriodMonths,
                'base_amount' => 490 * $planPricePeriodMonths,
                'discount_percent' => 0,
                'final_amount' => 490 * $planPricePeriodMonths,
                'currency' => 'RUB',
                'version' => 1,
                'valid_from' => now(),
                'is_active' => true,
            ])->id;
        }

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $subscription->id,
            'workspace_id' => $workspaceId,
            'tariff_plan_id' => $this->proPlan->id,
            'plan_price_id' => $planPriceId,
            'period_start' => now(),
            'period_end' => now()->addMonths($planPricePeriodMonths ?? 1),
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
            'provider_payment_id' => $providerPaymentId,
            'status' => $status,
            'failure_category' => $failureCategory,
            'initiated_at' => now()->subHour(),
            'metadata' => $metadata,
        ]);
        $attempt->save();

        return $attempt;
    }

    public function test_checkout_exposes_null_pending_attempt_without_in_flight_rows(): void
    {
        $this->actingAs($this->owner())
            ->get('/admin/billing/checkout?period_months=3')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing-checkout')
                ->where('pending_attempt', null)
            );
    }

    public function test_checkout_exposes_in_flight_attempt_identification(): void
    {
        Http::fake();

        $owner = $this->ownerWithWorkspace();
        $this->seedAttempt(
            $owner,
            'tbank_777',
            PaymentAttemptStatus::Processing,
            ['period_months' => 6],
        );

        $this->actingAs($owner)
            ->get('/admin/billing/checkout?period_months=3')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing-checkout')
                // Период попытки (6), а не период URL (3) — кнопка после
                // отказа должна вести к попытке, а не к URL
                ->where('pending_attempt.payment_id', 'tbank_777')
                ->where('pending_attempt.period_months', 6)
                ->where('pending_attempt.status', 'processing')
            );

        // Read-only: без provider-трафика и без новых записей
        Http::assertNothingSent();
    }

    public function test_pending_attempt_period_falls_back_to_plan_price(): void
    {
        $owner = $this->ownerWithWorkspace();
        // Metadata без period_months (старый attempt) — цикл несёт plan price
        $this->seedAttempt($owner, null, PaymentAttemptStatus::Created, [], null, 12);

        $this->actingAs($owner)
            ->get('/admin/billing/checkout?period_months=3')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing-checkout')
                ->where('pending_attempt.payment_id', null)
                ->where('pending_attempt.period_months', 12)
                ->where('pending_attempt.status', 'created')
            );
    }

    public function test_pending_attempt_period_is_null_when_unrecoverable(): void
    {
        $owner = $this->ownerWithWorkspace();
        // Ни metadata-периода, ни plan price — период не выдумывается
        $this->seedAttempt($owner, 'tbank_888', PaymentAttemptStatus::Processing);

        $this->actingAs($owner)
            ->get('/admin/billing/checkout?period_months=3')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing-checkout')
                ->where('pending_attempt.payment_id', 'tbank_888')
                ->where('pending_attempt.period_months', null)
            );
    }

    public function test_pending_attempt_of_another_workspace_is_never_exposed(): void
    {
        $owner = $this->ownerWithWorkspace();
        $other = $this->ownerWithWorkspace();
        $this->seedAttempt($other, 'tbank_999', PaymentAttemptStatus::Processing, ['period_months' => 3]);

        $this->actingAs($owner)
            ->get('/admin/billing/checkout?period_months=3')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing-checkout')
                ->where('pending_attempt', null)
            );
    }

    public function test_confirmed_terminal_failure_is_not_a_pending_attempt(): void
    {
        $owner = $this->ownerWithWorkspace();
        $this->seedAttempt(
            $owner,
            'tbank_111',
            PaymentAttemptStatus::FailedTerminal,
            ['period_months' => 3],
            'provider_declined',
        );

        $this->actingAs($owner)
            ->get('/admin/billing/checkout?period_months=3')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing-checkout')
                ->where('pending_attempt', null)
            );
    }

    public function test_timeout_released_attempt_without_provider_id_is_exposed_unresolved(): void
    {
        $owner = $this->ownerWithWorkspace();
        $this->seedAttempt(
            $owner,
            null,
            PaymentAttemptStatus::FailedTerminal,
            ['payment_method' => 'card'],
            'reconciliation_timeout',
        );

        // Попытка «уточняется»: без payment_id клиенту нечего проверять
        $this->actingAs($owner)
            ->get('/admin/billing/checkout?period_months=3')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing-checkout')
                ->where('pending_attempt.payment_id', null)
                ->where('pending_attempt.status', 'failed_terminal')
            );
    }

    public function test_unpaid_sbp_attempt_exposes_its_own_payload_and_deadline(): void
    {
        $owner = $this->ownerWithWorkspace();
        $this->seedAttempt(
            $owner,
            'tbank_sbp1',
            PaymentAttemptStatus::Processing,
            [
                'payment_method' => 'sbp',
                'sbp_payload' => 'https://qr.nspk.ru/SAMEATTEMPT',
                'sbp_expires_at' => '2027-01-01T12:00:00+03:00',
                'period_months' => 3,
            ],
        );

        $this->actingAs($owner)
            ->get('/admin/billing/checkout?period_months=6')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing-checkout')
                // Продолжение той же попытки: сохранённый payload и исходный
                // абсолютный дедлайн, без нового Init и без периода URL
                ->where('pending_attempt.method', 'sbp')
                ->where('pending_attempt.sbp_payload', 'https://qr.nspk.ru/SAMEATTEMPT')
                ->where('pending_attempt.sbp_expires_at', '2027-01-01T12:00:00+03:00')
                ->where('pending_attempt.period_months', 3)
            );
    }

    public function test_card_attempt_never_carries_a_payable_sbp_link(): void
    {
        $owner = $this->ownerWithWorkspace();
        $this->seedAttempt(
            $owner,
            'tbank_card1',
            PaymentAttemptStatus::Processing,
            [
                'payment_method' => 'card',
                'checkout_url' => 'https://example.test/pay',
                'period_months' => 3,
            ],
        );

        $this->actingAs($owner)
            ->get('/admin/billing/checkout?period_months=3')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing-checkout')
                ->where('pending_attempt.method', 'card')
                ->where('pending_attempt.sbp_payload', null)
                ->where('pending_attempt.sbp_expires_at', null)
            );
    }

    public function test_unknown_method_is_never_guessed_as_sbp(): void
    {
        $owner = $this->ownerWithWorkspace();
        $this->seedAttempt($owner, 'tbank_x1', PaymentAttemptStatus::Processing, ['period_months' => 3]);

        $this->actingAs($owner)
            ->get('/admin/billing/checkout?period_months=3')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing-checkout')
                ->where('pending_attempt.method', null)
                ->where('pending_attempt.sbp_payload', null)
            );
    }

    public function test_released_sbp_attempt_withholds_the_payable_link(): void
    {
        $owner = $this->ownerWithWorkspace();
        $this->seedAttempt(
            $owner,
            'tbank_sbp2',
            PaymentAttemptStatus::FailedTerminal,
            [
                'payment_method' => 'sbp',
                'sbp_payload' => 'https://qr.nspk.ru/RELEASED',
                'sbp_expires_at' => '2027-01-01T12:00:00+03:00',
            ],
            'reconciliation_timeout',
        );

        // Локально отпущенная попытка: исход неизвестен — действующей ссылки
        // оплаты и новой оплаты не показываем, только проверка статуса
        $this->actingAs($owner)
            ->get('/admin/billing/checkout?period_months=3')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing-checkout')
                ->where('pending_attempt.status', 'failed_terminal')
                ->where('pending_attempt.sbp_payload', null)
                ->where('pending_attempt.sbp_expires_at', null)
            );
    }

    public function test_billing_page_exposes_the_returned_attempt_period(): void
    {
        $owner = $this->ownerWithWorkspace();
        $this->seedAttempt(
            $owner,
            'tbank_ret1',
            PaymentAttemptStatus::FailedTerminal,
            ['period_months' => 6],
            'provider_declined',
        );

        $this->actingAs($owner)
            ->get('/admin/billing?payment_attempt=tbank_ret1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing')
                ->where('payment_attempt_id', 'tbank_ret1')
                ->where('payment_attempt_period_months', 6)
            );
    }
}
