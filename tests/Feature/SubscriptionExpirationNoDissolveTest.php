<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Appointment;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionExpirationNoDissolveTest extends TestCase
{
    use RefreshDatabase;

    private function createSoloWorkspace(): array
    {
        $master = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'solo-test-'.$master->id,
            'owner_id' => $master->id,
        ]);
        $workspace->ensureSlug();
        $master->update(['workspace_id' => $workspace->id]);

        return [$master, $workspace];
    }

    private function createTariffPlan(): TariffPlan
    {
        return TariffPlan::create([
            'code' => 'start',
            'name' => 'Старт',
            'price_monthly' => 500,
            'max_appointments_per_month' => null,
            'max_masters' => 1,
            'features' => [],
            'is_active' => true,
        ]);
    }

    private function createExpiredSubscription(Workspace $workspace): Subscription
    {
        return Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->createTariffPlan()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDays(30),
            'expires_at' => now()->subDay(),
            'period_months' => 1,
            'amount_paid' => 500,
        ]);
    }

    public function test_expiration_does_not_change_workspace(): void
    {
        [$master, $workspace] = $this->createSoloWorkspace();
        $originalWorkspaceId = $master->fresh()->workspace_id;

        $this->createExpiredSubscription($workspace);

        $this->artisan('subscriptions:check-expirations')->assertExitCode(0);

        $this->assertSame($originalWorkspaceId, $master->fresh()->workspace_id);
    }

    public function test_expiration_does_not_create_new_workspace(): void
    {
        [$master, $workspace] = $this->createSoloWorkspace();
        $workspaceCountBefore = Workspace::count();

        $this->createExpiredSubscription($workspace);

        $this->artisan('subscriptions:check-expirations')->assertExitCode(0);

        $this->assertSame($workspaceCountBefore, Workspace::count());
    }

    public function test_services_stay_linked_to_same_workspace(): void
    {
        [$master, $workspace] = $this->createSoloWorkspace();

        $catalog = ServiceCatalog::create([
            'workspace_id' => $workspace->id,
            'title' => 'Стрижка',
            'base_price' => 1500,
            'base_duration' => 60,
        ]);
        $ms = MasterService::create([
            'master_id' => $master->id,
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);

        $this->createExpiredSubscription($workspace);

        $this->artisan('subscriptions:check-expirations')->assertExitCode(0);

        $this->assertSame($workspace->id, $catalog->fresh()->workspace_id);
        $this->assertSame($master->id, $ms->fresh()->master_id);
        $this->assertSame($catalog->id, $ms->fresh()->catalog_id);
        $this->assertSame(
            $master->fresh()->workspace_id,
            $catalog->fresh()->workspace_id,
            'master.workspace_id must equal catalog.workspace_id',
        );
    }

    public function test_appointments_unchanged_after_expiration(): void
    {
        [$master, $workspace] = $this->createSoloWorkspace();

        $appointment = Appointment::factory()->forMaster($master)->create([
            'status' => 'booked',
        ]);
        $appointmentId = $appointment->id;

        $this->createExpiredSubscription($workspace);

        $this->artisan('subscriptions:check-expirations')->assertExitCode(0);

        $fresh = Appointment::find($appointmentId);
        $this->assertNotNull($fresh);
        $this->assertEquals(AppointmentStatus::Booked, $fresh->status);
        $this->assertSame($master->id, $fresh->master_id);
    }

    public function test_subscription_becomes_expired(): void
    {
        [$master, $workspace] = $this->createSoloWorkspace();
        $sub = $this->createExpiredSubscription($workspace);

        $this->assertSame('active', $sub->fresh()->status);

        $this->artisan('subscriptions:check-expirations')->assertExitCode(0);

        $this->assertSame('expired', $sub->fresh()->status);
    }

    public function test_active_subscription_not_expired(): void
    {
        [$master, $workspace] = $this->createSoloWorkspace();

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->createTariffPlan()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now(),
            'expires_at' => now()->addDays(10),
            'period_months' => 1,
            'amount_paid' => 500,
        ]);

        $this->artisan('subscriptions:check-expirations')->assertExitCode(0);

        $this->assertSame('active', Subscription::first()->fresh()->status);
    }
}
