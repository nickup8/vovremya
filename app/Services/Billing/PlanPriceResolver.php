<?php

namespace App\Services\Billing;

use App\Models\PlanPrice;
use App\Models\TariffPlan;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Single source of truth for resolving plan pricing.
 *
 * Replaces legacy discount_rules for billing decisions.
 * Selection rules:
 * 1. tariff_plan_id matches
 * 2. period_months matches
 * 3. is_active = true
 * 4. valid_from <= now
 * 5. valid_to IS NULL OR valid_to > now
 * 6. When multiple valid → highest version
 */
class PlanPriceResolver
{
    public function resolve(TariffPlan $plan, int $periodMonths, ?CarbonInterface $at = null): ?PlanPrice
    {
        $at = $at ?? Carbon::now();

        return PlanPrice::where('tariff_plan_id', $plan->id)
            ->where('period_months', $periodMonths)
            ->where('is_active', true)
            ->where('valid_from', '<=', $at)
            ->where(function ($q) use ($at) {
                $q->whereNull('valid_to')
                    ->orWhere('valid_to', '>', $at);
            })
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Calculate price from resolved PlanPrice.
     *
     * Returns the same shape as the old BillingService::calculatePrice()
     * for backward compatibility with callers that expect
     * ['base' => ..., 'discount_percent' => ..., 'final' => ...].
     */
    public function calculatePrice(TariffPlan $plan, int $periodMonths, ?CarbonInterface $at = null): ?array
    {
        $planPrice = $this->resolve($plan, $periodMonths, $at);

        if (! $planPrice) {
            return null;
        }

        return [
            'plan_price_id' => $planPrice->id,
            'base' => $planPrice->base_amount,
            'discount_percent' => $planPrice->discount_percent,
            'final' => $planPrice->final_amount,
            'currency' => $planPrice->currency,
            'version' => $planPrice->version,
            'period_months' => $planPrice->period_months,
        ];
    }

    /**
     * Build a price snapshot for storage in billing_cycles/attempt metadata.
     */
    public function snapshot(PlanPrice $planPrice): array
    {
        return [
            'plan_price_id' => $planPrice->id,
            'base_amount' => $planPrice->base_amount,
            'discount_percent' => $planPrice->discount_percent,
            'final_amount' => $planPrice->final_amount,
            'currency' => $planPrice->currency,
            'version' => $planPrice->version,
            'period_months' => $planPrice->period_months,
        ];
    }
}
