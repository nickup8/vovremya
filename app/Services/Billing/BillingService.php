<?php

namespace App\Services\Billing;

use App\Enums\PaymentAttemptStatus;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Services\Payment\PaymentGatewayInterface;
use App\Services\WorkspaceService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        $price = $this->priceResolver->calculatePrice($plan, $periodMonths);

        if (! $price) {
            // Fallback: compute from tariff_plans.price_monthly if plan_prices not seeded
            $base = $plan->price_monthly * $periodMonths;

            return [
                'plan_price_id' => null,
                'base' => $base,
                'discount_percent' => 0,
                'final' => $base,
                'currency' => 'RUB',
                'version' => null,
                'period_months' => $periodMonths,
            ];
        }

        return $price;
    }

    public function subscribe(User $master, TariffPlan $plan, int $periodMonths): array
    {
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
            return Cache::lock($lockKey, 120)->block(30, function () use ($master, $plan, $periodMonths, $price) {
                // ── Check for in-flight attempt (workspace+plan, no period) ──
                $inFlight = $this->coreWriter->findExistingInFlightAttempt(
                    $master->workspace_id,
                    $plan->id,
                );

                if ($inFlight) {
                    return $this->handleInFlightAttempt($inFlight, $master);
                }

                // ── Core horizon for stacking (replaces legacy activeSubscription().expires_at) ──
                $period = $this->horizon->computeNewPeriod(
                    $master->workspace,
                    $plan->code,
                    $periodMonths,
                );

                // ── Phase A: DB intent (legacy + Core) ──
                $intent = DB::transaction(function () use ($master, $plan, $periodMonths, $price, $period) {
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

                    $coreResult = $this->coreWriter->checkoutCreated($subscription, $plan, $price, $periodMonths);

                    return [
                        'subscription' => $subscription,
                        'price' => $price,
                        'coreResult' => $coreResult,
                    ];
                });

                // ── Phase B: Gateway call (outside transaction) ──
                try {
                    $paymentResult = $this->gateway->createPayment(
                        $intent['subscription'],
                        $intent['price']['final'],
                        $intent['coreResult']['internalOrderId'],
                    );
                } catch (\Throwable $e) {
                    DB::transaction(fn () => $this->coreWriter->checkoutFailed($intent['coreResult']['internalOrderId']));

                    throw $e;
                }

                // ── Phase C: Attach provider payment ID + checkout URL atomically ──
                DB::transaction(function () use ($intent, $paymentResult) {
                    Subscription::where('id', $intent['subscription']->id)
                        ->lockForUpdate()
                        ->first();

                    $this->coreWriter->paymentAttached(
                        $intent['coreResult']['internalOrderId'],
                        $paymentResult['payment_id'],
                        $paymentResult['confirmation_url'] ?? $paymentResult['checkout_url'] ?? '',
                        $intent['subscription'],
                    );
                });

                return [
                    'subscription' => $intent['subscription']->refresh(),
                    'confirmation_url' => $paymentResult['confirmation_url'],
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
     * Возвращает существующий checkout_url с тем же response contract,
     * или controlled 422 для unknown/created без checkout_url.
     */
    private function handleInFlightAttempt(
        \App\Models\PaymentAttempt $inFlight,
        User $master,
    ): array {
        $metadata = $inFlight->metadata ?? [];
        $checkoutUrl = $metadata['checkout_url'] ?? null;

        if ($inFlight->status === PaymentAttemptStatus::Processing && $checkoutUrl) {
            // Возвращаем существующий checkout
            $legacySubId = $metadata['legacy_subscription_id'] ?? null;
            $subscription = $legacySubId
                ? Subscription::find($legacySubId)
                : Subscription::where('workspace_id', $master->workspace_id)
                    ->where('status', 'pending')
                    ->latest()
                    ->first();

            return [
                'subscription' => $subscription?->refresh() ?? $this->createFallbackSubscription($master),
                'confirmation_url' => $checkoutUrl,
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

        // Core-first: read current plan from EntitlementService
        $currentPlan = app(EntitlementService::class)->currentPlan($ws);

        if (! $currentPlan) {
            return null; // нет активной подписки — не понижение
        }

        $currentMax = $currentPlan->maxMasters; // PHP_INT_MAX = безлимит
        $newMax = $plan->max_masters ?? PHP_INT_MAX;

        // Блокируем только если новый лимит строго строже текущего
        if ($newMax >= $currentMax) {
            return null; // новый лимит не строже — не понижение мест
        }

        $providersCount = $ws->providersCount();

        if ($providersCount > $newMax) {
            return "Невозможно понизить тариф: сейчас {$providersCount} провайдеров, а новый тариф даёт мест — {$newMax}. Отключите лишних участников (свитч «Принимаю клиентов»), затем повторите.";
        }

        return null;
    }
}
