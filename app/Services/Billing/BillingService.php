<?php

namespace App\Services\Billing;

use App\Enums\PaymentAttemptStatus;
use App\Models\DiscountRule;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Services\Payment\PaymentGatewayInterface;
use App\Services\WorkspaceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BillingService
{
    public function __construct(
        private PaymentGatewayInterface $gateway,
        private BillingCoreWriter $coreWriter,
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

        // ── Atomic lock: весь checkout flow ──
        return Cache::lock($lockKey, 60)->block(30, function () use ($master, $plan, $periodMonths, $price) {
            $now = Carbon::now();
            $currentSub = $master->workspace?->activeSubscription();

            $isSamePlanExtension = $currentSub
                && $currentSub->tariff_plan_id === $plan->id
                && $currentSub->expires_at !== null
                && $currentSub->expires_at->isFuture();

            $startsAt = $isSamePlanExtension
                ? $currentSub->expires_at->copy()
                : $now->copy();

            $expiresAt = $startsAt->copy()->addMonths($periodMonths);

            // ── Check for in-flight attempt ──
            $inFlight = $this->coreWriter->findExistingInFlightAttempt(
                $master->workspace_id,
                $plan->id,
                $startsAt->format('Y-m-d H:i:s'),
                $expiresAt->format('Y-m-d H:i:s'),
            );

            if ($inFlight) {
                return $this->handleInFlightAttempt($inFlight, $master);
            }

            // ── Phase A: DB intent (legacy + Core) ──
            $intent = DB::transaction(function () use ($master, $plan, $periodMonths, $price, $startsAt, $expiresAt) {
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
                    'starts_at' => $startsAt,
                    'expires_at' => $expiresAt,
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

        // Processing без checkout_url, Created, Unknown — controlled 422
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
     * Возвращает null если можно, или строку-причину если нельзя.
     */
    public function downgradeBlockReason(User $master, TariffPlan $plan): ?string
    {
        if (! $master->workspace_id) {
            return null; // первая подписка одиночки — нечего блокировать
        }

        $ws = $master->workspace;
        $currentSub = $ws?->activeSubscription();

        if (! $currentSub || ! $currentSub->tariffPlan) {
            return null; // нет активной подписки — не понижение
        }

        $currentMax = $currentSub->tariffPlan->max_masters; // null = безлимит
        $newMax = $plan->max_masters;                        // null = безлимит

        // Блокируем только если новый лимит строго строже текущего
        if ($newMax === null) {
            return null; // новый безлимит — не блок
        }

        if ($currentMax !== null && $newMax >= $currentMax) {
            return null; // новый лимит не строже — не понижение мест
        }

        $providersCount = $ws->providersCount();

        if ($providersCount > $newMax) {
            return "Невозможно понизить тариф: сейчас {$providersCount} провайдеров, а новый тариф даёт мест — {$newMax}. Отключите лишних участников (свитч «Принимаю клиентов»), затем повторите.";
        }

        return null;
    }
}
