<?php

namespace Tests\Feature\Billing;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Payment return routes are UX-only: they set a one-shot flash and redirect.
 * No billing data may be read or mutated here — webhook / Billing Core owns
 * the payment status.
 */
class PaymentReturnRouteTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Owner]);
    }

    public function test_success_route_redirects_with_flash(): void
    {
        $response = $this->actingAs($this->owner())
            ->get('/admin/billing/payment/success');

        $response->assertRedirect('/admin/billing');
        $response->assertSessionHas('payment_return', 'success');
    }

    public function test_failed_route_redirects_with_flash(): void
    {
        $response = $this->actingAs($this->owner())
            ->get('/admin/billing/payment/failed');

        $response->assertRedirect('/admin/billing');
        $response->assertSessionHas('payment_return', 'failed');
    }

    public function test_return_routes_require_auth(): void
    {
        $this->get('/admin/billing/payment/success')->assertRedirect('/login');
        $this->get('/admin/billing/payment/failed')->assertRedirect('/login');
    }

    public function test_return_routes_do_not_mutate_billing_data(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->get('/admin/billing/payment/success');

        $this->assertDatabaseCount('billing_subscriptions', 0);
        $this->assertDatabaseCount('billing_cycles', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_index_exposes_only_allowed_payment_return_values(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)
            ->get('/admin/billing/payment/success')
            ->assertRedirect('/admin/billing');

        $this->actingAs($owner)
            ->get('/admin/billing')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing')
                ->where('payment_return', 'success')
            );

        // Flash is one-shot: the next visit gets null.
        $this->actingAs($owner)
            ->get('/admin/billing')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing')
                ->where('payment_return', null)
            );
    }
}
