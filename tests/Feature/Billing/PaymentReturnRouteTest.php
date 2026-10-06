<?php

namespace Tests\Feature\Billing;

use App\Enums\PaymentAttemptStatus;
use App\Enums\UserRole;
use App\Models\PaymentAttempt;
use App\Models\PlanPrice;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The return routes are verdict-free: they hand over the checkout attempt
 * bound at POST /admin/checkout so the billing page can verify it against
 * Billing Core. FailURL proves nothing; the webhook owns the lifecycle.
 * The ?payment_attempt= URL param keeps the id alive across reloads.
 */
class PaymentReturnRouteTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->proPlan = TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'max_appointments_per_month' => null,
            'max_masters' => 1,
            'features' => ['unlimited_appointments'],
            'is_active' => true,
        ]);

        foreach ([1, 3, 6, 12] as $months) {
            PlanPrice::create([
                'tariff_plan_id' => $this->proPlan->id,
                'period_months' => $months,
                'base_amount' => 490 * $months,
                'discount_percent' => 0,
                'final_amount' => 490 * $months,
                'currency' => 'RUB',
                'version' => 1,
                'valid_from' => now(),
                'is_active' => true,
            ]);
        }
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Owner]);
    }

    private function ownerWithWorkspace(): User
    {
        $owner = $this->owner();
        $workspace = Workspace::create([
            'name' => 'ws-'.$owner->id,
            'owner_id' => $owner->id,
        ]);
        $workspace->ensureSlug();
        $owner->update(['workspace_id' => $workspace->id]);

        return $owner;
    }

    private function checkoutAs(User $owner): PaymentAttempt
    {
        $this->actingAs($owner)
            ->postJson('/admin/checkout', [
                'tariff_plan_id' => $this->proPlan->id,
                'period_months' => 1,
                'payment_method' => 'card',
            ])
            ->assertOk();

        return PaymentAttempt::where('internal_order_id', 'like', 'core_%')->latest('created_at')->firstOrFail();
    }

    // ── Binding: return carries the exact checkout attempt ──

    public function test_card_checkout_binds_attempt_to_success_return(): void
    {
        $owner = $this->owner();
        $attempt = $this->checkoutAs($owner);

        $response = $this->actingAs($owner)->get('/admin/billing/payment/success');

        $response->assertRedirect('/admin/billing?payment_attempt='.$attempt->provider_payment_id);
        // No verdict flash — the attempt id is the only handover.
        $response->assertSessionMissing('payment_return');
    }

    public function test_card_checkout_binds_attempt_to_failed_return(): void
    {
        $owner = $this->owner();
        $attempt = $this->checkoutAs($owner);

        // FailURL must behave exactly like SuccessURL: bind the attempt,
        // never claim a decline.
        $response = $this->actingAs($owner)->get('/admin/billing/payment/failed');

        $response->assertRedirect('/admin/billing?payment_attempt='.$attempt->provider_payment_id);
        $response->assertSessionMissing('payment_return');
    }

    public function test_return_binds_specific_attempt_not_the_latest_one(): void
    {
        $owner = $this->owner();
        $boundAttempt = $this->checkoutAs($owner);

        // The bound attempt gets confirmed (webhook processed) …
        $boundAttempt->update(['status' => PaymentAttemptStatus::Succeeded]);

        // … and a newer attempt appears in the same workspace afterwards.
        app(BillingService::class)->subscribe($owner, $this->proPlan, 3, false, 'card');
        $latest = PaymentAttempt::where('internal_order_id', 'like', 'core_%')
            ->where('id', '!=', $boundAttempt->id)
            ->latest('created_at')
            ->firstOrFail();
        $this->assertNotSame($boundAttempt->provider_payment_id, $latest->provider_payment_id);

        // The return still carries the originally bound attempt, not the latest.
        $this->actingAs($owner)
            ->get('/admin/billing/payment/success')
            ->assertRedirect('/admin/billing?payment_attempt='.$boundAttempt->provider_payment_id);
    }

    public function test_return_without_bound_attempt_is_neutral(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner)->get('/admin/billing/payment/success');

        $response->assertRedirect('/admin/billing');
        $response->assertSessionHas('payment_return', 'returned');
        $response->assertSessionMissing('payment_return_attempt');
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
        $this->actingAs($owner)->get('/admin/billing/payment/failed');

        $this->assertDatabaseCount('billing_subscriptions', 0);
        $this->assertDatabaseCount('billing_cycles', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    // ── Billing page props: signal + id, never a verdict ──

    public function test_index_exposes_neutral_signal_without_attempt(): void
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
                ->where('payment_return', 'returned')
                ->where('payment_attempt_id', null)
            );

        // Signal is one-shot: the next plain visit gets null.
        $this->actingAs($owner)
            ->get('/admin/billing')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing')
                ->where('payment_return', null)
            );
    }

    public function test_index_keeps_attempt_id_across_reloads(): void
    {
        $owner = $this->owner();
        $attempt = $this->checkoutAs($owner);

        $this->actingAs($owner)
            ->get('/admin/billing/payment/success')
            ->assertRedirect('/admin/billing?payment_attempt='.$attempt->provider_payment_id);

        // First visit and a reload both see the id and the signal from the
        // URL — the one-shot flash is not what keeps the dialog alive.
        $this->actingAs($owner)
            ->get('/admin/billing?payment_attempt='.$attempt->provider_payment_id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing')
                ->where('payment_return', 'returned')
                ->where('payment_attempt_id', $attempt->provider_payment_id)
            );

        $this->actingAs($owner)
            ->get('/admin/billing?payment_attempt='.$attempt->provider_payment_id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing')
                ->where('payment_return', 'returned')
                ->where('payment_attempt_id', $attempt->provider_payment_id)
            );
    }

    public function test_index_drops_foreign_attempt_id(): void
    {
        $ownerA = $this->ownerWithWorkspace();
        $attempt = $this->checkoutAs($ownerA);

        $ownerB = $this->ownerWithWorkspace();

        // Foreign id: signal stays (the URL says "a return happened") but the
        // id is dropped — no status of another workspace is ever exposed.
        $this->actingAs($ownerB)
            ->get('/admin/billing?payment_attempt='.$attempt->provider_payment_id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing')
                ->where('payment_return', 'returned')
                ->where('payment_attempt_id', null)
            );
    }

    public function test_index_drops_unknown_attempt_id(): void
    {
        $owner = $this->ownerWithWorkspace();

        $this->actingAs($owner)
            ->get('/admin/billing?payment_attempt=tbank_unknown_payment')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing')
                ->where('payment_return', 'returned')
                ->where('payment_attempt_id', null)
            );
    }

    // ── Verification source of truth: local attempt before/after webhook ──

    public function test_return_before_webhook_reports_processing_and_after_webhook_succeeded(): void
    {
        $owner = $this->owner();
        $attempt = $this->checkoutAs($owner);
        $attempt->update(['provider' => 'tbank']);

        $this->actingAs($owner)
            ->get('/admin/billing/payment/success')
            ->assertRedirect('/admin/billing?payment_attempt='.$attempt->provider_payment_id);

        // Before the webhook: still processing → the client keeps polling.
        $this->actingAs($owner)
            ->get('/admin/billing/payment-status/'.$attempt->provider_payment_id)
            ->assertOk()
            ->assertExactJson(['status' => 'processing']);

        // Webhook processed (Billing Core confirmed the payment) …
        $attempt->refresh()->update(['status' => PaymentAttemptStatus::Succeeded]);

        // … and only now the status endpoint reports success.
        $this->actingAs($owner)
            ->get('/admin/billing/payment-status/'.$attempt->provider_payment_id)
            ->assertOk()
            ->assertExactJson(['status' => 'succeeded']);
    }

    public function test_card_status_is_not_readable_from_foreign_workspace(): void
    {
        $ownerA = $this->ownerWithWorkspace();
        $attempt = $this->checkoutAs($ownerA);
        $attempt->update(['provider' => 'tbank']);

        $ownerB = $this->ownerWithWorkspace();

        $this->actingAs($ownerB)
            ->get('/admin/billing/payment-status/'.$attempt->provider_payment_id)
            ->assertNotFound();
    }
}
