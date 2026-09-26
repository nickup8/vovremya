<?php

namespace Tests\Unit;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\SubscriptionStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProviderSeatLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.core_entitlement' => true]);
    }

    // ═══════════════════════════════════════════
    // 1. downgradeBlockReason
    // ═══════════════════════════════════════════

    public function test_downgrade_block_reason_blocks_when_providers_exceed_new_limit(): void
    {
        $billingService = app(BillingService::class);

        $currentPlan = TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'max_masters' => 5,
            'features' => [],
            'is_active' => true,
        ]);

        $newPlan = TariffPlan::create([
            'code' => 'start',
            'name' => 'Старт',
            'price_monthly' => 290,
            'max_masters' => 3,
            'features' => [],
            'is_active' => true,
        ]);

        $owner = User::factory()->master()->create(['is_service_provider' => true]);
        \Illuminate\Support\Facades\DB::table('users')->where('id', $owner->id)->update(['role' => 'owner']);
        $workspace = Workspace::create([
            'name' => 'Studio',
            'owner_id' => $owner->id,
        ]);
        $owner->update(['workspace_id' => $workspace->id]);

        // Create Core entitlement (granting cycle) for current plan
        $billingSub = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $currentPlan->id,
            'status' => \App\Enums\BillingSubscriptionStatus::Active,
        ]);

        BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $currentPlan->id,
            'period_start' => now()->subMonth(),
            'period_end' => now()->addMonth(),
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'origin' => BillingCycleOrigin::AdminGrant,
        ]);

        // Create 3 more providers (owner + 3 = 4 total)
        User::factory()->master()->create([
            'workspace_id' => $workspace->id,
            'is_service_provider' => true,
        ]);
        User::factory()->master()->create([
            'workspace_id' => $workspace->id,
            'is_service_provider' => true,
        ]);
        User::factory()->master()->create([
            'workspace_id' => $workspace->id,
            'is_service_provider' => true,
        ]);

        $this->assertEquals(4, $workspace->providersCount());

        $reason = $billingService->downgradeBlockReason($owner, $newPlan);

        $this->assertIsString($reason);
        $this->assertStringContainsString('4 провайдеров', $reason);
        $this->assertStringContainsString('мест — 3', $reason);
    }

    public function test_downgrade_block_reason_allows_when_new_limit_is_higher_or_equal(): void
    {
        $billingService = app(BillingService::class);

        $currentPlan = TariffPlan::create([
            'code' => 'start',
            'name' => 'Старт',
            'price_monthly' => 290,
            'max_masters' => 1,
            'features' => [],
            'is_active' => true,
        ]);

        $newPlan = TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'max_masters' => 5,
            'features' => [],
            'is_active' => true,
        ]);

        $owner = User::factory()->master()->create(['is_service_provider' => true]);
        \Illuminate\Support\Facades\DB::table('users')->where('id', $owner->id)->update(['role' => 'owner']);
        $workspace = Workspace::create([
            'name' => 'Studio',
            'owner_id' => $owner->id,
        ]);
        $owner->update(['workspace_id' => $workspace->id]);

        // Create Core entitlement
        $billingSub = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $currentPlan->id,
            'status' => \App\Enums\BillingSubscriptionStatus::Active,
        ]);

        BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $currentPlan->id,
            'period_start' => now()->subMonth(),
            'period_end' => now()->addMonth(),
            'status' => BillingCycleStatus::Paid,
            'amount' => 290,
            'origin' => BillingCycleOrigin::AdminGrant,
        ]);

        $reason = $billingService->downgradeBlockReason($owner, $newPlan);

        $this->assertNull($reason);
    }

    public function test_downgrade_block_reason_null_when_no_active_subscription(): void
    {
        $billingService = app(BillingService::class);

        $owner = User::factory()->master()->create(['workspace_id' => null]);

        $newPlan = TariffPlan::create([
            'code' => 'start',
            'name' => 'Старт',
            'price_monthly' => 290,
            'max_masters' => 1,
            'features' => [],
            'is_active' => true,
        ]);

        $reason = $billingService->downgradeBlockReason($owner, $newPlan);

        $this->assertNull($reason);
    }

    public function test_downgrade_block_reason_allows_new_unlimited_plan(): void
    {
        $billingService = app(BillingService::class);

        $currentPlan = TariffPlan::create([
            'code' => 'start',
            'name' => 'Старт',
            'price_monthly' => 290,
            'max_masters' => 1,
            'features' => [],
            'is_active' => true,
        ]);

        $newPlan = TariffPlan::create([
            'code' => 'unlimited',
            'name' => 'Безлимит',
            'price_monthly' => 990,
            'max_masters' => null,
            'features' => [],
            'is_active' => true,
        ]);

        $owner = User::factory()->master()->create(['is_service_provider' => true]);
        \Illuminate\Support\Facades\DB::table('users')->where('id', $owner->id)->update(['role' => 'owner']);
        $workspace = Workspace::create([
            'name' => 'Studio',
            'owner_id' => $owner->id,
        ]);
        $owner->update(['workspace_id' => $workspace->id]);

        // Create Core entitlement
        $billingSub = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $currentPlan->id,
            'status' => \App\Enums\BillingSubscriptionStatus::Active,
        ]);

        BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $currentPlan->id,
            'period_start' => now()->subMonth(),
            'period_end' => now()->addMonth(),
            'status' => BillingCycleStatus::Paid,
            'amount' => 290,
            'origin' => BillingCycleOrigin::AdminGrant,
        ]);

        $reason = $billingService->downgradeBlockReason($owner, $newPlan);

        $this->assertNull($reason);
    }

    // ═══════════════════════════════════════════
    // 2. subscribe blocks downgrade via ValidationException
    // ═══════════════════════════════════════════

    public function test_subscribe_throws_validation_exception_on_downgrade(): void
    {
        $billingService = app(BillingService::class);

        $currentPlan = TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'max_masters' => 5,
            'features' => [],
            'is_active' => true,
        ]);

        $newPlan = TariffPlan::create([
            'code' => 'start',
            'name' => 'Старт',
            'price_monthly' => 290,
            'max_masters' => 3,
            'features' => [],
            'is_active' => true,
        ]);

        // Create plan_prices for newPlan (needed for subscribe)
        PlanPrice::create([
            'tariff_plan_id' => $newPlan->id,
            'period_months' => 1,
            'base_amount' => 290,
            'discount_percent' => 0,
            'final_amount' => 290,
            'currency' => 'RUB',
            'version' => 1,
            'valid_from' => now(),
            'is_active' => true,
        ]);

        $owner = User::factory()->master()->create(['is_service_provider' => true]);
        \Illuminate\Support\Facades\DB::table('users')->where('id', $owner->id)->update(['role' => 'owner']);
        $workspace = Workspace::create([
            'name' => 'Studio',
            'owner_id' => $owner->id,
        ]);
        $owner->update(['workspace_id' => $workspace->id]);

        // Create Core entitlement
        $billingSub = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $currentPlan->id,
            'status' => \App\Enums\BillingSubscriptionStatus::Active,
        ]);

        BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $currentPlan->id,
            'period_start' => now()->subMonth(),
            'period_end' => now()->addMonth(),
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'origin' => BillingCycleOrigin::AdminGrant,
        ]);

        // 4 providers (owner + 3)
        User::factory()->master()->create([
            'workspace_id' => $workspace->id,
            'is_service_provider' => true,
        ]);
        User::factory()->master()->create([
            'workspace_id' => $workspace->id,
            'is_service_provider' => true,
        ]);
        User::factory()->master()->create([
            'workspace_id' => $workspace->id,
            'is_service_provider' => true,
        ]);

        $this->assertEquals(4, $workspace->providersCount());

        $this->expectException(ValidationException::class);

        try {
            $billingService->subscribe($owner, $newPlan, 1);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('plan', $e->errors());
            throw $e;
        }
    }

    // ═══════════════════════════════════════════
    // 3. Solo first subscription not blocked
    // ═══════════════════════════════════════════

    public function test_subscribe_solo_first_subscription_not_blocked(): void
    {
        $billingService = app(BillingService::class);

        $plan = TariffPlan::create([
            'code' => 'start',
            'name' => 'Старт',
            'price_monthly' => 290,
            'max_masters' => 1,
            'features' => [],
            'is_active' => true,
        ]);

        PlanPrice::create([
            'tariff_plan_id' => $plan->id,
            'period_months' => 1,
            'base_amount' => 290,
            'discount_percent' => 0,
            'final_amount' => 290,
            'currency' => 'RUB',
            'version' => 1,
            'valid_from' => now(),
            'is_active' => true,
        ]);

        $master = User::factory()->master()->create(['workspace_id' => null]);

        $this->assertNull($master->workspace_id);

        $result = $billingService->subscribe($master, $plan, 1);

        $master->refresh();

        $this->assertNotNull($master->workspace_id, 'Workspace should be created for solo user');
        $this->assertNotNull($result['subscription']);
        $this->assertEquals($master->workspace_id, $result['subscription']->workspace_id);
    }
}
