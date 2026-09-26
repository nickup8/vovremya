<?php

namespace App\Services\Billing;

use App\Models\DiscountRule;
use App\Models\PlanPrice;
use App\Models\TariffPlan;
use Illuminate\Support\Facades\DB;

/**
 * Versioned PlanPrice publication when admin changes tariff plan base price.
 *
 * For each billing period (1, 3, 6, 12):
 * 1. Resolve discount_percent from active DiscountRule (or 0)
 * 2. Deactivate previous active PlanPrice (valid_to = now)
 * 3. Create new versioned PlanPrice with new base_amount + discount
 */
class PlanPricePublisher
{
    private const PERIODS = [1, 3, 6, 12];

    /**
     * Publish new PlanPrice versions after admin changes base price.
     *
     * Must run inside the same DB transaction as the TariffPlan update.
     */
    public function publish(TariffPlan $plan, ?int $newPriceMonthly = null): void
    {
        $priceMonthly = $newPriceMonthly ?? $plan->price_monthly;

        foreach (self::PERIODS as $months) {
            $baseAmount = $priceMonthly * $months;

            $discountPercent = DiscountRule::where('period_months', $months)
                ->where('is_active', true)
                ->value('discount_percent') ?? 0;

            $discount = (int) round($baseAmount * $discountPercent / 100);
            $finalAmount = $baseAmount - $discount;

            // Deactivate previous active version
            PlanPrice::where('tariff_plan_id', $plan->id)
                ->where('period_months', $months)
                ->where('is_active', true)
                ->update([
                    'valid_to' => now(),
                    'is_active' => false,
                ]);

            // Get next version number
            $maxVersion = PlanPrice::where('tariff_plan_id', $plan->id)
                ->where('period_months', $months)
                ->max('version') ?? 0;

            PlanPrice::create([
                'tariff_plan_id' => $plan->id,
                'period_months' => $months,
                'base_amount' => $baseAmount,
                'discount_percent' => $discountPercent,
                'final_amount' => $finalAmount,
                'currency' => 'RUB',
                'version' => $maxVersion + 1,
                'valid_from' => now(),
                'valid_to' => null,
                'is_active' => true,
            ]);
        }
    }
}
