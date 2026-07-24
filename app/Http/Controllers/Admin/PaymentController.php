<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\TariffPlan;
use App\Services\Billing\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private BillingService $billingService,
    ) {}

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
