<?php

namespace Tests\Feature\Billing;

use App\Models\PlanPrice;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanCatalogHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.legacy_mock_webhook_secret' => 'test_secret_123']);
        config(['billing.core_entitlement' => true]);

        TariffPlan::create([
            'code' => 'start',
            'name' => 'Старт',
            'price_monthly' => 0,
            'max_appointments_per_month' => 30,
            'max_masters' => 1,
            'features' => ['calendar', 'basic_client_management'],
            'is_active' => true,
        ]);

        TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'max_appointments_per_month' => null,
            'max_masters' => 3,
            'features' => ['calendar', 'basic_client_management', 'client_management', 'channel_analytics', 'slot_autofill', 'recurring_appointments'],
            'is_active' => true,
        ]);

        TariffPlan::create([
            'code' => 'studio',
            'name' => 'Студия',
            'price_monthly' => 990,
            'max_appointments_per_month' => null,
            'max_masters' => 5,
            'features' => ['calendar', 'basic_client_management', 'client_management', 'channel_analytics', 'slot_autofill', 'recurring_appointments'],
            'is_active' => true,
        ]);

        TariffPlan::create([
            'code' => 'salon',
            'name' => 'Салон',
            'price_monthly' => 1990,
            'max_appointments_per_month' => null,
            'max_masters' => 20,
            'features' => ['calendar', 'basic_client_management', 'client_management', 'channel_analytics', 'slot_autofill', 'recurring_appointments'],
            'is_active' => true,
        ]);

        $proId = TariffPlan::where('code', 'pro')->value('id');
        foreach ([1, 3, 6, 12] as $months) {
            PlanPrice::create([
                'tariff_plan_id' => $proId,
                'period_months' => $months,
                'base_amount' => 490 * $months,
                'discount_percent' => 0,
                'final_amount' => 490 * $months,
                'currency' => 'RUB',
                'version' => 1,
                'valid_from' => now(),
                'is_active' => true,
            ]);
        }

        foreach (['studio', 'salon'] as $code) {
            $planId = TariffPlan::where('code', $code)->value('id');
            foreach ([1, 3, 6, 12] as $months) {
                $monthly = $code === 'studio' ? 990 : 1990;
                PlanPrice::create([
                    'tariff_plan_id' => $planId,
                    'period_months' => $months,
                    'base_amount' => $monthly * $months,
                    'discount_percent' => 0,
                    'final_amount' => $monthly * $months,
                    'currency' => 'RUB',
                    'version' => 1,
                    'valid_from' => now(),
                    'is_active' => true,
                ]);
            }
        }
    }

    private function createMasterWithWorkspace(): array
    {
        $master = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'ws-'.$master->id,
            'owner_id' => $master->id,
        ]);
        $workspace->ensureSlug();

        return [$master, $workspace];
    }

    // ═══════════════════════════════════════════
    // §1 Backend Checkout Allowlist
    // ═══════════════════════════════════════════

    public function test_checkout_rejected_for_studio(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $studioPlan = TariffPlan::where('code', 'studio')->first();

        $response = $this->actingAs($master)
            ->post('/admin/checkout', [
                'tariff_plan_id' => $studioPlan->id,
                'period_months' => 1,
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('tariff_plan_id');
    }

    public function test_checkout_rejected_for_salon(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $salonPlan = TariffPlan::where('code', 'salon')->first();

        $response = $this->actingAs($master)
            ->post('/admin/checkout', [
                'tariff_plan_id' => $salonPlan->id,
                'period_months' => 1,
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('tariff_plan_id');
    }

    public function test_checkout_rejected_for_start(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $startPlan = TariffPlan::where('code', 'start')->first();

        $response = $this->actingAs($master)
            ->post('/admin/checkout', [
                'tariff_plan_id' => $startPlan->id,
                'period_months' => 1,
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('tariff_plan_id');
    }

    public function test_checkout_allowed_for_pro(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $proPlan = TariffPlan::where('code', 'pro')->first();

        $response = $this->actingAs($master)
            ->post('/admin/checkout', [
                'tariff_plan_id' => $proPlan->id,
                'period_months' => 1,
            ]);

        $response->assertOk();
    }

    // ═══════════════════════════════════════════
    // §2 Billing Page
    // ═══════════════════════════════════════════

    private function getBillingPageCodes(array $masterData): array
    {
        $response = $this->actingAs($masterData[0])->get('/admin/billing');
        $response->assertOk();
        $props = $response->viewData('page')['props'];
        return array_column($props['plans'], 'code');
    }

    public function test_billing_page_does_not_offer_studio(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $codes = $this->getBillingPageCodes([$master]);
        $this->assertNotContains('studio', $codes);
    }

    public function test_billing_page_does_not_offer_salon(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $codes = $this->getBillingPageCodes([$master]);
        $this->assertNotContains('salon', $codes);
    }

    public function test_billing_page_offers_start_and_pro(): void
    {
        [$master] = $this->createMasterWithWorkspace();

        $codes = $this->getBillingPageCodes([$master]);
        $this->assertContains('start', $codes);
        $this->assertContains('pro', $codes);
    }

    // ═══════════════════════════════════════════
    // §3 Data Migration
    // ═══════════════════════════════════════════

    public function test_migration_disables_studio_and_salon(): void
    {
        $this->assertTrue(TariffPlan::where('code', 'studio')->first()->is_active);
        $this->assertTrue(TariffPlan::where('code', 'salon')->first()->is_active);

        // Run the migration directly since RefreshDatabase already applied it
        $migration = require database_path('migrations/2026_10_16_100000_retire_legacy_billing_plans.php');
        $migration->up();

        $studio = TariffPlan::where('code', 'studio')->first();
        $salon = TariffPlan::where('code', 'salon')->first();

        $this->assertFalse($studio->is_active);
        $this->assertFalse($salon->is_active);
        $this->assertNotNull($studio);
        $this->assertNotNull($salon);
    }

    public function test_migration_preserves_start_and_pro_active(): void
    {
        $migration = require database_path('migrations/2026_10_16_100000_retire_legacy_billing_plans.php');
        $migration->up();

        $this->assertTrue(TariffPlan::where('code', 'start')->first()->is_active);
        $this->assertTrue(TariffPlan::where('code', 'pro')->first()->is_active);
    }

    public function test_migration_preserves_historical_plan_prices(): void
    {
        $studioId = TariffPlan::where('code', 'studio')->value('id');
        $salonId = TariffPlan::where('code', 'salon')->value('id');

        $studioPriceCount = PlanPrice::where('tariff_plan_id', $studioId)->count();
        $salonPriceCount = PlanPrice::where('tariff_plan_id', $salonId)->count();

        $this->assertGreaterThan(0, $studioPriceCount);
        $this->assertGreaterThan(0, $salonPriceCount);

        $migration = require database_path('migrations/2026_10_16_100000_retire_legacy_billing_plans.php');
        $migration->up();

        $this->assertEquals($studioPriceCount, PlanPrice::where('tariff_plan_id', $studioId)->count());
        $this->assertEquals($salonPriceCount, PlanPrice::where('tariff_plan_id', $salonId)->count());
    }

    public function test_migration_is_idempotent(): void
    {
        $migration = require database_path('migrations/2026_10_16_100000_retire_legacy_billing_plans.php');
        $migration->up();
        $migration->up();

        $this->assertFalse(TariffPlan::where('code', 'studio')->first()->is_active);
        $this->assertFalse(TariffPlan::where('code', 'salon')->first()->is_active);
    }
}
