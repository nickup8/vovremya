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
            ['code' => 'pro'],
            [
                'name' => 'Профи',
                'price_monthly' => 490,
                'features' => ['unlimited_appointments', 'analytics', 'client_management'],
                'is_active' => true,
            ],
        );

        TariffPlan::updateOrCreate(
            ['code' => 'studio'],
            [
                'name' => 'Студия',
                'price_monthly' => 1290,
                'features' => ['unlimited_appointments', 'analytics', 'client_management', 'multi_master', 'priority_support'],
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
