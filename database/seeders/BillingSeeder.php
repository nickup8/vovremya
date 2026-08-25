<?php

namespace Database\Seeders;

use App\Models\DiscountRule;
use App\Models\TariffPlan;
use Illuminate\Database\Seeder;

class BillingSeeder extends Seeder
{
    public function run(): void
    {
        TariffPlan::updateOrCreate(
            ['code' => 'start'],
            [
                'name' => 'Старт',
                'price_monthly' => 0,
                'max_appointments_per_month' => 30,
                'max_masters' => 1,
                'features' => ['calendar', 'basic_client_management'],
                'is_active' => true,
            ],
        );

        TariffPlan::updateOrCreate(
            ['code' => 'pro'],
            [
                'name' => 'Профи',
                'price_monthly' => 490,
                'max_appointments_per_month' => null,
                'max_masters' => 1,
                'features' => ['unlimited_appointments', 'client_management', 'channel_analytics', 'client_reactivation'],
                'is_active' => true,
            ],
        );

        $discounts = [
            1 => 0,
            3 => 5,
            6 => 10,
            12 => 20,
        ];

        foreach ($discounts as $months => $percent) {
            DiscountRule::updateOrCreate(
                ['period_months' => $months],
                ['discount_percent' => $percent, 'is_active' => true],
            );
        }
    }
}
