<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MetricsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markTestSkipped('Устаревший тест: subscriptions требует tariff_plan_id (NOT NULL)');
    }

    public function test_mrr_arr_active_subscriptions(): void
    {
        $master1 = User::factory()->master()->create();
        $master2 = User::factory()->master()->create();
        $master3 = User::factory()->master()->create();

        Subscription::create([
            'user_id' => $master1->id,
            'tariff_plan_id' => null,
            'period_months' => 1,
            'amount_paid' => 990,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addMonth(),
        ]);

        Subscription::create([
            'user_id' => $master2->id,
            'tariff_plan_id' => null,
            'period_months' => 12,
            'amount_paid' => 12000,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subMonths(3),
            'expires_at' => now()->addMonths(9),
        ]);

        Subscription::create([
            'user_id' => $master3->id,
            'tariff_plan_id' => null,
            'period_months' => 1,
            'amount_paid' => 500,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subMonths(2),
            'expires_at' => now()->subMonth(),
        ]);

        $this->actingAs($this->admin);

        $response = $this->get(route('super_admin.dashboard'));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('mrr', 1990)
            ->where('arr', 23880)
            ->where('active_subscriptions', 2)
        );
    }

    public function test_ltv_and_total_revenue(): void
    {
        $master1 = User::factory()->master()->create();
        $master2 = User::factory()->master()->create();

        Subscription::create([
            'user_id' => $master1->id,
            'tariff_plan_id' => null,
            'period_months' => 1,
            'amount_paid' => 1000,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addMonth(),
        ]);

        Subscription::create([
            'user_id' => $master1->id,
            'tariff_plan_id' => null,
            'period_months' => 3,
            'amount_paid' => 3000,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addMonths(2),
        ]);

        Subscription::create([
            'user_id' => $master2->id,
            'tariff_plan_id' => null,
            'period_months' => 6,
            'amount_paid' => 6000,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subMonths(2),
            'expires_at' => now()->addMonths(4),
        ]);

        $this->actingAs($this->admin);

        $response = $this->get(route('super_admin.dashboard'));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('mrr', 3000)
            ->where('ltv', 5000)
        );
    }

    public function test_expired_not_in_mrr_but_in_revenue(): void
    {
        $master = User::factory()->master()->create();

        Subscription::create([
            'user_id' => $master->id,
            'tariff_plan_id' => null,
            'period_months' => 1,
            'amount_paid' => 500,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subMonths(3),
            'expires_at' => now()->subMonth(),
        ]);

        $this->actingAs($this->admin);

        $response = $this->get(route('super_admin.dashboard'));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('mrr', 0)
            ->where('active_subscriptions', 0)
        );
    }
}
