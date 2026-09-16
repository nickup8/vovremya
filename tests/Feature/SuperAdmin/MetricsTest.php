<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\AppointmentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Appointment;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MetricsTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->proPlan = TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'max_appointments_per_month' => null,
            'max_masters' => 1,
            'features' => ['unlimited_appointments'],
            'is_active' => true,
        ]);

        $this->admin = User::factory()->master()->create([
            'is_super_admin' => true,
            'created_at' => now()->subDays(60),
        ]);
    }

    // ── Access ──

    public function test_regular_user_cannot_access_dashboard(): void
    {
        $user = User::factory()->master()->create(['is_super_admin' => false]);

        $this->actingAs($user)
            ->get(route('super_admin.dashboard'))
            ->assertStatus(403);
    }

    public function test_super_admin_can_access_dashboard(): void
    {
        $this->actingAs($this->admin)
            ->get(route('super_admin.dashboard'))
            ->assertOk();
    }

    // ── Financial ──

    public function test_mrr_arr_active_subscriptions(): void
    {
        $master1 = User::factory()->master()->create();
        $master2 = User::factory()->master()->create();
        $master3 = User::factory()->master()->create();

        $ws1 = Workspace::create(['name' => 'WS1', 'owner_id' => $master1->id]);
        $ws2 = Workspace::create(['name' => 'WS2', 'owner_id' => $master2->id]);
        $ws3 = Workspace::create(['name' => 'WS3', 'owner_id' => $master3->id]);

        Subscription::create([
            'workspace_id' => $ws1->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 990,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addMonth(),
        ]);

        Subscription::create([
            'workspace_id' => $ws2->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 12,
            'amount_paid' => 12000,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subMonths(3),
            'expires_at' => now()->addMonths(9),
        ]);

        // Expired subscription
        Subscription::create([
            'workspace_id' => $ws3->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 500,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subMonths(2),
            'expires_at' => now()->subMonth(),
        ]);

        $this->actingAs($this->admin);

        $response = $this->get(route('super_admin.dashboard'));

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

        $ws1 = Workspace::create(['name' => 'WS1', 'owner_id' => $master1->id]);
        $ws2 = Workspace::create(['name' => 'WS2', 'owner_id' => $master2->id]);

        Subscription::create([
            'workspace_id' => $ws1->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 1000,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addMonth(),
        ]);

        Subscription::create([
            'workspace_id' => $ws1->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 3,
            'amount_paid' => 3000,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addMonths(2),
        ]);

        Subscription::create([
            'workspace_id' => $ws2->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 6,
            'amount_paid' => 6000,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subMonths(2),
            'expires_at' => now()->addMonths(4),
        ]);

        $this->actingAs($this->admin);

        $response = $this->get(route('super_admin.dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('mrr', 3000)
            ->where('ltv', 5000)
        );
    }

    public function test_expired_not_in_mrr(): void
    {
        $master = User::factory()->master()->create();
        $ws = Workspace::create(['name' => 'WS', 'owner_id' => $master->id]);

        Subscription::create([
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 500,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subMonths(3),
            'expires_at' => now()->subMonth(),
        ]);

        $this->actingAs($this->admin);

        $response = $this->get(route('super_admin.dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('mrr', 0)
            ->where('active_subscriptions', 0)
        );
    }

    // ── Masters ──

    public function test_total_masters(): void
    {
        User::factory()->master()->create();
        User::factory()->master()->create();
        User::factory()->create(['is_master' => false]);

        $this->actingAs($this->admin);

        $response = $this->get(route('super_admin.dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('total_masters', 3)
        );
    }

    public function test_new_masters_30d(): void
    {
        User::factory()->master()->create(['created_at' => now()->subDays(10)]);
        User::factory()->master()->create(['created_at' => now()->subDays(40)]);

        $this->actingAs($this->admin);

        $response = $this->get(route('super_admin.dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('new_masters_30d', 1)
        );
    }

    // ── Workspaces ──

    public function test_workspace_count(): void
    {
        $m1 = User::factory()->master()->create();
        $m2 = User::factory()->master()->create();
        Workspace::create(['name' => 'W1', 'owner_id' => $m1->id]);
        Workspace::create(['name' => 'W2', 'owner_id' => $m2->id]);

        $this->actingAs($this->admin);

        $response = $this->get(route('super_admin.dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('total_workspaces', 2)
        );
    }

    // ── Tariffs ──

    public function test_pro_count(): void
    {
        $master = User::factory()->master()->create();
        $ws = Workspace::create(['name' => 'Pro WS', 'owner_id' => $master->id]);

        Subscription::create([
            'workspace_id' => $ws->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
        ]);

        $this->actingAs($this->admin);

        $response = $this->get(route('super_admin.dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('pro_count', 1)
        );
    }

    public function test_start_count(): void
    {
        $m1 = User::factory()->master()->create();
        $m2 = User::factory()->master()->create();
        Workspace::create(['name' => 'WS1', 'owner_id' => $m1->id]);
        $ws2 = Workspace::create(['name' => 'WS2', 'owner_id' => $m2->id]);

        Subscription::create([
            'workspace_id' => $ws2->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
        ]);

        $this->actingAs($this->admin);

        $response = $this->get(route('super_admin.dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('start_count', 1)
            ->where('pro_count', 1)
        );
    }

    // ── Appointments ──

    public function test_appointments_created_30d(): void
    {
        $master = User::factory()->master()->create();

        Appointment::factory()->forMaster($master)->create([
            'created_at' => now()->subDays(5),
        ]);

        Appointment::factory()->forMaster($master)->create([
            'created_at' => now()->subDays(60),
        ]);

        $this->actingAs($this->admin);

        $response = $this->get(route('super_admin.dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('appointments_30d', 1)
        );
    }

    public function test_cancellations_30d(): void
    {
        $master = User::factory()->master()->create();

        Appointment::factory()->forMaster($master)->cancelled()->create([
            'cancelled_at' => now()->subDays(3),
        ]);

        Appointment::factory()->forMaster($master)->cancelled()->create([
            'cancelled_at' => now()->subDays(60),
        ]);

        Appointment::factory()->forMaster($master)->booked()->create();

        $this->actingAs($this->admin);

        $response = $this->get(route('super_admin.dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('cancellations_30d', 1)
        );
    }

    // ── Messengers ──

    public function test_telegram_linked(): void
    {
        User::factory()->master()->create(['telegram_id' => 'tg_1']);
        User::factory()->master()->create(['telegram_id' => null]);

        $this->actingAs($this->admin);

        $response = $this->get(route('super_admin.dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('telegram_linked', 1)
        );
    }

    public function test_max_linked(): void
    {
        User::factory()->master()->create(['max_id' => 'max_1']);
        User::factory()->master()->create(['max_id' => null]);

        $this->actingAs($this->admin);

        $response = $this->get(route('super_admin.dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('max_linked', 1)
        );
    }

    public function test_vk_linked(): void
    {
        User::factory()->master()->create(['vk_id' => 'vk_1']);
        User::factory()->master()->create(['vk_id' => null]);

        $this->actingAs($this->admin);

        $response = $this->get(route('super_admin.dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('vk_linked', 1)
        );
    }
}
