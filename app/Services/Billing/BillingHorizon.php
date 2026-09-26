<?php

namespace App\Services\Billing;

use App\Models\BillingCycle;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Single source of truth for computing the Core entitlement horizon.
 *
 * Used by:
 * - BillingService::subscribe() (checkout stacking)
 * - SuperAdminController::extendSubscription() (admin grant)
 * - CheckSubscriptionExpirations (reminder/expire)
 *
 * The horizon determines period_start and period_end for new cycles.
 *
 * Rules:
 * - For the same plan: period_start = max(now, existing granting horizon end)
 * - If no existing granting entitlement: period_start = now
 * - period_end = period_start + period
 */
class BillingHorizon
{
    /**
     * Get the latest granting end for a workspace + plan combination.
     * Returns null if no active granting cycle exists.
     */
    public function currentGrantingEnd(Workspace $workspace, string $planCode, ?CarbonInterface $at = null): ?CarbonInterface
    {
        $at = $at ?? Carbon::now();

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

    /**
     * Get the latest granting end for ANY plan in the workspace.
     * Used for extendSubscription when the workspace may not have a specific plan.
     */
    public function anyGrantingEnd(Workspace $workspace, ?CarbonInterface $at = null): ?CarbonInterface
    {
        $at = $at ?? Carbon::now();

        $best = BillingCycle::query()
            ->where('workspace_id', $workspace->id)
            ->where('period_end', '>', $at)
            ->with('paymentAttempts')
            ->get()
            ->filter(fn (BillingCycle $cycle) => $this->isGranting($cycle))
            ->sortByDesc('period_end')
            ->first();

        return $best?->period_end;
    }

    /**
     * Compute period boundaries for a new cycle, given existing Core horizon.
     *
     * For same-plan stacking:
     *   period_start = max(now, existing horizon end)
     *   period_end = period_start + periodMonths
     *
     * For no existing entitlement:
     *   period_start = now
     *   period_end = now + periodMonths
     */
    public function computeNewPeriod(
        Workspace $workspace,
        string $planCode,
        int $periodMonths,
        ?CarbonInterface $at = null,
    ): array {
        $at = $at ?? Carbon::now();
        $horizonEnd = $this->currentGrantingEnd($workspace, $planCode, $at);

        $periodStart = $horizonEnd && $horizonEnd->isAfter($at)
            ? $horizonEnd->copy()
            : $at->copy();

        $periodEnd = $periodStart->copy()->addMonths($periodMonths);

        return [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ];
    }

    private function isGranting(BillingCycle $cycle): bool
    {
        return match ($cycle->origin) {
            \App\Enums\BillingCycleOrigin::LegacyGrant,
            \App\Enums\BillingCycleOrigin::AdminGrant
                => $cycle->status === \App\Enums\BillingCycleStatus::Paid,

            \App\Enums\BillingCycleOrigin::Payment,
            \App\Enums\BillingCycleOrigin::Renewal
                => $cycle->paymentAttempts
                    ->contains(fn ($a) => $a->status === \App\Enums\PaymentAttemptStatus::Succeeded),

            default => false,
        };
    }
}
