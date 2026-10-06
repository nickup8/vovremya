<?php

namespace Tests\Feature\Billing;

use App\Enums\PaymentAttemptStatus;
use App\Models\PaymentAttempt;
use App\Models\PlanPrice;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SbpPaymentStatusTest extends TestCase
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
     * Real checkout attempt re-pointed at the production provider —
     * the endpoint queries provider=tbank while tests default to mock.
     */
    private function attemptFromCheckout(User $master, string $paymentMethod): PaymentAttempt
    {
        app(BillingService::class)->subscribe($master, $this->proPlan, 1, false, $paymentMethod);

        $attempt = PaymentAttempt::where('internal_order_id', 'like', 'core_%')->firstOrFail();
        $attempt->update(['provider' => 'tbank']);

        return $attempt->refresh();
    }

    public function test_owner_gets_status_for_own_sbp_payment(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $attempt = $this->attemptFromCheckout($master, 'sbp');

        $response = $this->actingAs($master)
            ->get("/admin/billing/payment-status/{$attempt->provider_payment_id}");

        $response->assertOk();

        // Status string only — no metadata, payload or internal ids
        $response->assertExactJson(['status' => 'processing']);
    }

    public function test_foreign_workspace_gets_404(): void
    {
        [$owner] = $this->createMasterWithWorkspace();
        $attempt = $this->attemptFromCheckout($owner, 'sbp');

        [$other] = $this->createMasterWithWorkspace();

        $this->actingAs($other)
            ->get("/admin/billing/payment-status/{$attempt->provider_payment_id}")
            ->assertNotFound();
    }

    public function test_card_payment_gets_status(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $attempt = $this->attemptFromCheckout($master, 'card');

        $response = $this->actingAs($master)
            ->get("/admin/billing/payment-status/{$attempt->provider_payment_id}");

        $response->assertOk();

        // Same contract as SBP: status string only, workspace-scoped
        $response->assertExactJson(['status' => 'processing']);
    }

    public function test_card_payment_unknown_outcome_is_reported_verbatim(): void
    {
        [$master] = $this->createMasterWithWorkspace();
        $attempt = $this->attemptFromCheckout($master, 'card');
        $attempt->update(['status' => PaymentAttemptStatus::Unknown]);

        $this->actingAs($master)
            ->get("/admin/billing/payment-status/{$attempt->provider_payment_id}")
            ->assertOk()
            ->assertExactJson(['status' => 'unknown']);
    }

    public function test_unknown_payment_gets_404(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $this->actingAs($master)
            ->get('/admin/billing/payment-status/tbank_unknown_payment')
            ->assertNotFound();
    }

    public function test_user_without_workspace_gets_404(): void
    {
        $user = User::factory()->master()->create();
        $this->assertNull($user->workspace_id);

        $this->actingAs($user)
            ->get('/admin/billing/payment-status/tbank_any')
            ->assertNotFound();
    }
}
