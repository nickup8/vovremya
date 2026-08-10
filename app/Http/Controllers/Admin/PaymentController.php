<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TariffPlan;
use App\Services\Billing\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PaymentController extends Controller
{
    public function __construct(
        private BillingService $billingService,
    ) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->role->canManageBilling(), 403);

        $plans = TariffPlan::whereIn('code', ['start', 'pro'])
            ->where('is_active', true)
            ->orderBy('price_monthly')
            ->get()
            ->map(fn (TariffPlan $plan) => [
                'id' => $plan->id,
                'code' => $plan->code,
                'name' => $plan->name,
                'price_monthly' => $plan->price_monthly,
                'max_appointments_per_month' => $plan->max_appointments_per_month,
                'features' => $plan->features,
                'prices' => $plan->price_monthly > 0
                    ? collect([1, 3, 6, 12])->map(fn (int $m) => array_merge(
                        ['period_months' => $m],
                        $this->billingService->calculatePrice($plan, $m),
                    ))->values()
                    : [],
            ]);

        $user = $request->user();
        $activeSub = $user->workspace?->activeSubscription();

        return Inertia::render('admin/billing', [
            'plans' => $plans,
            'current' => [
                'tariff' => $activeSub?->tariffPlan?->code ?? 'start',
                'tariff_name' => $activeSub?->tariffPlan?->name ?? 'Старт',
            ],
        ]);
    }

    public function createCheckout(Request $request): JsonResponse
    {
        abort_unless(auth()->user()->role->canManageBilling(), 403, 'Только владелец может управлять подпиской.');

        $validated = $request->validate([
            'tariff_plan_id' => 'required|exists:tariff_plans,id',
            'period_months' => 'required|integer|in:1,3,6,12',
        ]);

        $plan = TariffPlan::findOrFail($validated['tariff_plan_id']);
        $master = auth()->user();

        $result = $this->billingService->subscribe(
            $master,
            $plan,
            $validated['period_months'],
        );

        return response()->json([
            'checkout_url' => $result['confirmation_url'],
            'subscription_id' => $result['subscription']->id,
            'amount' => $result['subscription']->amount_paid,
        ]);
    }
}
