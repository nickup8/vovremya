<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ClientReactivationEntitlementTest extends TestCase
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
            'max_appointments_per_month' => 30,
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
            'features' => ['unlimited_appointments', 'client_management', 'channel_analytics', 'client_reactivation'],
            'is_active' => true,
        ]);
    }

    private function createWorkspaceWithSubscription(TariffPlan $plan): Workspace
    {
        $owner = User::factory()->master()->create();
        $workspace = Workspace::create(['name' => 'Test WS', 'owner_id' => $owner->id]);
        $owner->update(['workspace_id' => $workspace->id]);

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'period_months' => 1,
            'amount_paid' => $plan->price_monthly,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);

        return $workspace;
    }

    public function test_no_subscription_returns_false(): void
    {
        $owner = User::factory()->master()->create();
        $workspace = Workspace::create(['name' => 'No Sub WS', 'owner_id' => $owner->id]);

        $this->assertFalse($workspace->hasFeature('client_reactivation'));
    }

    public function test_start_plan_returns_false(): void
    {
        $workspace = $this->createWorkspaceWithSubscription($this->startPlan);

        $this->assertFalse($workspace->hasFeature('client_reactivation'));
    }

    public function test_pro_plan_returns_true(): void
    {
        $workspace = $this->createWorkspaceWithSubscription($this->proPlan);

        $this->assertTrue($workspace->hasFeature('client_reactivation'));
    }

    public function test_historical_studio_plan_returns_false(): void
    {
        $studioPlan = TariffPlan::create([
            'code' => 'studio',
            'name' => 'Студия',
            'price_monthly' => 1290,
            'max_appointments_per_month' => null,
            'max_masters' => 5,
            'features' => ['unlimited_appointments', 'client_management', 'multi_master', 'priority_support'],
            'is_active' => true,
        ]);

        $workspace = $this->createWorkspaceWithSubscription($studioPlan);

        $this->assertFalse($workspace->hasFeature('client_reactivation'));
    }

    public function test_historical_salon_plan_returns_false(): void
    {
        $salonPlan = TariffPlan::create([
            'code' => 'salon',
            'name' => 'Салон',
            'price_monthly' => 2990,
            'max_appointments_per_month' => null,
            'max_masters' => null,
            'features' => ['unlimited_appointments', 'client_management', 'multi_master', 'priority_support', 'white_label'],
            'is_active' => true,
        ]);

        $workspace = $this->createWorkspaceWithSubscription($salonPlan);

        $this->assertFalse($workspace->hasFeature('client_reactivation'));
    }

    public function test_downgrade_from_pro_to_start_removes_feature(): void
    {
        $workspace = $this->createWorkspaceWithSubscription($this->proPlan);
        $this->assertTrue($workspace->hasFeature('client_reactivation'));

        $workspace->subscriptions()->where('status', 'active')->update([
            'status' => SubscriptionStatus::Expired->value,
            'expires_at' => now()->subDay(),
        ]);

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->startPlan->id,
            'period_months' => 1,
            'amount_paid' => 0,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
        ]);

        $this->assertFalse($workspace->hasFeature('client_reactivation'));
    }

    public function test_middleware_blocks_start_user(): void
    {
        Route::get('/test-reactivation', fn () => 'ok')->middleware('feature:client_reactivation');

        $owner = User::factory()->master()->create();
        $workspace = Workspace::create(['name' => 'Start WS', 'owner_id' => $owner->id]);
        $owner->update(['workspace_id' => $workspace->id]);

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->startPlan->id,
            'period_months' => 1,
            'amount_paid' => 0,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);

        $this->actingAs($owner)
            ->get('/test-reactivation')
            ->assertForbidden();
    }

    public function test_middleware_allows_pro_user(): void
    {
        Route::get('/test-reactivation', fn () => 'ok')->middleware('feature:client_reactivation');

        $owner = User::factory()->master()->create();
        $workspace = Workspace::create(['name' => 'Pro WS', 'owner_id' => $owner->id]);
        $owner->update(['workspace_id' => $workspace->id]);

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);

        $this->actingAs($owner)
            ->get('/test-reactivation')
            ->assertOk();
    }

    public function test_migration_up_preserves_existing_features(): void
    {
        // Remove client_reactivation to simulate pre-migration state
        $this->proPlan->update(['features' => ['custom_feature_a', 'custom_feature_b']]);

        // Re-run migration up logic (same algorithm as the migration)
        $this->runMigrationUp();

        $this->proPlan->refresh();

        $this->assertContains('custom_feature_a', $this->proPlan->features);
        $this->assertContains('custom_feature_b', $this->proPlan->features);
        $this->assertContains('client_reactivation', $this->proPlan->features);
    }

    public function test_migration_up_is_idempotent(): void
    {
        $this->proPlan->update(['features' => ['unlimited_appointments']]);

        $this->runMigrationUp();

        $this->proPlan->refresh();
        $countBefore = count(array_filter($this->proPlan->features, fn ($f) => $f === 'client_reactivation'));
        $this->assertEquals(1, $countBefore);

        $this->runMigrationUp();

        $this->proPlan->refresh();
        $countAfter = count(array_filter($this->proPlan->features, fn ($f) => $f === 'client_reactivation'));
        $this->assertEquals(1, $countAfter);
    }

    public function test_migration_down_preserves_other_features(): void
    {
        $this->proPlan->update(['features' => ['custom_a', 'client_reactivation', 'custom_b']]);

        $this->runMigrationDown();

        $this->proPlan->refresh();

        $this->assertContains('custom_a', $this->proPlan->features);
        $this->assertContains('custom_b', $this->proPlan->features);
        $this->assertNotContains('client_reactivation', $this->proPlan->features);
    }

    /**
     * Execute the same algorithm as the data migration's up() method.
     */
    private function runMigrationUp(): void
    {
        $pro = \DB::table('tariff_plans')->where('code', 'pro')->first();
        if (! $pro) {
            return;
        }

        $features = is_string($pro->features) ? json_decode($pro->features, true) : $pro->features;
        if (! is_array($features)) {
            $features = [];
        }

        if (in_array('client_reactivation', $features, true)) {
            return;
        }

        $features[] = 'client_reactivation';

        \DB::table('tariff_plans')
            ->where('code', 'pro')
            ->update(['features' => json_encode($features)]);
    }

    /**
     * Execute the same algorithm as the data migration's down() method.
     */
    private function runMigrationDown(): void
    {
        $pro = \DB::table('tariff_plans')->where('code', 'pro')->first();
        if (! $pro) {
            return;
        }

        $features = is_string($pro->features) ? json_decode($pro->features, true) : $pro->features;
        if (! is_array($features)) {
            return;
        }

        $features = array_values(array_filter($features, fn (string $f): bool => $f !== 'client_reactivation'));

        \DB::table('tariff_plans')
            ->where('code', 'pro')
            ->update(['features' => json_encode($features)]);
    }
}
