<?php

namespace Tests\Unit;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\TariffLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TariffLimitNullableTest extends TestCase
{
    use RefreshDatabase;

    public function test_null_in_database_stays_null_after_fresh_read(): void
    {
        $plan = TariffPlan::create([
            'code' => 'unlimited',
            'name' => 'Безлимитный',
            'price_monthly' => 0,
            'max_appointments_per_month' => null,
            'max_masters' => null,
            'features' => [],
            'is_active' => true,
        ]);

        $fresh = $plan->fresh();

        $this->assertNull($fresh->max_appointments_per_month);
        $this->assertNull($fresh->max_masters);
    }

    public function test_explicit_zero_stays_int_zero(): void
    {
        $plan = TariffPlan::create([
            'code' => 'zero-test',
            'name' => 'Zero Test',
            'price_monthly' => 0,
            'max_appointments_per_month' => 0,
            'max_masters' => 0,
            'features' => [],
            'is_active' => true,
        ]);

        $fresh = $plan->fresh();

        $this->assertSame(0, $fresh->max_appointments_per_month);
        $this->assertSame(0, $fresh->max_masters);
    }

    public function test_unlimited_plan_returns_php_int_max_from_service(): void
    {
        $plan = TariffPlan::create([
            'code' => 'unlimited-svc',
            'name' => 'Безлимитный Сервис',
            'price_monthly' => 0,
            'max_appointments_per_month' => null,
            'max_masters' => null,
            'features' => [],
            'is_active' => true,
        ]);

        $workspace = Workspace::create([
            'name' => 'Test Unlimited',
            'owner_id' => User::factory()->create()->id,
        ]);

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'period_months' => 1,
            'amount_paid' => 0,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addMonth(),
        ]);

        $service = app(TariffLimitService::class);

        $this->assertSame(PHP_INT_MAX, $service->getMonthlyLimit($workspace));
        $this->assertSame(PHP_INT_MAX, $workspace->maxMasters());
    }

    public function test_start_plan_returns_thirty_from_service(): void
    {
        $plan = TariffPlan::create([
            'code' => 'start',
            'name' => 'Старт',
            'price_monthly' => 0,
            'max_appointments_per_month' => 30,
            'max_masters' => 1,
            'features' => ['calendar', 'basic_client_management'],
            'is_active' => true,
        ]);

        $workspace = Workspace::create([
            'name' => 'Test Start',
            'owner_id' => User::factory()->create()->id,
        ]);

        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'period_months' => 1,
            'amount_paid' => 0,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addMonth(),
        ]);

        $service = app(TariffLimitService::class);

        $this->assertSame(30, $service->getMonthlyLimit($workspace));
        $this->assertSame(1, $workspace->maxMasters());
    }

    public function test_fallback_without_subscription(): void
    {
        $workspace = Workspace::create([
            'name' => 'No Subscription',
            'owner_id' => User::factory()->create()->id,
        ]);

        $service = app(TariffLimitService::class);

        $this->assertSame(30, $service->getMonthlyLimit($workspace));
        $this->assertSame(true, $workspace->hasFeature('calendar'));
        $this->assertSame(true, $workspace->hasFeature('basic_client_management'));
        $this->assertSame(1, $workspace->maxMasters());
    }
}
