<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientReactivationOptOutTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;
    private TariffPlan $startPlan;

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

        $client = Client::factory()->create([
            'user_id' => $owner->id,
            'workspace_id' => $workspace->id,
        ]);

        return [$owner, $workspace, $client];
    }

    // ── Default ───────────────────────────────────────────

    public function test_new_client_has_reactivation_enabled(): void
    {
        [$owner, $ws, $client] = $this->createOwnerWithSubscription($this->proPlan);

        $this->assertFalse($client->refresh()->disable_reactivation);
    }

    // ── Pro disables ──────────────────────────────────────

    public function test_pro_can_disable_reactivation(): void
    {
        [$owner, $ws, $client] = $this->createOwnerWithSubscription($this->proPlan);

        $response = $this->actingAs($owner)
            ->patch("/admin/clients/{$client->id}/reactivation", [
                'disable_reactivation' => true,
            ]);

        $response->assertRedirect();
        $client->refresh();
        $this->assertTrue($client->disable_reactivation);
    }

    // ── Pro enables again ─────────────────────────────────

    public function test_pro_can_enable_reactivation(): void
    {
        [$owner, $ws, $client] = $this->createOwnerWithSubscription($this->proPlan);
        $client->update(['disable_reactivation' => true]);

        $this->actingAs($owner)
            ->patch("/admin/clients/{$client->id}/reactivation", [
                'disable_reactivation' => false,
            ]);

        $client->refresh();
        $this->assertFalse($client->disable_reactivation);
    }

    // ── Idempotency ───────────────────────────────────────

    public function test_double_patch_is_idempotent(): void
    {
        [$owner, $ws, $client] = $this->createOwnerWithSubscription($this->proPlan);

        $this->actingAs($owner)
            ->patch("/admin/clients/{$client->id}/reactivation", [
                'disable_reactivation' => true,
            ]);

        $this->actingAs($owner)
            ->patch("/admin/clients/{$client->id}/reactivation", [
                'disable_reactivation' => true,
            ]);

        $client->refresh();
        $this->assertTrue($client->disable_reactivation);
    }

    // ── Start ─────────────────────────────────────────────

    public function test_start_user_gets_403(): void
    {
        [$owner, $ws, $client] = $this->createOwnerWithSubscription($this->startPlan);

        $response = $this->actingAs($owner)
            ->patch("/admin/clients/{$client->id}/reactivation", [
                'disable_reactivation' => true,
            ]);

        $response->assertForbidden();
        $client->refresh();
        $this->assertFalse($client->disable_reactivation);
    }

    // ── Cross-workspace ───────────────────────────────────

    public function test_cross_workspace_blocked(): void
    {
        [$ownerA, $wsA, $clientA] = $this->createOwnerWithSubscription($this->proPlan);
        [$ownerB, $wsB, $clientB] = $this->createOwnerWithSubscription($this->proPlan);

        $response = $this->actingAs($ownerB)
            ->patch("/admin/clients/{$clientA->id}/reactivation", [
                'disable_reactivation' => true,
            ]);

        $response->assertForbidden();
        $clientA->refresh();
        $this->assertFalse($clientA->disable_reactivation);
    }

    // ── General update bypass ─────────────────────────────

    public function test_general_update_cannot_set_disable_reactivation(): void
    {
        [$owner, $ws, $client] = $this->createOwnerWithSubscription($this->startPlan);

        $this->actingAs($owner)
            ->put("/admin/clients/{$client->id}", [
                'name' => $client->name,
                'phone' => $client->phone,
                'disable_reactivation' => true,
            ]);

        $client->refresh();
        $this->assertFalse($client->disable_reactivation);
    }

    // ── No side effects ───────────────────────────────────

    public function test_no_side_effects_on_block_or_consent(): void
    {
        [$owner, $ws, $client] = $this->createOwnerWithSubscription($this->proPlan);
        $client->update(['pdn_consent_at' => now(), 'pdn_consent_version' => '1.0']);

        $this->actingAs($owner)
            ->patch("/admin/clients/{$client->id}/reactivation", [
                'disable_reactivation' => true,
            ]);

        $client->refresh();
        $this->assertFalse($client->is_blocked);
        $this->assertNotNull($client->pdn_consent_at);
        $this->assertEquals('1.0', $client->pdn_consent_version);
        $this->assertEquals(0, ClientConsent::count());
    }
}
