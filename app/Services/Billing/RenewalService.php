<?php

namespace App\Services\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Models\PlanPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Internal renewal intent (A2.3b).
 *
 * Prepares a renewal BillingCycle + PaymentAttempt inside a single DB
 * transaction. Does NOT call the bank (no Init/Charge), does NOT add a
 * scheduler, and does NOT mutate the subscription horizon — next_charge_at
 * moves only after a future successful payment via PaymentTransitionService.
 * Core remains the source of truth; no legacy Subscription row is created.
 */
class RenewalService
{
    public function __construct(
        private PlanPriceResolver $priceResolver,
    ) {}

    /**
     * Create (or reuse) the renewal cycle + payment attempt for a due,
     * consented subscription. Returns null without any change when a
     * precondition fails (fail closed).
     */
    public function prepare(BillingSubscription $subscription): ?PaymentAttempt
    {
        return DB::transaction(function () use ($subscription) {
            $sub = BillingSubscription::lockForUpdate()
                ->whereKey($subscription->getKey())
                ->first();

            if ($sub === null || ! $this->isDue($sub)) {
                return null;
            }

            $method = $this->findDefaultPaymentMethod($sub);
            if ($method === null) {
                return null;
            }

            $planPrice = $this->priceResolver->resolve(
                $sub->tariffPlan,
                $sub->renewal_period_months,
                now(),
            );

            if ($planPrice === null) {
                return null;
            }

            $cycle = $this->findOrCreateCycle($sub, $planPrice);

            return $this->findOrCreateAttempt($sub, $cycle, $method);
        });
    }

    /**
     * One safe retry of a technical renewal failure (reconciliation_timeout)
     * while the technical grace window is still open.
     *
     * The old PaymentAttempt is never touched and never Charged again: the
     * retry is a brand-new attempt in the same renewal BillingCycle, which
     * then runs through the ordinary RenewalPaymentExecutor. Fail closed on
     * every precondition — including an already dispatched Charge whose
     * outcome is still unknown; at most one technical retry per cycle
     * (v1 policy).
     */
    public function prepareTechnicalRetry(BillingSubscription $subscription): ?PaymentAttempt
    {
        return DB::transaction(function () use ($subscription) {
            $sub = BillingSubscription::lockForUpdate()
                ->whereKey($subscription->getKey())
                ->first();

            if ($sub === null || ! $this->isTechnicalRetryAllowed($sub)) {
                return null;
            }

            $cycle = BillingCycle::where('billing_subscription_id', $sub->id)
                ->where('origin', BillingCycleOrigin::Renewal)
                ->where('period_start', $sub->current_period_end)
                ->where('status', '!=', BillingCycleStatus::Paid)
                ->first();

            if ($cycle === null) {
                return null;
            }

            $attempts = PaymentAttempt::where('billing_cycle_id', $cycle->id)->get();

            if ($attempts->isEmpty()) {
                return null;
            }

            // v1 policy: exactly one technical retry per renewal cycle.
            $alreadyRetried = $attempts->contains(
                fn (PaymentAttempt $attempt) => ($attempt->metadata['technical_retry'] ?? null) === true
            );

            if ($alreadyRetried) {
                return null;
            }

            if ($attempts->whereIn('status', [
                PaymentAttemptStatus::Created,
                PaymentAttemptStatus::Processing,
                PaymentAttemptStatus::Unknown,
            ])->isNotEmpty()) {
                return null;
            }

            $last = $attempts->sortByDesc('attempt_number')->first();

            if ($last->status !== PaymentAttemptStatus::FailedTerminal
                || $last->failure_category !== 'reconciliation_timeout'
                || ($last->metadata['renewal'] ?? null) !== true) {
                return null;
            }

            // reconciliation_timeout is set by age-release (which never asks
            // the provider) — so for a dispatched Charge it means the bank
            // answer is unknown, not that nothing was charged. A single
            // GetState NEW would not prove otherwise either. Creating a
            // payment now could double-charge: fail closed and leave the
            // outcome to the existing recovery (reconciliation polling /
            // late success). The marker is never cleared and the old
            // attempt is never Charged again.
            if (($last->metadata['charge_dispatch_started_at'] ?? null) !== null) {
                return null;
            }

            $method = $this->findDefaultPaymentMethod($sub);
            if ($method === null) {
                return null;
            }

            if ($cycle->status === BillingCycleStatus::Failed) {
                $cycle->update(['status' => BillingCycleStatus::Pending]);
            }

            return PaymentAttempt::create([
                'billing_cycle_id' => $cycle->id,
                'payment_method_id' => $method->id,
                'provider' => $method->provider,
                'attempt_number' => (int) $attempts->max('attempt_number') + 1,
                'amount' => $cycle->amount,
                'currency' => $cycle->currency,
                'internal_order_id' => 'renew_'.Str::random(32),
                'provider_payment_id' => null,
                'status' => PaymentAttemptStatus::Created,
                'initiated_at' => now(),
                'metadata' => [
                    'renewal' => true,
                    'billing_subscription_id' => $sub->id,
                    'technical_retry' => true,
                    'retry_of_attempt_id' => $last->id,
                ],
            ]);
        });
    }

