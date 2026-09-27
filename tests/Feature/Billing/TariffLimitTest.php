<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\SubscriptionStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\Client;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkingHour;
use App\Services\Billing\TariffLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TariffLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $master;
    private \App\Models\ServiceCatalog $service;
    private \App\Models\MasterService $masterService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->master = User::factory()->master()->create([
            'is_service_provider' => true,
        ]);

        $workspace = Workspace::create([
            'name' => 'Test Studio',
            'owner_id' => $this->master->id,
        ]);
        $workspace->ensureSlug();
        $this->master->update(['workspace_id' => $workspace->id]);

        for ($day = 0; $day < 7; $day++) {
            WorkingHour::updateOrCreate(
                ['user_id' => $this->master->id, 'day_of_week' => $day],
                ['is_working' => true, 'start_time' => '08:00', 'end_time' => '20:00']
            );
        }

        $this->service = \App\Models\ServiceCatalog::create([
            'workspace_id' => $workspace->id,
            'title' => 'Test Service',
            'base_duration' => 60,
            'base_price' => 1000,
            'is_active' => true,
        ]);

        $this->masterService = \App\Models\MasterService::create([
            'master_id' => $this->master->id,
            'catalog_id' => $this->service->id,
        ]);
    }

    public function test_start_tariff_allows_unlimited_appointments(): void
    {
        // No Core entitlement = start plan with unlimited (null) limit
        $client = Client::factory()->for($this->master)->create();

        for ($i = 0; $i < 35; $i++) {
            \App\Models\Appointment::factory()
                ->forMaster($this->master)
                ->forClient($client)
                ->withMasterService($this->masterService)
                ->booked()
                ->create([
                    'start_time' => now()->startOfMonth()->addDays($i)->setTime(10, 0),
                ]);
        }

        $this->assertDatabaseCount('appointments', 35);

        // Start plan should now allow unlimited appointments
        $canCreate = app(TariffLimitService::class)->canCreateAppointment($this->master->workspace);
        $this->assertTrue($canCreate, 'Start plan should allow unlimited appointments');
    }

    public function test_pro_tariff_allows_unlimited_appointments(): void
    {
        // Create pro plan with Core entitlement
        $proPlan = TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'max_appointments_per_month' => null,
            'is_active' => true,
        ]);

        $billingSub = BillingSubscription::create([
            'workspace_id' => $this->master->workspace_id,
            'tariff_plan_id' => $proPlan->id,
            'status' => \App\Enums\BillingSubscriptionStatus::Active,
        ]);

        BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $this->master->workspace_id,
            'tariff_plan_id' => $proPlan->id,
            'period_start' => now()->subMonth(),
            'period_end' => now()->addMonth(),
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'origin' => BillingCycleOrigin::AdminGrant,
        ]);

        $client = Client::factory()->for($this->master)->create();

        for ($i = 0; $i < 35; $i++) {
            \App\Models\Appointment::factory()
                ->forMaster($this->master)
                ->forClient($client)
                ->withMasterService($this->masterService)
                ->booked()
                ->create([
                    'start_time' => now()->startOfMonth()->addDays($i)->setTime(10, 0),
                ]);
        }

        $this->assertDatabaseCount('appointments', 35);
    }

    public function test_free_tariff_counts_only_booked_and_paid(): void
    {
        $client = Client::factory()->for($this->master)->create();

        for ($i = 0; $i < 25; $i++) {
            \App\Models\Appointment::factory()
                ->forMaster($this->master)
                ->forClient($client)
                ->withMasterService($this->masterService)
                ->booked()
                ->create([
                    'start_time' => now()->startOfMonth()->addDays($i)->setTime(10, 0),
                ]);
        }

        for ($i = 0; $i < 10; $i++) {
            \App\Models\Appointment::factory()
                ->forMaster($this->master)
                ->forClient($client)
                ->withMasterService($this->masterService)
                ->cancelled()
                ->create([
                    'start_time' => now()->startOfMonth()->addDays($i)->setTime(14, 0),
                ]);
        }

        $this->assertDatabaseCount('appointments', 35);

        $this->assertTrue(
            app(TariffLimitService::class)->canCreateAppointment($this->master->workspace)
        );
    }
}
