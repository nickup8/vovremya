<?php

namespace Tests\Feature\Settings;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlugGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_solo_master_can_update_slug(): void
    {
        $user = User::factory()->master()->create([
            'workspace_id' => null,
            'master_slug' => 'old-slug',
        ]);

        $this->actingAs($user);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);

        $this->put('/admin/settings', [
            'name' => $user->name,
            'phone' => $user->phone,
            'master_slug' => 'new-slug',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'master_slug' => 'new-slug',
        ]);
    }

    public function test_employed_master_cannot_update_slug(): void
    {
        $workspace = Workspace::create(['name' => 'Studio', 'owner_id' => User::factory()->create()->id]);
        $user = User::factory()->master()->create([
            'workspace_id' => $workspace->id,
            'master_slug' => 'original-slug',
        ]);

        $plan = TariffPlan::create([
            'code' => 'pro', 'name' => 'Профи', 'price_monthly' => 490,
            'max_appointments_per_month' => null, 'max_masters' => 1,
            'features' => [], 'is_active' => true,
        ]);

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'period_months' => 1, 'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);

        $this->actingAs($user);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);

        $this->put('/admin/settings', [
            'name' => 'Updated Name',
            'phone' => $user->phone,
            'master_slug' => 'hacked-slug',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'master_slug' => 'original-slug',
            'name' => 'Updated Name',
        ]);
    }

    public function test_employed_master_slug_editable_again_when_subscription_expired(): void
    {
        $workspace = Workspace::create(['name' => 'Studio', 'owner_id' => User::factory()->create()->id]);
        $user = User::factory()->master()->create([
            'workspace_id' => $workspace->id,
            'master_slug' => 'old-slug',
        ]);

        $plan = TariffPlan::create([
            'code' => 'pro', 'name' => 'Профи', 'price_monthly' => 490,
            'max_appointments_per_month' => null, 'max_masters' => 1,
            'features' => [], 'is_active' => true,
        ]);

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'period_months' => 1, 'amount_paid' => 490,
            'status' => SubscriptionStatus::Expired->value,
            'starts_at' => now()->subMonths(2),
            'expires_at' => now()->subMonth(),
        ]);

        $this->actingAs($user);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);

        $this->put('/admin/settings', [
            'name' => $user->name,
            'phone' => $user->phone,
            'master_slug' => 'revived-slug',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'master_slug' => 'revived-slug',
        ]);
    }
}
