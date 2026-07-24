<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingWidgetAccessTest extends TestCase
{
    use RefreshDatabase;

    private function createSoloMaster(): User
    {
        $master = User::factory()->master()->create([
            'workspace_id' => null,
            'master_slug' => 'solo-master',
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);

        TariffPlan::create([
            'code' => 'start', 'name' => 'Старт', 'price_monthly' => 0,
            'max_appointments_per_month' => 30, 'max_masters' => 1,
            'features' => ['calendar', 'basic_client_management'], 'is_active' => true,
        ]);

        return $master;
    }

    private function createEmployedMaster(SubscriptionStatus $subscriptionStatus = SubscriptionStatus::Active): User
    {
        $workspace = Workspace::create([
            'name' => 'Studio',
            'owner_id' => User::factory()->create()->id,
        ]);

        $master = User::factory()->master()->create([
            'workspace_id' => $workspace->id,
            'master_slug' => 'employed-master',
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);

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
            'status' => $subscriptionStatus->value,
            'starts_at' => now()->subMonth(),
            'expires_at' => match ($subscriptionStatus) {
                SubscriptionStatus::Active => now()->addMonth(),
                SubscriptionStatus::Expired => now()->subMonth(),
            },
        ]);

        return $master;
    }

    public function test_solo_master_booking_widget_is_accessible(): void
    {
        $master = $this->createSoloMaster();

        $response = $this->get("/book/{$master->master_slug}");

        $response->assertStatus(200);
    }

    public function test_employed_master_booking_widget_redirects_to_studio(): void
    {
        $master = $this->createEmployedMaster(SubscriptionStatus::Active);

        $response = $this->get("/book/{$master->master_slug}");

        $response->assertStatus(302);
        $response->assertRedirect('/studio/' . $master->workspace->slug);
    }

    public function test_master_widget_revives_when_studio_subscription_expired(): void
    {
        $master = $this->createEmployedMaster(SubscriptionStatus::Expired);

        $response = $this->get("/book/{$master->master_slug}");

        $response->assertStatus(200);
    }
}
