<?php

namespace Tests\Unit;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserIsSoloTest extends TestCase
{
    use RefreshDatabase;

    public function test_solo_user_without_workspace_is_solo(): void
    {
        $user = User::factory()->create(['workspace_id' => null]);

        $this->assertTrue($user->isSolo());
    }

    public function test_employed_master_with_active_subscription_is_not_solo(): void
    {
        $workspace = Workspace::create(['name' => 'Studio', 'owner_id' => User::factory()->create()->id]);
        $user = User::factory()->create(['workspace_id' => $workspace->id]);

        $plan = TariffPlan::create([
            'code' => 'pro', 'name' => 'Профи', 'price_monthly' => 490,
            'max_appointments_per_month' => null, 'max_masters' => 1,
            'features' => [], 'is_active' => true,
        ]);

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);

        $this->assertFalse($user->isSolo());
    }

    public function test_employed_master_becomes_solo_when_subscription_expired(): void
    {
        $workspace = Workspace::create(['name' => 'Studio', 'owner_id' => User::factory()->create()->id]);
        $user = User::factory()->create(['workspace_id' => $workspace->id]);

        $plan = TariffPlan::create([
            'code' => 'pro', 'name' => 'Профи', 'price_monthly' => 490,
            'max_appointments_per_month' => null, 'max_masters' => 1,
            'features' => [], 'is_active' => true,
        ]);

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Expired->value,
            'starts_at' => now()->subMonths(2),
            'expires_at' => now()->subMonth(),
        ]);

        $this->assertTrue($user->isSolo());
    }

    public function test_studio_owner_with_active_subscription_is_not_solo(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'My Studio', 'owner_id' => $owner->id]);
        $owner->update(['workspace_id' => $workspace->id]);

        $plan = TariffPlan::create([
            'code' => 'studio', 'name' => 'Студия', 'price_monthly' => 1290,
            'max_appointments_per_month' => null, 'max_masters' => 5,
            'features' => [], 'is_active' => true,
        ]);

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'period_months' => 1,
            'amount_paid' => 1290,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);

        $this->assertFalse($owner->isSolo());
    }
}
