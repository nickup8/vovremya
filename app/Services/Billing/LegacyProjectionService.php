<?php

namespace App\Services\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\PaymentAttempt;
use App\Models\Subscription;
use App\Models\TariffPlan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LegacyProjectionService
{
    public function projectAll(bool $dryRun = false): array
    {
        $subscriptions = Subscription::query()
            ->with(['tariffPlan'])
            ->whereNotNull('tariff_plan_id')
            ->get();

        $grouped = $subscriptions->groupBy(fn ($s) => "{$s->workspace_id}|{$s->tariff_plan_id}");

        $stats = [
            'workspaces_found' => $subscriptions->pluck('workspace_id')->unique()->count(),
            'canonical_subscriptions_to_create' => 0,
            'billing_cycles_to_create' => 0,
            'payment_attempts_to_create' => 0,
            'admin_grants' => 0,
            'ambiguous_rows' => 0,
            'skipped_workspaces' => [],
        ];

        DB::transaction(function () use ($grouped, $dryRun, &$stats) {
            foreach ($grouped as $key => $group) {
                [$workspaceId, $planId] = explode('|', $key);

                $proResult = $this->projectWorkspaceGroup(
                    $workspaceId,
                    $planId,
                    $group,
                    $dryRun,
                );

                $stats['canonical_subscriptions_to_create'] += $proResult['created_sub'] ? 1 : 0;
                $stats['billing_cycles_to_create'] += $proResult['cycles'];
                $stats['payment_attempts_to_create'] += $proResult['attempts'];
                $stats['admin_grants'] += $proResult['admin_grants'];
                $stats['ambiguous_rows'] += $proResult['ambiguous'];

                if ($proResult['skipped']) {
                    $stats['skipped_workspaces'][] = $workspaceId;
                }
            }
        });

        return $stats;
    }

    private function projectWorkspaceGroup(
        string $workspaceId,
        string $planId,
        Collection $rows,
        bool $dryRun,
    ): array {
        $result = [
            'created_sub' => false,
            'cycles' => 0,
            'attempts' => 0,
            'admin_grants' => 0,
            'ambiguous' => 0,
            'skipped' => false,
        ];

        $plan = TariffPlan::find($planId);
        if (! $plan) {
            $result['skipped'] = true;
            return $result;
        }

        // Skip free/start plans — no canonical billing subscription for free tier
        if ($plan->price_monthly === 0 && $plan->code === 'start') {
            $result['skipped'] = true;
            return $result;
        }

        // Find or create canonical BillingSubscription
        $billingSub = BillingSubscription::firstOrCreate(
            [
                'workspace_id' => $workspaceId,
                'tariff_plan_id' => $planId,
            ],
            [
                'status' => BillingSubscriptionStatus::PendingInitial,
            ],
        );
        $result['created_sub'] = true;

        // Group rows by billing period
        $periodGroups = $rows->groupBy(fn ($s) => "{$s->starts_at}|{$s->expires_at}");

        // Track entitlement horizon
        $entitlementEnd = null;

        foreach ($periodGroups as $periodKey => $periodRows) {
            [$startsAt, $expiresAt] = explode('|', $periodKey);

            $cycleResult = $this->projectBillingCycle(
                $billingSub,
                $workspaceId,
                $planId,
                $periodRows,
                $startsAt,
                $expiresAt,
                $dryRun,
            );

            $result['cycles'] += 1;
            $result['attempts'] += $cycleResult['attempts'];
            $result['admin_grants'] += $cycleResult['admin_grant'] ? 1 : 0;
            $result['ambiguous'] += $cycleResult['ambiguous'];

            // Track entitlement: successful/grant periods extend the horizon
            if ($cycleResult['extends_entitlement'] && $entitlementEnd === null) {
                $entitlementEnd = $expiresAt;
            } elseif ($cycleResult['extends_entitlement'] && $entitlementEnd !== null) {
                $entitlementEnd = max($entitlementEnd, $expiresAt);
            }
        }

        // Update canonical subscription status from entitlement reality
        if ($entitlementEnd !== null && strtotime($entitlementEnd) > time()) {
            $billingSub->update([
                'status' => BillingSubscriptionStatus::Active,
                'current_period_start' => $rows->min('starts_at'),
                'current_period_end' => $entitlementEnd,
            ]);
        } elseif ($entitlementEnd !== null) {
            $billingSub->update([
                'status' => BillingSubscriptionStatus::Expired,
                'current_period_start' => $rows->min('starts_at'),
                'current_period_end' => $entitlementEnd,
            ]);
        } else {
            $billingSub->update([
                'status' => BillingSubscriptionStatus::Expired,
            ]);
        }

        return $result;
    }

    private function projectBillingCycle(
        BillingSubscription $billingSub,
        string $workspaceId,
        string $planId,
        Collection $rows,
        string $startsAt,
        string $expiresAt,
        bool $dryRun,
    ): array {
        $result = [
            'attempts' => 0,
            'admin_grant' => false,
            'ambiguous' => false,
            'extends_entitlement' => false,
        ];

        // Determine cycle status and origin from the group of rows
        $hasPaymentId = $rows->whereNotNull('payment_id')->isNotEmpty();
        $hasAmount = $rows->where('amount_paid', '>', 0)->isNotEmpty();
        $allFailed = $rows->every('status', 'failed');
        $anyActive = $rows->contains('status', 'active');

        // Determine origin
        if (! $hasPaymentId && ! $hasAmount) {
            $origin = BillingCycleOrigin::LegacyGrant;
            $result['admin_grant'] = true;
            $result['extends_entitlement'] = true;
        } elseif ($hasAmount || $hasPaymentId) {
            $origin = BillingCycleOrigin::Payment;
            $result['extends_entitlement'] = $anyActive || (! $allFailed && $hasAmount);
        } else {
            $origin = BillingCycleOrigin::Payment;
        }

        // Determine cycle status
        if ($anyActive) {
            $cycleStatus = BillingCycleStatus::Paid;
        } elseif ($allFailed) {
            $cycleStatus = BillingCycleStatus::Failed;
        } else {
            $cycleStatus = BillingCycleStatus::Paid;
        }

        // Use the max amount from the group as the cycle amount
        $amount = $rows->max('amount_paid') ?? 0;

        // Check for ambiguous rows (mixed success/failure in same period)
        if ($anyActive && $allFailed) {
            $result['ambiguous'] = true;
        }

        $billingCycle = BillingCycle::firstOrCreate(
            [
                'billing_subscription_id' => $billingSub->id,
                'period_start' => $startsAt,
                'period_end' => $expiresAt,
            ],
            [
                'workspace_id' => $workspaceId,
                'tariff_plan_id' => $planId,
                'status' => $cycleStatus,
                'amount' => $amount,
                'currency' => 'RUB',
                'origin' => $origin,
                'price_snapshot' => $this->buildPriceSnapshot($rows, $amount),
            ],
        );

        // Create PaymentAttempts for rows with payment_id
        foreach ($rows as $row) {
            if (! $row->payment_id) {
                continue;
            }

            $attemptStatus = match ($row->status) {
                'active' => PaymentAttemptStatus::Succeeded,
                'failed' => PaymentAttemptStatus::FailedTerminal,
                'refunded' => PaymentAttemptStatus::Refunded,
                default => PaymentAttemptStatus::Unknown,
            };

            PaymentAttempt::firstOrCreate(
                [
                    'internal_order_id' => $row->payment_id,
                ],
                [
                    'billing_cycle_id' => $billingCycle->id,
                    'provider' => 'mock',
                    'attempt_number' => 1,
                    'amount' => $row->amount_paid,
                    'currency' => 'RUB',
                    'status' => $attemptStatus,
                    'provider_payment_id' => $row->payment_id,
                    'initiated_at' => $row->created_at,
                    'finished_at' => $row->updated_at,
                    'metadata' => [
                        'legacy_subscription_id' => $row->id,
                    ],
                ],
            );

            $result['attempts']++;
        }

        return $result;
    }

    private function buildPriceSnapshot(Collection $rows, int $amount): array
    {
        return [
            'amount_paid' => $amount,
            'source' => 'legacy_projection',
            'row_count' => $rows->count(),
        ];
    }
}
