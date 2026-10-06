<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BillingSubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\BillingSubscription;
use App\Models\PaymentAttempt;
use App\Models\TariffPlan;
use App\Services\Billing\BillingService;
use App\Services\Billing\EntitlementService;
use App\Support\PlanDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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

        // Payment return signal: the return routes only mark that the user came
        // back from the bank — they never claim success/failure (FailURL and
        // SuccessURL prove nothing; Billing Core does). The attempt id survives
        // reloads via the ?payment_attempt= query param.
        $paymentReturn = $this->paymentReturnState($request);

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
                'payment_return' => $paymentReturn['signal'] ? 'returned' : null,
                'payment_attempt_id' => $paymentReturn['attempt_id'],
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
            'payment_return' => $paymentReturn['signal'] ? 'returned' : null,
            'payment_attempt_id' => $paymentReturn['attempt_id'],
        ]);
    }

    /**
     * Own checkout page (PR1): read-only pricing for the Pro plan.
     *
     * No Subscription/PaymentAttempt is created and T-Bank is never called —
     * the POST /admin/checkout contract stays the source of the redirect.
     */
    public function checkout(Request $request)
    {
        abort_unless($request->user()->role->canManageBilling(), 403);

        // Controlled failure for missing/invalid period: this app renders
        // web validation errors as redirects (shouldRenderJsonWhen is api/*),
        // so an explicit 404 keeps the GET contract deterministic.
        $periodMonths = filter_var($request->query('period_months'), FILTER_VALIDATE_INT);
        abort_unless($periodMonths !== false && in_array($periodMonths, [1, 3, 6, 12], true), 404);

        $plan = TariffPlan::where('code', 'pro')
            ->where('is_active', true)
            ->first();

        abort_if($plan === null, 404);

        $price = $this->billingService->calculatePrice($plan, $periodMonths);

        return Inertia::render('admin/billing-checkout', [
            'plan' => [
                'id' => $plan->id,
                'code' => $plan->code,
                'name' => $plan->name,
                'price_monthly' => $plan->price_monthly,
            ],
            'period_months' => $periodMonths,
            'price' => [
                'base' => $price['base'],
                'discount_percent' => $price['discount_percent'],
                'final' => $price['final'],
                'currency' => $price['currency'] ?? 'RUB',
            ],
        ]);
    }

    /**
     * Payment provider return (SuccessURL) — never a status verdict.
     *
     * Redirects to billing carrying the checkout attempt bound at creation
     * time, so the client can verify it against Billing Core. No billing
     * data is read or mutated here.
     */
    public function paymentReturnSuccess(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role->canManageBilling(), 403);

        return $this->redirectAfterPaymentReturn($request);
    }

    /**
     * Payment provider return (FailURL) — a bank redirect alone does not
     * prove a declined payment, so this behaves exactly like SuccessURL:
     * bind the attempt and let the client verify the status.
     */
    public function paymentReturnFailed(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role->canManageBilling(), 403);

        return $this->redirectAfterPaymentReturn($request);
    }

    /**
     * One-shot handoff of the bound checkout attempt to the billing page.
     *
     * The session key is consumed on return (the ?payment_attempt= URL param
     * becomes the carrier, surviving reloads). Without a bound attempt the
     * redirect carries only the neutral "returned" flash — never a verdict.
     */
    private function redirectAfterPaymentReturn(Request $request): RedirectResponse
    {
        $attemptId = $request->session()->pull('payment_return_attempt');

        if (is_string($attemptId) && $attemptId !== '') {
            return redirect()->route('admin.billing', ['payment_attempt' => $attemptId]);
        }

        return redirect('/admin/billing')->with('payment_return', 'returned');
    }

    /**
     * @return array{signal: bool, attempt_id: ?string}
     */
    private function paymentReturnState(Request $request): array
    {
        $signal = session('payment_return') === 'returned';
        $attemptParam = $request->query('payment_attempt');

        if (! is_string($attemptParam) || $attemptParam === '') {
            return ['signal' => $signal, 'attempt_id' => null];
        }

        // The return URL carries the attempt id — the signal and the id stay
        // alive across reloads of the billing page, no flash needed.
        $workspaceId = $request->user()->workspace_id;
        $belongsToWorkspace = $workspaceId !== null && PaymentAttempt::query()
            ->where('provider_payment_id', $attemptParam)
            ->whereHas('billingCycle', function ($query) use ($workspaceId) {
                $query->where('workspace_id', $workspaceId);
            })
            ->exists();

        // Foreign or unknown id → neutral (signal without id), never a verdict.
        return ['signal' => true, 'attempt_id' => $belongsToWorkspace ? $attemptParam : null];
    }

    public function createCheckout(Request $request): JsonResponse
    {
        abort_unless(auth()->user()->role->canManageBilling(), 403, 'Только владелец может управлять подпиской.');

        $validated = $request->validate([
            'tariff_plan_id' => 'required|exists:tariff_plans,id',
            'period_months' => 'required|integer|in:1,3,6,12',
            'auto_renew' => 'sometimes|boolean',
            'payment_method' => 'required|string|in:sbp,card',
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
            $validated['payment_method'],
        );

        if (($result['payment_method'] ?? null) === 'sbp') {
            return response()->json([
                'payment_method' => 'sbp',
                'payment_id' => $result['payment_id'],
                'sbp_payload' => $result['sbp_payload'],
                'subscription_id' => $result['subscription']->id,
                'amount' => $result['subscription']->amount_paid,
            ]);
        }

        // Bind this exact checkout attempt to the session: the bank return
        // routes replay it so the billing page verifies this attempt only —
        // never "the latest payment".
        $boundAttemptId = $result['payment_id'] ?? null;
        if (is_string($boundAttemptId) && $boundAttemptId !== '') {
            $request->session()->put('payment_return_attempt', $boundAttemptId);
        }

        return response()->json([
            'payment_method' => 'card',
            'checkout_url' => $result['confirmation_url'],
            'subscription_id' => $result['subscription']->id,
            'amount' => $result['subscription']->amount_paid,
        ]);
    }

    /**
     * Read-only checkout status for return-page polling (SBP and card).
     *
     * Source of truth is the local PaymentAttempt (webhook/reconciliation
     * own its lifecycle) — T-Bank is never called from here. Workspace
     * scoping is unchanged: an attempt of another workspace yields 404. The
     * response carries the status string only: no metadata, payload or
     * internal ids.
     */
    public function paymentStatus(Request $request, string $paymentId): JsonResponse
    {
        abort_unless($request->user()->role->canManageBilling(), 403);

        $workspaceId = $request->user()->workspace_id;
        abort_unless($workspaceId !== null, 404);

        $attempt = PaymentAttempt::query()
            ->where('provider', 'tbank')
            ->where('provider_payment_id', $paymentId)
            ->whereHas('billingCycle', function ($query) use ($workspaceId) {
                $query->where('workspace_id', $workspaceId);
            })
            ->first();

        abort_if($attempt === null, 404);

        return response()->json([
            'status' => $attempt->status->value,
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
