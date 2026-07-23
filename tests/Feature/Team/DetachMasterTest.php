<?php

namespace Tests\Feature\Team;

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DetachMasterTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;
    private User $owner;
    private User $master;
    private User $target;
    private TariffPlan $plan;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create([
            'workspace_id' => null,
            'role' => UserRole::Owner,
        ]);

        $this->workspace = Workspace::create([
            'name' => 'Test Studio',
            'owner_id' => $this->owner->id,
        ]);

        $this->owner->update(['workspace_id' => $this->workspace->id]);

        $this->plan = TariffPlan::create([
            'code' => 'studio', 'name' => 'Студия', 'price_monthly' => 1290,
            'max_appointments_per_month' => null, 'max_masters' => 5,
            'features' => [], 'is_active' => true,
        ]);

        Subscription::create([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->plan->id,
            'period_months' => 1, 'amount_paid' => 1290,
            'status' => 'active',
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addMonth(),
        ]);

        $this->master = User::factory()->master()->create([
            'workspace_id' => $this->workspace->id,
            'role' => UserRole::Master,
        ]);

        $this->target = User::factory()->master()->create([
            'workspace_id' => $this->workspace->id,
            'role' => UserRole::Master,
        ]);

        $this->service = Service::create([
            'user_id' => $this->master->id,
            'title' => 'Тестовая услуга',
            'price' => 1000,
            'duration_minutes' => 60,
        ]);
    }

    private function detach(User $actor, User $removed, string $targetId): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($actor)
            ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class)
            ->postJson(route('admin.team.detach', $removed->id), [
                'target_master_id' => $targetId,
            ]);
    }

    public function test_owner_can_detach_master_and_appointments_transfer(): void
    {
        $futureAppointment = Appointment::create([
            'master_id' => $this->master->id,
            'client_id' => null,
            'service_id' => $this->service->id,
            'start_time' => Carbon::tomorrow(),
            'status' => AppointmentStatus::Booked,
        ]);

        $this->detach($this->owner, $this->master, $this->target->id)
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $this->master->id,
            'workspace_id' => null,
            'role' => UserRole::Owner,
        ]);

        $this->assertDatabaseHas('appointments', [
            'id' => $futureAppointment->id,
            'master_id' => $this->target->id,
        ]);
    }

    public function test_admin_can_detach_master(): void
    {
        $admin = User::factory()->create([
            'workspace_id' => $this->workspace->id,
            'role' => UserRole::Admin,
        ]);

        Appointment::create([
            'master_id' => $this->master->id,
            'client_id' => null,
            'service_id' => $this->service->id,
            'start_time' => Carbon::tomorrow(),
            'status' => AppointmentStatus::Booked,
        ]);

        $this->detach($admin, $this->master, $this->target->id)
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $this->master->id,
            'workspace_id' => null,
            'role' => UserRole::Owner,
        ]);
    }

    public function test_past_appointments_are_not_transferred(): void
    {
        $pastAppointment = Appointment::create([
            'master_id' => $this->master->id,
            'client_id' => null,
            'service_id' => $this->service->id,
            'start_time' => Carbon::yesterday(),
            'status' => AppointmentStatus::Booked,
        ]);

        $this->detach($this->owner, $this->master, $this->target->id)
            ->assertRedirect();

        $this->assertDatabaseHas('appointments', [
            'id' => $pastAppointment->id,
            'master_id' => $this->master->id,
        ]);
    }

    public function test_non_transferable_statuses_stay_on_removed(): void
    {
        foreach (['paid', 'no_show', 'cancelled'] as $status) {
            // Create fresh users for each iteration to avoid stale state
            $loopMaster = User::factory()->master()->create([
                'workspace_id' => $this->workspace->id,
                'role' => UserRole::Master,
            ]);

            $loopTarget = User::factory()->master()->create([
                'workspace_id' => $this->workspace->id,
                'role' => UserRole::Master,
            ]);

            $appointment = Appointment::create([
                'master_id' => $loopMaster->id,
                'client_id' => null,
                'service_id' => $this->service->id,
                'start_time' => Carbon::tomorrow(),
                'status' => $status,
            ]);

            $this->detach($this->owner, $loopMaster, $loopTarget->id)
                ->assertRedirect();

            $this->assertDatabaseHas('appointments', [
                'id' => $appointment->id,
                'master_id' => $loopMaster->id,
            ]);
        }
    }

    public function test_master_role_returns_403(): void
    {
        $this->detach($this->master, $this->target, $this->owner->id)
            ->assertStatus(403);
    }

    public function test_cannot_detach_workspace_owner(): void
    {
        $this->detach($this->owner, $this->owner, $this->master->id)
            ->assertStatus(422);
    }

    public function test_cannot_detach_self(): void
    {
        $this->detach($this->owner, $this->owner, $this->master->id)
            ->assertStatus(422);
    }

    public function test_cannot_detach_from_other_workspace(): void
    {
        $otherWorkspace = Workspace::create([
            'name' => 'Other Studio',
            'owner_id' => User::factory()->create()->id,
        ]);

        $otherMaster = User::factory()->master()->create([
            'workspace_id' => $otherWorkspace->id,
            'role' => UserRole::Master,
        ]);

        $this->detach($this->owner, $otherMaster, $this->master->id)
            ->assertStatus(403);
    }

    public function test_target_from_other_workspace_returns_422(): void
    {
        $otherWorkspace = Workspace::create([
            'name' => 'Other Studio',
            'owner_id' => User::factory()->create()->id,
        ]);

        $otherMaster = User::factory()->master()->create([
            'workspace_id' => $otherWorkspace->id,
            'role' => UserRole::Master,
        ]);

        $this->detach($this->owner, $this->master, $otherMaster->id)
            ->assertStatus(422);
    }

    public function test_target_same_as_removed_returns_422(): void
    {
        $this->detach($this->owner, $this->master, $this->master->id)
            ->assertStatus(422);
    }

    public function test_target_not_master_returns_422(): void
    {
        $client = User::factory()->create([
            'workspace_id' => $this->workspace->id,
            'is_master' => false,
        ]);

        $this->detach($this->owner, $this->master, $client->id)
            ->assertStatus(422);
    }

    public function test_subscription_unchanged_after_detach(): void
    {
        $this->detach($this->owner, $this->master, $this->target->id);

        $this->assertDatabaseHas('subscriptions', [
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->plan->id,
            'status' => 'active',
        ]);
    }
}
