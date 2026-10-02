<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BillingSubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\BillingSubscription;
use App\Models\TariffPlan;
use App\Services\Billing\BillingService;
use App\Services\Billing\EntitlementService;
use App\Support\PlanDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PaymentController extends Controller
{
    public function __construct(
        private BillingService $billingService,
    ) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->role->canManageBilling(), 403);

        $plans = TariffPlan::whereIn('code', PlanDefaults::BILLING_PAGE_CODES)
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

        if (config('billing.core_entitlement') && $user->workspace) {
            $plan = app(EntitlementService::class)->currentPlan($user->workspace);
            $subscription = $this->activeBillingSubscription($user->workspace_id);

            return Inertia::render('admin/billing', [
                'plans' => $plans,
                'current' => [
                    'tariff' => $plan?->code ?? 'start',
                    'tariff_name' => $plan?->name ?? 'Старт',
                    'is_paid' => ($plan?->code ?? 'start') !== 'start',
                    'expires_at' => $plan?->expiresAt?->toIso8601String(),
                    'days_left' => $plan?->expiresAt ? (int) ceil(now()->diffInDays($plan->expiresAt, absolute: false)) : 0,
                    'auto_renew_enabled' => $subscription !== null
                        && $subscription->auto_renew_consent_at !== null
                        && $subscription->renewal_period_months !== null
                        && $subscription->cancel_at_period_end === false,
                    'renewal_period_months' => $subscription?->renewal_period_months,
                ],
            ]);
        }

        $activeSub = $user->workspace?->activeSubscription();

        return Inertia::render('admin/billing', [
            'plans' => $plans,
            'current' => [
                'tariff' => $activeSub?->tariffPlan?->code ?? 'start',
                'tariff_name' => $activeSub?->tariffPlan?->name ?? 'Старт',
                'is_paid' => ($activeSub?->tariffPlan?->price_monthly ?? 0) > 0,
                'expires_at' => $activeSub?->expires_at?->toIso8601String(),
                'days_left' => $activeSub?->daysLeft() ?? 0,
            ],
        ]);
    }

    public function createCheckout(Request $request): JsonResponse
    {
        abort_unless(auth()->user()->role->canManageBilling(), 403, 'Только владелец может управлять подпиской.');

        $validated = $request->validate([
            'tariff_plan_id' => 'required|exists:tariff_plans,id',
            'period_months' => 'required|integer|in:1,3,6,12',
            'auto_renew' => 'sometimes|boolean',
        ]);

        /** @var TariffPlan $plan */
        $plan = TariffPlan::findOrFail($validated['tariff_plan_id']);

        if (! in_array($plan->code, PlanDefaults::CHECKOUT_ALLOWED_CODES, true)) {
            throw ValidationException::withMessages([
                'tariff_plan_id' => "Оформление подписки на тариф «{$plan->name}» недоступно. Выберите другой тариф.",
            ]);
        }

        $master = auth()->user();

        $result = $this->billingService->subscribe(
            $master,
            $plan,
            $validated['period_months'],
            (bool) ($validated['auto_renew'] ?? false),
        );

        return response()->json([
            'checkout_url' => $result['confirmation_url'],
            'subscription_id' => $result['subscription']->id,
            'amount' => $result['subscription']->amount_paid,
        ]);
    }

    /**
     * Disable auto renewal for the current Pro subscription.
     *
     * Idempotent: only flips cancel_at_period_end and clears next_charge_at;
     * the paid period keeps running until it ends.
     */
    public function disableAutoRenew(Request $request): JsonResponse
    {
        abort_unless($request->user()->role->canManageBilling(), 403, 'Только владелец может управлять подпиской.');

        $workspaceId = $request->user()->workspace_id;
        abort_unless($workspaceId !== null, 404);

        $subscription = DB::transaction(function () use ($workspaceId) {
            $subscription = BillingSubscription::where('workspace_id', $workspaceId)
                ->where('status', BillingSubscriptionStatus::Active)
                ->lockForUpdate()
                ->first();

            if ($subscription === null) {
                return null;
            }

            $subscription->update([
                'cancel_at_period_end' => true,
                'next_charge_at' => null,
            ]);

            return $subscription;
        });

        if ($subscription === null) {
            abort(404, 'Активная подписка не найдена');
        }

        return response()->json([
            'ok' => true,
            'auto_renew_enabled' => false,
        ]);
    }

    private function activeBillingSubscription(?string $workspaceId): ?BillingSubscription
    {
        if ($workspaceId === null) {
            return null;
        }

        return BillingSubscription::where('workspace_id', $workspaceId)
            ->where('status', BillingSubscriptionStatus::Active)
            ->latest('updated_at')
            ->first();
    }
}
