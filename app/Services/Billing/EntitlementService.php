<?php

namespace App\Services\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\PaymentAttemptStatus;
use App\Models\BillingCycle;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Read-only Core entitlement reader.
 *
 * Determines workspace entitlement from Billing Core facts
 * (BillingCycle + PaymentAttempt), NOT from BillingSubscription.status
 * or current_period_start/end.
 *
 * A1.2 parity: period_start is intentionally NOT used as lower bound.
 * Granting = cycle grants entitlement AND cycle.period_end > $at.
 */
class EntitlementService
{
    public function currentPlan(Workspace $workspace, ?CarbonInterface $at = null): ?PlanDescriptor
    {
        $at = $at ?? Carbon::now();

        $grantingCycle = $this->bestGrantingCycle($workspace, $at);

        if (! $grantingCycle) {
            return null;
        }

        $plan = $grantingCycle->tariffPlan;

        if (! $plan) {
            return null;
        }

        // Find the latest period_end among all granting cycles for this plan
        $periodEnd = $this->latestGrantingEndForPlan($workspace, $plan->code, $at);

        return PlanDescriptor::fromTariffPlan($plan, $periodEnd);
    }

    public function hasPlan(Workspace $workspace, string $planCode, ?CarbonInterface $at = null): bool
    {
        $plan = $this->currentPlan($workspace, $at);

        return $plan !== null && $plan->code === $planCode;
    }

    public function hasFeature(Workspace $workspace, string $feature, ?CarbonInterface $at = null): bool
    {
        $plan = $this->currentPlan($workspace, $at);

        if (! $plan) {
            return in_array($feature, \App\Support\PlanDefaults::START_FEATURES);
        }

        return in_array($feature, $plan->features);
    }

    public function maxMasters(Workspace $workspace, ?CarbonInterface $at = null): int
    {
        $plan = $this->currentPlan($workspace, $at);

        if (! $plan) {
            return \App\Support\PlanDefaults::START_MAX_MASTERS;
        }

        return $plan->maxMasters;
    }

    public function monthlyLimit(Workspace $workspace, ?CarbonInterface $at = null): ?int
    {
        $plan = $this->currentPlan($workspace, $at);

        if (! $plan) {
            return \App\Support\PlanDefaults::START_MAX_APPOINTMENTS;
        }

        return $plan->monthlyLimit;
    }

    public function entitlementEnd(Workspace $workspace, string $planCode, ?CarbonInterface $at = null): ?CarbonInterface
    {
        $at = $at ?? Carbon::now();

        $plan = $this->currentPlan($workspace, $at);

        if (! $plan || $plan->code !== $planCode) {
            return null;
        }

        return $plan->expiresAt;
    }

    /**
     * Find the best granting cycle for the workspace at time $at.
     *
     * Granting rules:
     * - legacy_grant / admin_grant: cycle.status = paid
     * - payment / renewal: exists PaymentAttempt with status = succeeded
     * - refunded: NEVER grants
     *
     * A1.2 parity: period_start is NOT gated (legacy doesn't gate it either).
     * Only period_end > $at is checked (strict).
     */
    private function bestGrantingCycle(Workspace $workspace, CarbonInterface $at): ?BillingCycle
    {
        $cycles = BillingCycle::query()
            ->where('workspace_id', $workspace->id)
            ->where('period_end', '>', $at)
            ->with('tariffPlan', 'paymentAttempts')
            ->get()
            ->filter(fn (BillingCycle $cycle) => $this->isGranting($cycle));

        if ($cycles->isEmpty()) {
            return null;
        }

        // Pick cycle with highest tariff_plan priority (paid plans > start)
        // and latest period_end
        return $cycles->sortByDesc('period_end')->first();
    }

    private function isGranting(BillingCycle $cycle): bool
    {
        return match ($cycle->origin) {
            BillingCycleOrigin::LegacyGrant, BillingCycleOrigin::AdminGrant
                => $cycle->status === BillingCycleStatus::Paid,

            BillingCycleOrigin::Payment, BillingCycleOrigin::Renewal
                => $cycle->paymentAttempts
                    ->contains(fn ($a) => $a->status === PaymentAttemptStatus::Succeeded),

            default => false,
        };
    }

    private function latestGrantingEndForPlan(Workspace $workspace, string $planCode, CarbonInterface $at): ?CarbonInterface
    {
        $best = BillingCycle::query()
            ->where('workspace_id', $workspace->id)
            ->where('period_end', '>', $at)
            ->whereHas('tariffPlan', fn ($q) => $q->where('code', $planCode))
            ->with('paymentAttempts')
            ->get()
            ->filter(fn (BillingCycle $cycle) => $this->isGranting($cycle))
            ->sortByDesc('period_end')
            ->first();

        return $best?->period_end;
    }
}
