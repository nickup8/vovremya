<?php

namespace Tests\Feature\Billing;

use App\Enums\UserRole;
use App\Models\TariffPlan;
use App\Models\User;
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
}
