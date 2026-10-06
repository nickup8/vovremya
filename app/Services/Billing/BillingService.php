<?php

namespace App\Services\Billing;

use App\Enums\PaymentAttemptStatus;
use App\Models\DiscountRule;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Services\Payment\DTOs\PaymentInitiation;
use App\Services\Payment\PaymentGatewayInterface;
use App\Services\WorkspaceService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BillingService
{
    public function __construct(
        private PaymentGatewayInterface $gateway,
        private BillingCoreWriter $coreWriter,
        private PlanPriceResolver $priceResolver,
        private BillingHorizon $horizon,
    ) {}

    public function calculatePrice(TariffPlan $plan, int $periodMonths): array
    {
        if (config('billing.core_entitlement')) {
            return $this->calculatePriceCore($plan, $periodMonths);
        }

        return $this->calculatePriceLegacy($plan, $periodMonths);
    }

    /**
     * Core mode: pricing exclusively from plan_prices table.
     * Fail closed — no fallback.
     */
    private function calculatePriceCore(TariffPlan $plan, int $periodMonths): array
    {
        $price = $this->priceResolver->calculatePrice($plan, $periodMonths);

        if (! $price) {
            throw ValidationException::withMessages([
                'plan' => "Цена не найдена для плана {$plan->code} на {$periodMonths} мес. Обратитесь к администратору.",
            ]);
        }

        return $price;
    }

    /**
     * Legacy rollback mode: pricing from tariff_plans.price_monthly + discount_rules.
     */
    private function calculatePriceLegacy(TariffPlan $plan, int $periodMonths): array
    {
        $base = $plan->price_monthly * $periodMonths;

        $discountPercent = DiscountRule::where('period_months', $periodMonths)
            ->where('is_active', true)
            ->value('discount_percent') ?? 0;

        $discount = (int) round($base * $discountPercent / 100);

        return [
            'plan_price_id' => null,
            'base' => $base,
            'discount_percent' => $discountPercent,
            'final' => $base - $discount,
            'currency' => 'RUB',
            'version' => null,
            'period_months' => $periodMonths,
        ];
    }

    public function subscribe(User $master, TariffPlan $plan, int $periodMonths, bool $autoRenew, string $paymentMethod): array
    {
        if (! in_array($paymentMethod, ['sbp', 'card'], true)) {
            throw ValidationException::withMessages([
                'payment_method' => 'Недоступный способ оплаты.',
            ]);
        }

        if ($paymentMethod === 'sbp' && $autoRenew) {
            throw ValidationException::withMessages([
                'auto_renew' => 'Автопродление через СБП пока недоступно. Отключите автопродление или выберите банковскую карту.',
            ]);
        }

        if (! $master->workspace_id) {
            $workspace = app(WorkspaceService::class)->createForUser($master);
            $master->refresh();
        }

        $blockReason = $this->downgradeBlockReason($master, $plan);
        if ($blockReason !== null) {
            throw ValidationException::withMessages([
                'plan' => $blockReason,
            ]);
        }

        $price = $this->calculatePrice($plan, $periodMonths);
        $lockKey = "billing-checkout:{$master->workspace_id}:{$plan->id}";

        // ── Atomic lock: весь checkout flow (TTL 120s > HTTP timeout ~20s) ──
        try {
            return Cache::lock($lockKey, 120)->block(30, function () use ($master, $plan, $periodMonths, $price, $autoRenew, $paymentMethod) {
                // ── Check for in-flight attempt (workspace+plan, no period) ──
                $inFlight = $this->coreWriter->findExistingInFlightAttempt(
                    $master->workspace_id,
                    $plan->id,
                );

                if ($inFlight) {
                    return $this->handleInFlightAttempt($inFlight, $master);
                }

                // ── Core horizon for stacking (replaces legacy activeSubscription().expires_at) ──
                $period = config('billing.core_entitlement')
                    ? $this->horizon->computeNewPeriod($master->workspace, $plan->code, $periodMonths)
                    : $this->computeLegacyPeriod($master, $periodMonths);

                // ── Phase A: DB intent (legacy + Core) ──
                $intent = DB::transaction(function () use ($master, $plan, $periodMonths, $price, $period, $autoRenew) {
                    // P1.1c: помечаем прежние незавершённые pending этого workspace как failed
                    if ($master->workspace_id) {
                        Subscription::where('workspace_id', $master->workspace_id)
                            ->where('status', 'pending')
                            ->update(['status' => 'failed']);
                    }

                    $subscription = Subscription::create([
                        'workspace_id' => $master->workspace_id,
                        'tariff_plan_id' => $plan->id,
                        'period_months' => $periodMonths,
                        'amount_paid' => $price['final'],
                        'status' => 'pending',
                        'starts_at' => $period['period_start'],
                        'expires_at' => $period['period_end'],
                    ]);

                    $coreResult = $this->coreWriter->checkoutCreated(
                        $subscription,
                        $plan,
                        $price,
                        $periodMonths,
                        $this->gateway->name(),
                        $autoRenew,
                    );

                    return [
                        'subscription' => $subscription,
                        'price' => $price,
                        'coreResult' => $coreResult,
                    ];
                });

                // ── Phase B: Gateway call (outside transaction) ──
                try {
                    $paymentResult = $this->gateway->createPayment(
                        amount: $intent['price']['final'],
                        currency: $intent['price']['currency'] ?? 'RUB',
                        internalOrderId: $intent['coreResult']['internalOrderId'],
                        context: [
                            'workspace_id' => $master->workspace_id,
                            'auto_renew' => $autoRenew,
                            'payment_method' => $paymentMethod,
                        ],
                    );

                    // Fail closed: initiation method must match the requested
                    // checkout method (card → redirect+URL, sbp → payload).
                    if ($paymentMethod === 'sbp') {
                        if ($paymentResult->method !== PaymentInitiation::METHOD_SBP
                            || $paymentResult->payload === null) {
                            throw new RuntimeException("Unsupported payment initiation: method={$paymentResult->method}");
                        }
                    } elseif ($paymentResult->method !== PaymentInitiation::METHOD_REDIRECT
                        || $paymentResult->checkoutUrl === null) {
                        throw new RuntimeException("Unsupported payment initiation: method={$paymentResult->method}");
                    }

                    $checkoutUrl = $paymentResult->checkoutUrl;
                } catch (\Throwable $e) {
                    DB::transaction(fn () => $this->coreWriter->checkoutFailed($intent['coreResult']['internalOrderId']));

                    throw $e;
                }

                // ── Phase C: Attach provider payment ID + initiation data atomically ──
                DB::transaction(function () use ($intent, $paymentResult, $paymentMethod) {
                    Subscription::where('id', $intent['subscription']->id)
                        ->lockForUpdate()
                        ->first();

                    $this->coreWriter->paymentAttached(
                        $intent['coreResult']['internalOrderId'],
                        $paymentResult,
                        $paymentMethod,
                        $intent['subscription'],
                    );
                });

                if ($paymentMethod === 'sbp') {
                    return [
                        'subscription' => $intent['subscription']->refresh(),
                        'payment_method' => PaymentInitiation::METHOD_SBP,
                        'payment_id' => $paymentResult->providerPaymentId,
                        'sbp_payload' => $paymentResult->payload,
                    ];
                }

                return [
                    'subscription' => $intent['subscription']->refresh(),
                    'confirmation_url' => $checkoutUrl,
                    // Provider id of this exact attempt — lets the caller bind
                    // the bank return to it instead of any "latest payment".
                    'payment_id' => $paymentResult->providerPaymentId,
                ];
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'plan' => 'Платёж уже обрабатывается. Попробуйте через несколько секунд.',
            ]);
        }
    }

    /**
     * Обработка повторного checkout при наличии in-flight attempt.
     *
     * Возвращает существующий initiation (card checkout_url / SBP payload)
     * с тем же response contract, или controlled 422 для unknown/created
     * без данных оплаты.
     */
    private function handleInFlightAttempt(
        \App\Models\PaymentAttempt $inFlight,
        User $master,
    ): array {
        $metadata = $inFlight->metadata ?? [];
        $checkoutUrl = $metadata['checkout_url'] ?? null;
        $sbpPayload = $metadata['sbp_payload'] ?? null;

        if ($inFlight->status === PaymentAttemptStatus::Processing && $checkoutUrl) {
            // Возвращаем существующий checkout
            return [
                'subscription' => $this->resolveInFlightSubscription($metadata, $master),
                'confirmation_url' => $checkoutUrl,
                'payment_id' => $inFlight->provider_payment_id,
            ];
        }

        if ($inFlight->status === PaymentAttemptStatus::Processing && $sbpPayload) {
            // Возвращаем существующий SBP payment (идемпотентный повтор)
            return [
                'subscription' => $this->resolveInFlightSubscription($metadata, $master),
                'payment_method' => 'sbp',
                'payment_id' => $inFlight->provider_payment_id,
                'sbp_payload' => $sbpPayload,
            ];
        }

        if ($inFlight->status === PaymentAttemptStatus::Processing) {
            throw ValidationException::withMessages([
                'plan' => 'Платёж уже обрабатывается. Попробуйте через несколько секунд.',
            ]);
        }

        // Unknown — 422, НЕ supersede, НЕ retry
        throw ValidationException::withMessages([
            'plan' => 'Статус предыдущего платежа уточняется. Попробуйте позже.',
        ]);
    }

    private function resolveInFlightSubscription(
        array $metadata,
        User $master,
    ): Subscription {
        $legacySubId = $metadata['legacy_subscription_id'] ?? null;
        $subscription = $legacySubId
            ? Subscription::find($legacySubId)
            : Subscription::where('workspace_id', $master->workspace_id)
                ->where('status', 'pending')
                ->latest()
                ->first();

        return $subscription?->refresh() ?? $this->createFallbackSubscription($master);
    }

    private function createFallbackSubscription(User $master): Subscription
    {
        return Subscription::where('workspace_id', $master->workspace_id)
            ->where('status', 'pending')
            ->latest()
            ->firstOrFail();
    }

    /**
     * Проверка понижения лимита мест провайдеров.
     * Core-first: uses EntitlementService for current plan detection.
     * Возвращает null если можно, или строку-причину если нельзя.
     */
    public function downgradeBlockReason(User $master, TariffPlan $plan): ?string
    {
        if (! $master->workspace_id) {
            return null; // первая подписка одиночки — нечего блокировать
        }

        $ws = $master->workspace;

        if (config('billing.core_entitlement')) {
            return $this->downgradeBlockReasonCore($ws, $plan);
        }

        return $this->downgradeBlockReasonLegacy($ws, $plan);
    }

    private function downgradeBlockReasonCore(\App\Models\Workspace $ws, TariffPlan $plan): ?string
    {
        $currentPlan = app(EntitlementService::class)->currentPlan($ws);

        if (! $currentPlan) {
            return null; // нет активной подписки — не понижение
        }

        $currentMax = $currentPlan->maxMasters; // PHP_INT_MAX = безлимит
        $newMax = $plan->max_masters ?? PHP_INT_MAX;

        if ($newMax >= $currentMax) {
            return null;
        }

        $providersCount = $ws->providersCount();

        if ($providersCount > $newMax) {
            return "Невозможно понизить тариф: сейчас {$providersCount} провайдеров, а новый тариф даёт мест — {$newMax}. Отключите лишних участников (свитч «Принимаю клиентов»), затем повторите.";
        }

        return null;
    }

    private function downgradeBlockReasonLegacy(\App\Models\Workspace $ws, TariffPlan $plan): ?string
    {
        $activeSub = $ws->activeSubscription();

        if (! $activeSub || ! $activeSub->tariffPlan) {
            return null;
        }

        $currentMax = $activeSub->tariffPlan->max_masters ?? PHP_INT_MAX;
        $newMax = $plan->max_masters ?? PHP_INT_MAX;

        if ($newMax >= $currentMax) {
            return null;
        }

        $providersCount = $ws->providersCount();

        if ($providersCount > $newMax) {
            return "Невозможно понизить тариф: сейчас {$providersCount} провайдеров, а новый тариф даёт мест — {$newMax}. Отключите лишних участников (свитч «Принимаю клиентов»), затем повторите.";
        }

        return null;
    }

    /**
     * Legacy stacking: use activeSubscription().expires_at as period_start.
     */
    private function computeLegacyPeriod(User $master, int $periodMonths): array
    {
        $activeSub = $master->workspace?->activeSubscription();

        if ($activeSub && $activeSub->expires_at && $activeSub->expires_at->isFuture()) {
            $periodStart = $activeSub->expires_at->copy();
        } else {
            $periodStart = Carbon::now();
        }

        return [
            'period_start' => $periodStart,
            'period_end' => $periodStart->copy()->addMonths($periodMonths),
        ];
    }
}
