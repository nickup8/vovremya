<?php

namespace App\Services\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\PaymentAttempt;
use App\Models\ProviderEvent;
use App\Models\Subscription;
use App\Models\TariffPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Atomic Billing Core writer for the checkout + webhook chain.
 *
 * Each public method is designed to run INSIDE the caller's transaction.
 * Legacy remains the runtime entitlement authority.
 */
class BillingCoreWriter
{
    private const PROVIDER = 'mock';

    /**
     * Checkout intent: create BillingSubscription + BillingCycle + PaymentAttempt.
     *
     * Called inside the checkout lock / Transaction A.
     * Reuses existing in-flight cycle for same period if one exists.
     */
    public function checkoutCreated(
        Subscription $legacy,
        TariffPlan $plan,
        array $price,
        int $periodMonths,
    ): array {
        $workspaceId = $legacy->workspace_id;

        $existingSub = BillingSubscription::where('workspace_id', $workspaceId)
            ->where('tariff_plan_id', $plan->id)
            ->first();

        if ($existingSub) {
            // Обновляем status если sub не active и нет current granting entitlement
            if ($existingSub->status !== BillingSubscriptionStatus::Active
                && ! $this->workspaceHasGrantingEntitlement($workspaceId)) {
                $existingSub->update(['status' => BillingSubscriptionStatus::PendingInitial]);
            }
            $billingSub = $existingSub;
        } else {
            $hasGranting = $this->workspaceHasGrantingEntitlement($workspaceId);
            $billingSub = BillingSubscription::updateOrCreate(
                ['workspace_id' => $workspaceId, 'tariff_plan_id' => $plan->id],
                ['status' => $hasGranting
                    ? BillingSubscriptionStatus::Active
                    : BillingSubscriptionStatus::PendingInitial,
                ],
            );
        }

        // Проверяем существующий cycle по ТОЧНЫМ period_start/period_end
        // (UNIQUE constraint: billing_subscription_id + period_start + period_end)
        $existingCycle = BillingCycle::where('billing_subscription_id', $billingSub->id)
            ->where('period_start', $legacy->starts_at)
            ->where('period_end', $legacy->expires_at)
            ->first();

        if ($existingCycle) {
            $cycle = $existingCycle;
        } else {
            // Помечаем stale pending cycles как failed (supersede на retry)
            BillingCycle::where('billing_subscription_id', $billingSub->id)
                ->where('status', BillingCycleStatus::Pending)
                ->update(['status' => BillingCycleStatus::Failed]);

            $cycle = BillingCycle::create([
                'billing_subscription_id' => $billingSub->id,
                'workspace_id' => $workspaceId,
                'tariff_plan_id' => $plan->id,
                'period_start' => $legacy->starts_at,
                'period_end' => $legacy->expires_at,
                'status' => BillingCycleStatus::Pending,
                'amount' => $price['final'],
                'currency' => 'RUB',
                'origin' => BillingCycleOrigin::Payment,
                'legacy_subscription_id' => $legacy->id,
                'price_snapshot' => $price,
            ]);
        }

        // Compute attempt_number (MAX + 1 within cycle, under conceptual lock)
        $maxNumber = PaymentAttempt::where('billing_cycle_id', $cycle->id)
            ->max('attempt_number') ?? 0;

        $internalOrderId = 'core_'.Str::random(32);

        $attempt = PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => self::PROVIDER,
            'attempt_number' => $maxNumber + 1,
            'amount' => $price['final'],
            'currency' => 'RUB',
            'internal_order_id' => $internalOrderId,
            'status' => PaymentAttemptStatus::Created,
            'initiated_at' => now(),
            'metadata' => [
                'legacy' => true,
                'legacy_subscription_id' => $legacy->id,
                'period_months' => $periodMonths,
            ],
        ]);

        return compact('billingSub', 'cycle', 'attempt', 'internalOrderId');
    }

    /**
     * Attach gateway response data to the attempt after provider call (Phase C).
     *
     * Atomically writes:
     * - legacy subscription.payment_id
     * - attempt.provider_payment_id
     * - attempt.status = processing
     * - attempt.metadata.checkout_url
     */
    public function paymentAttached(
        string $internalOrderId,
        string $providerPaymentId,
        string $checkoutUrl,
        Subscription $legacy,
    ): void {
        $attempt = PaymentAttempt::where('internal_order_id', $internalOrderId)->firstOrFail();

        $metadata = $attempt->metadata ?? [];
        $metadata['checkout_url'] = $checkoutUrl;

        $attempt->update([
            'provider_payment_id' => $providerPaymentId,
            'status' => PaymentAttemptStatus::Processing,
            'metadata' => $metadata,
        ]);

        $legacy->update([
            'payment_id' => $providerPaymentId,
        ]);
    }

    /**
     * Mark attempt as unknown when gateway call failed but outcome is uncertain.
     */
    public function checkoutFailed(string $internalOrderId): void
    {
        $attempt = PaymentAttempt::where('internal_order_id', $internalOrderId)->first();

        if ($attempt && $attempt->status === PaymentAttemptStatus::Created) {
            $attempt->update(['status' => PaymentAttemptStatus::Unknown]);
        }
    }

    /**
     * Process confirmed webhook success.
     *
     * Called inside a single webhook transaction (with row locks).
     */
    public function paymentSucceeded(string $providerPaymentId): void
    {
        $attempt = $this->lockAttemptByProviderPaymentId($providerPaymentId);
        if (! $attempt || $attempt->status === PaymentAttemptStatus::Succeeded) {
            return; // idempotent
        }

        $attempt->update([
            'status' => PaymentAttemptStatus::Succeeded,
            'finished_at' => now(),
        ]);

        $cycle = $attempt->billingCycle;
        if ($cycle && $cycle->status !== BillingCycleStatus::Paid) {
            $cycle->update(['status' => BillingCycleStatus::Paid]);
        }

        $this->syncBillingSubscriptionHorizon($cycle);
    }

    /**
     * Process confirmed webhook failure.
     */
    public function paymentFailed(string $providerPaymentId): void
    {
        $attempt = $this->lockAttemptByProviderPaymentId($providerPaymentId);
        if (! $attempt) {
            return;
        }

        // Don't overwrite succeeded
        if ($attempt->status === PaymentAttemptStatus::Succeeded) {
            return;
        }

        $attempt->update([
            'status' => PaymentAttemptStatus::FailedTerminal,
            'finished_at' => now(),
        ]);

        $cycle = $attempt->billingCycle;
        if ($cycle) {
            $cycle->update(['status' => BillingCycleStatus::Failed]);
        }
    }

    /**
     * Process confirmed refund.
     */
    public function paymentRefunded(string $providerPaymentId): void
    {
        $attempt = $this->lockAttemptByProviderPaymentId($providerPaymentId);
        if (! $attempt) {
            return;
        }

        if ($attempt->status === PaymentAttemptStatus::Refunded) {
            return; // idempotent
        }

        $attempt->update([
            'status' => PaymentAttemptStatus::Refunded,
            'finished_at' => now(),
        ]);

        $cycle = $attempt->billingCycle;
        if ($cycle) {
            $cycle->update(['status' => BillingCycleStatus::Refunded]);
        }

        $this->recalcBillingSubscriptionAfterRefund($cycle);
    }

    /**
     * Write a ProviderEvent with dedup key to prevent duplicate processing.
     *
     * @return true if new event written, false if duplicate
     */
    public function recordProviderEvent(
        string $paymentId,
        string $status,
        array $payload,
    ): bool {
        $dedupKey = $paymentId.':'.$status;

        $exists = ProviderEvent::where('dedup_key', $dedupKey)->exists();

        if ($exists) {
            return false;
        }

        ProviderEvent::create([
            'provider' => self::PROVIDER,
            'dedup_key' => $dedupKey,
            'event_type' => $status,
            'payload' => $payload,
            'received_at' => now(),
        ]);

        return true;
    }

    /**
     * Find an existing in-flight attempt for the same workspace + plan.
     *
     * In-flight = created, processing, or unknown.
     * Searches by workspace + plan only (NOT by period) — a double-click
     * may produce slightly different period timestamps but the previous
     * payment must still block a new provider call.
     *
     * @see §3 — processing+checkout_url returns existing; otherwise 422
     */
    public function findExistingInFlightAttempt(
        string $workspaceId,
        string $planId,
    ): ?PaymentAttempt {
        $inFlightStatuses = [
            PaymentAttemptStatus::Created,
            PaymentAttemptStatus::Processing,
            PaymentAttemptStatus::Unknown,
        ];

        return PaymentAttempt::whereHas('billingCycle', function ($q) use ($workspaceId, $planId) {
            $q->where('workspace_id', $workspaceId)
                ->where('tariff_plan_id', $planId);
        })
            ->whereIn('status', $inFlightStatuses)
            ->orderByDesc('attempt_number')
            ->first();
    }

    /**
     * Find attempt by internal_order_id (for webhook fallback).
     */
    public function findAttemptByInternalOrderId(string $internalOrderId): ?PaymentAttempt
    {
        return PaymentAttempt::where('internal_order_id', $internalOrderId)->first();
    }

    /**
     * Recover unknown/created/processing attempt from webhook order_id fallback.
     *
     * Attaches provider_payment_id if null, resolves legacy subscription from metadata,
     * and performs normal success/failed transition.
     */
    public function recoverAttemptFromWebhook(
        PaymentAttempt $attempt,
        string $providerPaymentId,
        string $rawStatus,
        ?Subscription $legacySubscription = null,
    ): void {
        // Attach provider_payment_id if missing
        if ($attempt->provider_payment_id === null) {
            $attempt->update(['provider_payment_id' => $providerPaymentId]);
        }

        $metadata = $attempt->metadata ?? [];
        $legacySubId = $metadata['legacy_subscription_id'] ?? null;
        $legacy = $legacySubscription
            ?? ($legacySubId ? Subscription::find($legacySubId) : null);

        if (! $legacy) {
            return;
        }

        // Resolve legacy status from raw
        $parsedStatus = match ($rawStatus) {
            'paid', 'succeeded' => 'active',
            'failed', 'canceled' => 'failed',
            'refunded' => 'refunded',
            default => null,
        };

        if ($parsedStatus && in_array($legacy->status, ['pending', 'active'], true)) {
            $legacy->update(['status' => $parsedStatus]);
            // Ensure payment_id is set
            if (! $legacy->payment_id) {
                $legacy->update(['payment_id' => $providerPaymentId]);
            }
        }

        // Perform core transition
        match ($rawStatus) {
            'paid', 'succeeded' => $this->paymentSucceeded($providerPaymentId),
            'failed', 'canceled' => $this->paymentFailed($providerPaymentId),
            'refunded' => $this->paymentRefunded($providerPaymentId),
            default => null,
        };
    }

    /**
     * Mirror an admin grant / extend into Billing Core.
     *
     * Creates a paid BillingCycle with origin=admin_grant and NO PaymentAttempt.
     * Caller must provide the exact period boundaries:
     * - New grant: period_start = legacy.starts_at, period_end = legacy.expires_at
     * - Extend:    period_start = old_expiry, period_end = new_expiry
     *
     * Called INSIDE the caller's transaction (with workspace lockForUpdate).
     */
    public function adminGrant(
        string $workspaceId,
        TariffPlan $plan,
        Subscription $legacy,
        string $periodStart,
        string $periodEnd,
    ): void {
        // Find or create canonical BillingSubscription
        $existingSub = BillingSubscription::where('workspace_id', $workspaceId)
            ->where('tariff_plan_id', $plan->id)
            ->first();

        $billingSub = $existingSub ?? BillingSubscription::create([
            'workspace_id' => $workspaceId,
            'tariff_plan_id' => $plan->id,
            'status' => BillingSubscriptionStatus::Active,
        ]);

        // Проверяем существующий cycle по ТОЧНЫМ period_start/period_end
        $existingCycle = BillingCycle::where('billing_subscription_id', $billingSub->id)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->first();

        if (! $existingCycle) {
            BillingCycle::create([
                'billing_subscription_id' => $billingSub->id,
                'workspace_id' => $workspaceId,
                'tariff_plan_id' => $plan->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'status' => BillingCycleStatus::Paid,
                'amount' => 0,
                'currency' => 'RUB',
                'origin' => BillingCycleOrigin::AdminGrant,
                'legacy_subscription_id' => $legacy->id,
                'price_snapshot' => ['base' => 0, 'discount_percent' => 0, 'final' => 0],
            ]);
        }

        // Обновляем horizon после admin grant
        $latestCycle = BillingCycle::where('billing_subscription_id', $billingSub->id)
            ->latest('period_end')
            ->first();
        $this->syncBillingSubscriptionHorizon($latestCycle);
    }

    // ── Private helpers ──

    private function lockAttemptByProviderPaymentId(string $providerPaymentId): ?PaymentAttempt
    {
        return PaymentAttempt::lockForUpdate()
            ->where('provider_payment_id', $providerPaymentId)
            ->first();
    }

    /**
     * Check if the workspace already has a granting entitlement in billing core.
     */
    private function workspaceHasGrantingEntitlement(string $workspaceId): bool
    {
        return BillingCycle::where('workspace_id', $workspaceId)
            ->where('period_end', '>', now())
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->whereIn('origin', [BillingCycleOrigin::LegacyGrant, BillingCycleOrigin::AdminGrant])
                        ->where('status', BillingCycleStatus::Paid);
                })->orWhere(function ($q2) {
                    $q2->whereIn('origin', [BillingCycleOrigin::Payment, BillingCycleOrigin::Renewal])
                        ->whereHas('paymentAttempts', function ($q3) {
                            $q3->where('status', PaymentAttemptStatus::Succeeded);
                        });
                });
            })
            ->exists();
    }

    /**
     * Recalculate BillingSubscription horizon from granting cycles.
     */
    private function syncBillingSubscriptionHorizon(?BillingCycle $cycle): void
    {
        if (! $cycle) {
            return;
        }

        $sub = $cycle->billingSubscription;
        if (! $sub) {
            return;
        }

        $latestEnd = BillingCycle::where('billing_subscription_id', $sub->id)
            ->where('status', BillingCycleStatus::Paid)
            ->max('period_end');

        if ($latestEnd) {
            $sub->update([
                'current_period_end' => $latestEnd,
                'status' => BillingSubscriptionStatus::Active,
            ]);
        }
    }

    /**
     * After refund: check if other granting cycles still exist.
     * If yes → keep Active. If no → Canceled.
     */
    private function recalcBillingSubscriptionAfterRefund(?BillingCycle $refundedCycle): void
    {
        if (! $refundedCycle) {
            return;
        }

        $sub = $refundedCycle->billingSubscription;
        if (! $sub) {
            return;
        }

        $hasOtherGranting = BillingCycle::where('billing_subscription_id', $sub->id)
            ->where('id', '!=', $refundedCycle->id)
            ->where('status', BillingCycleStatus::Paid)
            ->exists();

        if (! $hasOtherGranting) {
            $sub->update(['status' => BillingSubscriptionStatus::Canceled]);
        }
    }
}
