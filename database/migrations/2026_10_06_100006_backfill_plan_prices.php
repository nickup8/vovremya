<?php

use App\Models\DiscountRule;
use App\Models\PlanPrice;
use App\Models\TariffPlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $plans = TariffPlan::where('is_active', true)->get();
        $discounts = DiscountRule::where('is_active', true)->get()->keyBy('period_months');

        $periods = [1, 3, 6, 12];

        foreach ($plans as $plan) {
            foreach ($periods as $months) {
                $discount = $discounts->get($months);
                $discountPercent = $discount?->discount_percent ?? 0;
                $baseAmount = $plan->price_monthly * $months;
                $finalAmount = (int) round($baseAmount * (1 - $discountPercent / 100));

                PlanPrice::updateOrCreate(
                    [
                        'tariff_plan_id' => $plan->id,
                        'period_months' => $months,
                        'version' => 1,
                    ],
                    [
                        'base_amount' => $baseAmount,
                        'discount_percent' => $discountPercent,
                        'final_amount' => $finalAmount,
                        'currency' => 'RUB',
                        'valid_from' => $plan->created_at ?? now(),
                        'valid_to' => null,
                        'is_active' => true,
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('plan_prices')->delete();
    }
};
