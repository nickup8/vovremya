<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Client;
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
            'max_appointments_per_month' => 11,
        ]);

        $response->assertSessionHas('success');

        $this->startPlan->refresh();
        $this->assertSame(11, $this->startPlan->max_appointments_per_month);

        $workspace = Workspace::create([
            'name' => 'After Update',
            'owner_id' => User::factory()->create()->id,
        ]);

        $service = app(TariffLimitService::class);
        $this->assertSame(11, $service->getMonthlyLimit($workspace));
    }

    public function test_regular_user_cannot_update_plan(): void
    {
        $user = User::factory()->create(['is_super_admin' => false]);

        $response = $this->actingAs($user)->put(route('super_admin.update_plan', $this->startPlan), [
            'max_appointments_per_month' => 99,
        ]);

        $response->assertStatus(403);
    }

    public function test_update_rejects_invalid_values(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $response = $this->actingAs($admin)->put(route('super_admin.update_plan', $this->startPlan), [
            'max_appointments_per_month' => 0,
        ]);

        $response->assertSessionHasErrors('max_appointments_per_month');
    }

    public function test_update_rejects_negative_values(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $response = $this->actingAs($admin)->put(route('super_admin.update_plan', $this->startPlan), [
            'max_appointments_per_month' => -5,
        ]);

        $response->assertSessionHasErrors('max_appointments_per_month');
    }

    public function test_update_allows_null_for_unlimited(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $response = $this->actingAs($admin)->put(route('super_admin.update_plan', $this->startPlan), [
            'max_appointments_per_month' => null,
        ]);

        $response->assertSessionHas('success');

        $this->startPlan->refresh();
        $this->assertNull($this->startPlan->max_appointments_per_month);

        $workspace = Workspace::create([
            'name' => 'Unlimited',
            'owner_id' => User::factory()->create()->id,
        ]);

        $service = app(TariffLimitService::class);
        $this->assertSame(PHP_INT_MAX, $service->getMonthlyLimit($workspace));
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
}
