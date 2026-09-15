<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\TariffLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanManagementTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $startPlan;
    private TariffPlan $proPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startPlan = TariffPlan::create([
            'code' => 'start',
            'name' => 'Старт',
            'price_monthly' => 0,
            'max_appointments_per_month' => 7,
            'max_masters' => 1,
            'features' => ['calendar', 'basic_client_management'],
            'is_active' => true,
        ]);

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

    public function test_start_limit_from_db_not_hardcoded(): void
    {
        $workspace = Workspace::create([
            'name' => 'Test',
            'owner_id' => User::factory()->create()->id,
        ]);

        $service = app(TariffLimitService::class);

        $this->assertSame(7, $service->getMonthlyLimit($workspace));
    }

    public function test_free_workspace_uses_start_plan_limit(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'Free Workspace',
            'owner_id' => $user->id,
        ]);

        $service = app(TariffLimitService::class);

        $this->assertSame(7, $service->getMonthlyLimit($workspace));
        $this->assertTrue($service->canCreateAppointment($workspace));
    }

    public function test_limit_blocks_after_db_value(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'Test Limit',
            'owner_id' => $user->id,
        ]);
        $user->update(['workspace_id' => $workspace->id]);

        $service = app(TariffLimitService::class);

        $client = Client::factory()->for($user)->create();

        for ($i = 0; $i < 7; $i++) {
            Appointment::factory()
                ->forMaster($user)
                ->forClient($client)
                ->booked()
                ->create([
                    'start_time' => now()->startOfMonth()->addDays($i)->setTime(10, 0),
                ]);
        }

        $this->assertFalse($service->canCreateAppointment($workspace));
        $this->assertSame(0, $service->getRemainingCount($workspace));
    }

    public function test_tariff_limits_total_returns_db_value(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'Test Total',
            'owner_id' => $user->id,
        ]);

        $service = app(TariffLimitService::class);

        $this->assertSame(7, $service->getMonthlyLimit($workspace));
    }

    public function test_superadmin_can_update_start_limit(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $response = $this->actingAs($admin)->put(route('super_admin.update_plan', $this->startPlan), [
            'max_appointments_per_month' => 50,
        ]);

        $response->assertSessionHas('success');

        $this->startPlan->refresh();
        $this->assertSame(50, $this->startPlan->max_appointments_per_month);

        $workspace = Workspace::create([
            'name' => 'After Update',
            'owner_id' => User::factory()->create()->id,
        ]);

        $service = app(TariffLimitService::class);
        $this->assertSame(50, $service->getMonthlyLimit($workspace));
    }

    public function test_regular_user_cannot_update_plan(): void
    {
        $user = User::factory()->create(['is_super_admin' => false]);

        $response = $this->actingAs($user)->put(route('super_admin.update_plan', $this->startPlan), [
            'max_appointments_per_month' => 99,
        ]);

        $response->assertStatus(403);
    }

    public function test_start_update_rejects_invalid_values(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $response = $this->actingAs($admin)->put(route('super_admin.update_plan', $this->startPlan), [
            'max_appointments_per_month' => 0,
        ]);

        $response->assertSessionHasErrors('max_appointments_per_month');
    }

    public function test_start_update_rejects_negative_values(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $response = $this->actingAs($admin)->put(route('super_admin.update_plan', $this->startPlan), [
            'max_appointments_per_month' => -5,
        ]);

        $response->assertSessionHasErrors('max_appointments_per_month');
    }

    public function test_start_update_rejects_null(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $response = $this->actingAs($admin)->put(route('super_admin.update_plan', $this->startPlan), [
            'max_appointments_per_month' => null,
        ]);

        $response->assertSessionHasErrors('max_appointments_per_month');
    }

    // ── Start cannot change price ──

    public function test_start_rejects_price_monthly(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)->put(route('super_admin.update_plan', $this->startPlan), [
            'max_appointments_per_month' => 10,
            'price_monthly' => 999,
        ]);

        $this->startPlan->refresh();
        $this->assertSame(10, $this->startPlan->max_appointments_per_month);
        $this->assertSame(0, $this->startPlan->price_monthly);
    }

    public function test_update_cannot_change_other_fields(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)->put(route('super_admin.update_plan', $this->startPlan), [
            'max_appointments_per_month' => 15,
            'code' => 'hacked',
            'price_monthly' => 999,
        ]);

        $this->startPlan->refresh();
        $this->assertSame(15, $this->startPlan->max_appointments_per_month);
        $this->assertSame('start', $this->startPlan->code);
        $this->assertSame(0, $this->startPlan->price_monthly);
    }

    // ── Pro price ──

    public function test_superadmin_can_update_pro_price(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $response = $this->actingAs($admin)->put(route('super_admin.update_plan', $this->proPlan), [
            'price_monthly' => 690,
        ]);

        $response->assertSessionHas('success');

        $this->proPlan->refresh();
        $this->assertSame(690, $this->proPlan->price_monthly);
    }

    public function test_pro_price_does_not_change_existing_subscription_amount(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'WS', 'owner_id' => $user->id]);

        $sub = Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
        ]);

        $this->actingAs($admin)->put(route('super_admin.update_plan', $this->proPlan), [
            'price_monthly' => 790,
        ]);

        $this->proPlan->refresh();
        $this->assertSame(790, $this->proPlan->price_monthly);

        $sub->refresh();
        $this->assertSame(490, $sub->amount_paid);
    }

    public function test_pro_rejects_negative_price(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $response = $this->actingAs($admin)->put(route('super_admin.update_plan', $this->proPlan), [
            'price_monthly' => -100,
        ]);

        $response->assertSessionHasErrors('price_monthly');
    }

    public function test_pro_cannot_change_limit(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)->put(route('super_admin.update_plan', $this->proPlan), [
            'price_monthly' => 590,
            'max_appointments_per_month' => 100,
        ]);

        $this->proPlan->refresh();
        $this->assertSame(590, $this->proPlan->price_monthly);
        $this->assertNull($this->proPlan->max_appointments_per_month);
    }

    public function test_unknown_plan_rejected(): void
    {
        $otherPlan = TariffPlan::create([
            'code' => 'unknown',
            'name' => 'Unknown',
            'price_monthly' => 100,
            'max_appointments_per_month' => 10,
            'max_masters' => 1,
            'features' => [],
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['is_super_admin' => true]);

        $response = $this->actingAs($admin)->put(route('super_admin.update_plan', $otherPlan), [
            'price_monthly' => 200,
        ]);

        $response->assertStatus(422);
    }
}
