<?php

namespace App\Services\Billing;

use App\Models\DiscountRule;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Services\Payment\PaymentGatewayInterface;
use Illuminate\Support\Carbon;

class BillingService
{
    public function __construct(
        private PaymentGatewayInterface $gateway,
    ) {}

    public function calculatePrice(TariffPlan $plan, int $periodMonths): array
    {
        $discountRule = DiscountRule::where('period_months', $periodMonths)
            ->where('is_active', true)
            ->first();

        $discountPercent = $discountRule?->discount_percent ?? 0;

        $base = $plan->price_monthly * $periodMonths;
        $final = (int) round($base * (1 - $discountPercent / 100));

        return [
            'base' => $base,
            'discount_percent' => $discountPercent,
            'final' => $final,
        ];
    }

    public function subscribe(User $master, TariffPlan $plan, int $periodMonths): array
    {
        $price = $this->calculatePrice($plan, $periodMonths);

        $subscription = Subscription::create([
            'workspace_id' => $master->workspace_id,
            'tariff_plan_id' => $plan->id,
            'period_months' => $periodMonths,
            'amount_paid' => $price['final'],
            'status' => 'pending',
            'starts_at' => Carbon::now(),
            'expires_at' => Carbon::now()->copy()->addMonths($periodMonths),
        ]);

        $paymentResult = $this->gateway->createPayment($subscription, $price['final']);

        $subscription->update(['payment_id' => $paymentResult['payment_id']]);

        return [
            'subscription' => $subscription,
            'confirmation_url' => $paymentResult['confirmation_url'],
        ];
    }
}