    /**
     * The already-created technical retry attempt of the current renewal
     * cycle, when it is in a state RenewalPaymentExecutor can progress.
     *
     * Lets a rerun continue where the previous run stopped (crash after
     * prepare, CheckOrder/Init exception, in-flight Charge) instead of
     * reporting Skipped forever. Never creates an attempt, never touches
     * the original failed attempt: the one-technical-retry-per-cycle limit
     * stays with prepareTechnicalRetry(). Unsupported or terminal states
     * return null so the command keeps counting them as Skipped.
     */
    public function resumeTechnicalRetry(BillingSubscription $subscription): ?PaymentAttempt
    {
        if ($subscription->current_period_end === null) {
            return null;
        }

        $cycle = BillingCycle::where('billing_subscription_id', $subscription->getKey())
            ->where('origin', BillingCycleOrigin::Renewal)
            ->where('period_start', $subscription->current_period_end)
            ->where('status', '!=', BillingCycleStatus::Paid)
            ->first();

        if ($cycle === null) {
            return null;
        }

        $resumable = PaymentAttempt::where('billing_cycle_id', $cycle->id)
            ->whereIn('status', [
                PaymentAttemptStatus::Created,
                PaymentAttemptStatus::Processing,
            ])
            ->orderByDesc('attempt_number')
            ->get()
            ->first(fn (PaymentAttempt $attempt) => ($attempt->metadata['technical_retry'] ?? null) === true);

        // Loading is enough: the executor owns idempotency for the attempt
        // it receives (charge_dispatch_started_at, atomic attach, state
        // machine), so no lock is needed here and nothing is written.
        return $resumable;
    }

    private function isTechnicalRetryAllowed(BillingSubscription $sub): bool
    {
        if ($sub->status !== BillingSubscriptionStatus::PastDue
            || $sub->grace_until === null
            || ! $sub->grace_until->isFuture()
            || $sub->auto_renew_consent_at === null
            || $sub->renewal_period_months === null
            || $sub->cancel_at_period_end !== false
            || $sub->current_period_end === null) {
            return false;
        }

        return true;
    }

    private function isDue(BillingSubscription $sub): bool
    {
        if ($sub->status !== BillingSubscriptionStatus::Active) {
            return false;
        }

        if ($sub->auto_renew_consent_at === null
            || $sub->renewal_period_months === null
            || $sub->cancel_at_period_end !== false
            || $sub->current_period_end === null
            || $sub->next_charge_at === null) {
            return false;
        }

        return ! $sub->next_charge_at->isFuture();
    }

    private function findDefaultPaymentMethod(BillingSubscription $sub): ?PaymentMethod
    {
        return PaymentMethod::where('workspace_id', $sub->workspace_id)
            ->where('provider', 'tbank')
            ->where('type', 'card')
            ->where('status', 'active')
            ->where('is_default', true)
            ->whereNotNull('provider_reference')
            ->first();
    }

    private function findOrCreateCycle(BillingSubscription $sub, PlanPrice $planPrice): BillingCycle
    {
        $periodStart = $sub->current_period_end->copy();
        $periodEnd = $periodStart->copy()->addMonths($sub->renewal_period_months);

        // UNIQUE (billing_subscription_id, period_start, period_end) —
        // reuse an existing renewal cycle instead of duplicating it.
        $existing = BillingCycle::where('billing_subscription_id', $sub->id)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $sub->workspace_id,
            'tariff_plan_id' => $sub->tariff_plan_id,
            'plan_price_id' => $planPrice->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => BillingCycleStatus::Pending,
            'amount' => $planPrice->final_amount,
            'currency' => $planPrice->currency,
            'origin' => BillingCycleOrigin::Renewal,
            'price_snapshot' => $this->priceResolver->snapshot($planPrice),
        ]);
    }

    private function findOrCreateAttempt(
        BillingSubscription $sub,
        BillingCycle $cycle,
        PaymentMethod $method,
    ): ?PaymentAttempt {
        $inFlight = PaymentAttempt::where('billing_cycle_id', $cycle->id)
            ->whereIn('status', [
                PaymentAttemptStatus::Created,
                PaymentAttemptStatus::Processing,
                PaymentAttemptStatus::Unknown,
            ])
            ->orderByDesc('attempt_number')
            ->first();

        if ($inFlight !== null) {
            return $inFlight;
        }

        if ($cycle->status === BillingCycleStatus::Paid) {
            return null;
        }

        $maxNumber = PaymentAttempt::where('billing_cycle_id', $cycle->id)
            ->max('attempt_number') ?? 0;

        return PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'payment_method_id' => $method->id,
            'provider' => $method->provider,
            'attempt_number' => $maxNumber + 1,
            'amount' => $cycle->amount,
            'currency' => $cycle->currency,
            'internal_order_id' => 'renew_'.Str::random(32),
            'provider_payment_id' => null,
            'status' => PaymentAttemptStatus::Created,
            'initiated_at' => now(),
            'metadata' => [
                'renewal' => true,
                'billing_subscription_id' => $sub->id,
            ],
        ]);
    }
}
