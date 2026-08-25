<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\ServiceCatalog;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServiceReactivationConfigTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $startPlan;
    private TariffPlan $proPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startPlan = TariffPlan::create([
            'code' => 'start', 'name' => 'Старт', 'price_monthly' => 0,
            'features' => ['calendar', 'basic_client_management'], 'is_active' => true,
        ]);

        $this->proPlan = TariffPlan::create([
            'code' => 'pro', 'name' => 'Профи', 'price_monthly' => 490,
            'features' => ['unlimited_appointments', 'client_management', 'channel_analytics', 'client_reactivation'],
            'is_active' => true,
        ]);
    }

    private function createOwnerWithSubscription(TariffPlan $plan): array
    {
        $owner = User::factory()->master()->create();
        $workspace = Workspace::create(['name' => 'WS ' . Str::random(6), 'owner_id' => $owner->id]);
        $owner->update(['workspace_id' => $workspace->id, 'role' => UserRole::Owner]);

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'period_months' => 1,
            'amount_paid' => $plan->price_monthly,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $workspace->id,
            'title' => 'Стрижка ' . Str::random(4),
            'base_price' => 1500,
            'base_duration' => 30,
            'is_active' => true,
        ]);

        return [$owner, $workspace, $catalog];
    }

    // ── Existing service after migration ──────────────────

    public function test_existing_service_has_null_reactivation_days(): void
    {
        [$owner, $ws, $catalog] = $this->createOwnerWithSubscription($this->proPlan);

        $this->assertNull($catalog->reactivation_days);
    }

    // ── Pro sets cycle ────────────────────────────────────

    public function test_pro_can_set_reactivation_days(): void
    {
        [$owner, $ws, $catalog] = $this->createOwnerWithSubscription($this->proPlan);

        $response = $this->actingAs($owner)
            ->patch("/admin/catalog/{$catalog->id}/reactivation", [
                'reactivation_days' => 21,
            ]);

        $response->assertRedirect();
        $catalog->refresh();
        $this->assertSame(21, $catalog->reactivation_days);
    }

    // ── Pro changes cycle ─────────────────────────────────

    public function test_pro_can_change_reactivation_days(): void
    {
        [$owner, $ws, $catalog] = $this->createOwnerWithSubscription($this->proPlan);
        $catalog->update(['reactivation_days' => 21]);

        $this->actingAs($owner)
            ->patch("/admin/catalog/{$catalog->id}/reactivation", [
                'reactivation_days' => 45,
            ]);

        $catalog->refresh();
        $this->assertSame(45, $catalog->reactivation_days);
    }

    // ── Pro disables ──────────────────────────────────────

    public function test_pro_can_disable_reactivation(): void
    {
        [$owner, $ws, $catalog] = $this->createOwnerWithSubscription($this->proPlan);
        $catalog->update(['reactivation_days' => 21]);

        $this->actingAs($owner)
            ->patch("/admin/catalog/{$catalog->id}/reactivation", [
                'reactivation_days' => null,
            ]);

        $catalog->refresh();
        $this->assertNull($catalog->reactivation_days);
    }

    // ── Validation ────────────────────────────────────────

    public function test_validation_rejects_zero(): void
    {
        [$owner, $ws, $catalog] = $this->createOwnerWithSubscription($this->proPlan);

        $response = $this->actingAs($owner)
            ->patch("/admin/catalog/{$catalog->id}/reactivation", [
                'reactivation_days' => 0,
            ]);

        $response->assertSessionHasErrors('reactivation_days');
    }

    public function test_validation_rejects_negative(): void
    {
        [$owner, $ws, $catalog] = $this->createOwnerWithSubscription($this->proPlan);

        $response = $this->actingAs($owner)
            ->patch("/admin/catalog/{$catalog->id}/reactivation", [
                'reactivation_days' => -5,
            ]);

        $response->assertSessionHasErrors('reactivation_days');
    }

    public function test_validation_rejects_non_integer(): void
    {
        [$owner, $ws, $catalog] = $this->createOwnerWithSubscription($this->proPlan);

        $response = $this->actingAs($owner)
            ->patch("/admin/catalog/{$catalog->id}/reactivation", [
                'reactivation_days' => 'abc',
            ]);

        $response->assertSessionHasErrors('reactivation_days');
    }

    // ── Start entitlement ─────────────────────────────────

    public function test_start_user_gets_403(): void
    {
        [$owner, $ws, $catalog] = $this->createOwnerWithSubscription($this->startPlan);

        $response = $this->actingAs($owner)
            ->patch("/admin/catalog/{$catalog->id}/reactivation", [
                'reactivation_days' => 21,
            ]);

        $response->assertForbidden();
        $catalog->refresh();
        $this->assertNull($catalog->reactivation_days);
    }

    // ── Pro entitlement ───────────────────────────────────

    public function test_pro_user_succeeds(): void
    {
        [$owner, $ws, $catalog] = $this->createOwnerWithSubscription($this->proPlan);

        $response = $this->actingAs($owner)
            ->patch("/admin/catalog/{$catalog->id}/reactivation", [
                'reactivation_days' => 30,
            ]);

        $response->assertRedirect();
        $catalog->refresh();
        $this->assertSame(30, $catalog->reactivation_days);
    }

    // ── Cross-workspace ───────────────────────────────────

    public function test_cross_workspace_blocked(): void
    {
        [$ownerA, $wsA, $catalogA] = $this->createOwnerWithSubscription($this->proPlan);
        [$ownerB, $wsB, $catalogB] = $this->createOwnerWithSubscription($this->proPlan);

        $response = $this->actingAs($ownerB)
            ->patch("/admin/catalog/{$catalogA->id}/reactivation", [
                'reactivation_days' => 21,
            ]);

        $response->assertForbidden();
        $catalogA->refresh();
        $this->assertNull($catalogA->reactivation_days);
    }

    // ── General endpoint bypass ───────────────────────────

    public function test_general_update_cannot_set_reactivation_days(): void
    {
        [$owner, $ws, $catalog] = $this->createOwnerWithSubscription($this->startPlan);

        $this->actingAs($owner)
            ->put("/admin/catalog/{$catalog->id}", [
                'title' => $catalog->title,
                'base_price' => 1500,
                'base_duration' => 30,
                'is_active' => true,
                'reactivation_days' => 21,
            ]);

        $catalog->refresh();
        $this->assertNull($catalog->reactivation_days);
    }
}
