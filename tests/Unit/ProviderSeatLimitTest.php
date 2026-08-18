<?php

namespace Tests\Unit;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use App\Services\Payment\MockPaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProviderSeatLimitTest extends TestCase
{
    use RefreshDatabase;

    // ═══════════════════════════════════════════
    // 1. downgradeBlockReason
    // ═══════════════════════════════════════════

    public function test_downgrade_block_reason_blocks_when_providers_exceed_new_limit(): void
    {
        $billingService = new BillingService(new MockPaymentGateway);

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

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $currentPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addMonth(),
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
        $billingService = new BillingService(new MockPaymentGateway);

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

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $currentPlan->id,
            'period_months' => 1,
            'amount_paid' => 290,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addMonth(),
        ]);

        $reason = $billingService->downgradeBlockReason($owner, $newPlan);

        $this->assertNull($reason);
    }

    public function test_downgrade_block_reason_null_when_no_active_subscription(): void
    {
        $billingService = new BillingService(new MockPaymentGateway);

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
        $billingService = new BillingService(new MockPaymentGateway);

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

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $currentPlan->id,
            'period_months' => 1,
            'amount_paid' => 290,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addMonth(),
        ]);

        $reason = $billingService->downgradeBlockReason($owner, $newPlan);

        $this->assertNull($reason);
    }

    // ═══════════════════════════════════════════
    // 2. subscribe blocks downgrade via ValidationException
    // ═══════════════════════════════════════════

    public function test_subscribe_throws_validation_exception_on_downgrade(): void
    {
        $billingService = new BillingService(new MockPaymentGateway);

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

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $currentPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addMonth(),
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
        $billingService = new BillingService(new MockPaymentGateway);

        $plan = TariffPlan::create([
            'code' => 'start',
            'name' => 'Старт',
            'price_monthly' => 290,
            'max_masters' => 1,
            'features' => [],
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
