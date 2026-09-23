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
     * Create BillingSubscription + BillingCycle + PaymentAttempt for a new checkout intent.
     *
     * Called inside Transaction A (before gateway call).
     */
    public function checkoutCreated(
        Subscription $legacy,
        TariffPlan $plan,
        array $price,
        int $periodMonths,
    ): array {
        $workspaceId = $legacy->workspace_id;

        // Upsert BillingSubscription by workspace + plan
        $billingSub = BillingSubscription::updateOrCreate(
            ['workspace_id' => $workspaceId, 'tariff_plan_id' => $plan->id],
            ['status' => BillingSubscriptionStatus::Active],
        );

        // Mark stale pending cycles as failed (supersede on retry)
        BillingCycle::where('billing_subscription_id', $billingSub->id)
            ->where('status', BillingCycleStatus::Pending)
            ->update(['status' => BillingCycleStatus::Failed]);

        // Create BillingCycle — period exactly matches legacy
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

        // Compute attempt_number under conceptual lock (cycle is new in this txn)
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
     * Attach gateway payment_id to the attempt after provider call.
     *
     * Called inside Transaction B (after gateway).
     */
    public function paymentAttached(string $internalOrderId, string $providerPaymentId): void
    {
        $attempt = PaymentAttempt::where('internal_order_id', $internalOrderId)->firstOrFail();

        $attempt->update([
            'provider_payment_id' => $providerPaymentId,
            'status' => PaymentAttemptStatus::Processing,
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
        $dedupKey = $paymentId . ':' . $status;

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

    // ── Private helpers ──

    private function lockAttemptByProviderPaymentId(string $providerPaymentId): ?PaymentAttempt
    {
        return PaymentAttempt::lockForUpdate()
            ->where('provider_payment_id', $providerPaymentId)
            ->first();
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
