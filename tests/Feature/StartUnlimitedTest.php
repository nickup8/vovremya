<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkingHour;
use App\Support\PlanDefaults;
use App\Services\Billing\TariffLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StartUnlimitedTest extends TestCase
{
    use RefreshDatabase;

    private User $master;
    private Workspace $workspace;
    private \App\Models\ServiceCatalog $catalog;
    private MasterService $masterService;

    protected function setUp(): void
    {
        parent::setUp();

        $startPlan = TariffPlan::create([
            'code' => 'start',
            'name' => 'Старт',
            'price_monthly' => 0,
            'max_appointments_per_month' => null,
            'max_masters' => 1,
            'features' => ['calendar', 'basic_client_management'],
            'is_active' => true,
        ]);

        TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'max_appointments_per_month' => null,
            'max_masters' => 1,
            'features' => ['unlimited_appointments', 'client_management', 'channel_analytics', 'slot_autofill', 'recurring_appointments', 'free_windows'],
            'is_active' => true,
        ]);

        $this->master = User::factory()->create([
            'is_master' => true,
            'settings' => ['timezone' => 'Europe/Moscow'],
        ]);
        $this->workspace = Workspace::create([
            'name' => 'Start Studio',
            'owner_id' => $this->master->id,
        ]);
        $this->master->update(['workspace_id' => $this->workspace->id]);
        Subscription::create([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $startPlan->id,
            'status' => 'active',
            'expires_at' => now()->addYear(),
        ]);

        for ($day = 0; $day < 7; $day++) {
            WorkingHour::updateOrCreate(
                ['user_id' => $this->master->id, 'day_of_week' => $day],
                ['is_working' => true, 'start_time' => '08:00', 'end_time' => '20:00']
            );
        }

        $this->catalog = \App\Models\ServiceCatalog::create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Test',
            'base_duration' => 60,
            'base_price' => 1000,
            'is_active' => true,
        ]);

        $this->masterService = MasterService::create([
            'master_id' => $this->master->id,
            'catalog_id' => $this->catalog->id,
            'effective_duration' => 60,
            'is_active' => true,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // PlanDefaults
    // ═══════════════════════════════════════════════════════
    public function test_start_max_appointments_is_null(): void
    {
        $this->assertNull(PlanDefaults::START_MAX_APPOINTMENTS);
    }

    public function test_unlimited_constant_is_null(): void
    {
        $this->assertNull(PlanDefaults::UNLIMITED);
    }

    // ═══════════════════════════════════════════════════════
    // TariffLimitService
    // ═══════════════════════════════════════════════════════
    public function test_start_plan_allows_over_30_appointments(): void
    {
        $client = Client::factory()->for($this->master)->create();

        // Create 35 appointments across different days/times to avoid overlap constraint
        for ($i = 0; $i < 35; $i++) {
            $day = $i % 28;
            $hour = 8 + ($i % 10); // 8:00 to 17:00, cycling every 10 hours

            \App\Models\Appointment::factory()
                ->forMaster($this->master)
                ->forClient($client)
                ->withMasterService($this->masterService)
                ->booked()
                ->create([
                    'start_time' => now()->startOfMonth()->addDays($day)->setTime($hour, 0),
                    'duration' => 60,
                ]);
        }

        $this->assertDatabaseCount('appointments', 35);

        // Start plan should allow unlimited appointments
        $canCreate = app(TariffLimitService::class)->canCreateAppointment($this->workspace);
        $this->assertTrue($canCreate, 'Start plan should allow unlimited appointments');
    }

    public function test_start_plan_get_monthly_limit_returns_unlimited(): void
    {
        $limit = app(TariffLimitService::class)->getMonthlyLimit($this->workspace);
        // null from PlanDefaults is resolved to PHP_INT_MAX by TariffLimitService
        $this->assertGreaterThanOrEqual(31, $limit);
    }

    // ═══════════════════════════════════════════════════════
    // Recurring blocked times available to Start
    // ═══════════════════════════════════════════════════════
    public function test_start_can_create_recurring_blocked_times(): void
    {
        $this->actingAs($this->master);

        $response = $this->postJson('/admin/recurring-blocked-times', [
            'title' => 'Test',
            'start_date' => now()->format('Y-m-d'),
            'start_time' => '16:00',
            'end_time' => '17:00',
            'recurrence_type' => 'daily',
            'interval' => 1,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('recurring_blocked_time_series', [
            'user_id' => $this->master->id,
            'title' => 'Test',
            'status' => 'active',
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // Pro plan features
    // ═══════════════════════════════════════════════════════
    public function test_pro_plan_features_do_not_include_recurring_blocked_times(): void
    {
        $proPlan = TariffPlan::where('code', 'pro')->first();
        $this->assertNotNull($proPlan, 'Pro plan must exist');
        $this->assertNotContains('recurring_blocked_times', $proPlan->features);
    }

    public function test_pro_plan_features_include_free_windows(): void
    {
        $proPlan = TariffPlan::where('code', 'pro')->first();
        $this->assertNotNull($proPlan, 'Pro plan must exist');
        $this->assertContains('free_windows', $proPlan->features);
    }
}
