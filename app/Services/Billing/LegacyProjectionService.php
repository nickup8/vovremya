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
    /**
     * Compute the full projection plan without writing anything.
     *
     * Returns a plan with two sections:
     *   - found: what exists in legacy
     *   - to_create: what's missing in billing core
     *   - already_projected: what already exists
     */
    public function computePlan(): array
    {
        $subscriptions = $this->loadLegacySubscriptions();

        $workspaceIds = $subscriptions->pluck('workspace_id')->unique();
        $periodGroups = $this->groupByPeriod($subscriptions);
        $paymentRows = $subscriptions->filter(fn ($s) => $s->payment_id !== null);
        $grantRows = $subscriptions->filter(fn ($s) => $s->amount_paid === 0 && $s->payment_id === null);

        $plan = [
            'found' => [
                'workspaces' => $workspaceIds->count(),
                'legacy_period_groups' => $periodGroups->count(),
                'legacy_payment_rows' => $paymentRows->count(),
                'legacy_zero_amount_grants' => $grantRows->count(),
            ],
            'to_create' => [
                'canonical_subscriptions' => 0,
                'billing_cycles' => 0,
                'payment_attempts' => 0,
            ],
            'already_projected' => [
                'canonical_subscriptions' => 0,
                'billing_cycles' => 0,
                'payment_attempts' => 0,
            ],
            'ambiguous_groups' => [],
            'skipped_workspaces' => [],
        ];

        $grouped = $subscriptions->groupBy(fn ($s) => "{$s->workspace_id}|{$s->tariff_plan_id}");

        foreach ($grouped as $key => $group) {
            [$workspaceId, $planId] = explode('|', $key);

            $planResult = $this->computeWorkspacePlan($workspaceId, $planId, $group, $periodGroups);

            $plan['to_create']['canonical_subscriptions'] += $planResult['sub_created'];
            $plan['to_create']['billing_cycles'] += $planResult['cycles_created'];
            $plan['to_create']['payment_attempts'] += $planResult['attempts_created'];

            $plan['already_projected']['canonical_subscriptions'] += $planResult['sub_existing'];
            $plan['already_projected']['billing_cycles'] += $planResult['cycles_existing'];
            $plan['already_projected']['payment_attempts'] += $planResult['attempts_existing'];

            if ($planResult['skipped']) {
                $plan['skipped_workspaces'][] = $workspaceId;
            }

            $plan['ambiguous_groups'] = array_merge($plan['ambiguous_groups'], $planResult['ambiguous_groups']);
        }

        return $plan;
    }

    /**
     * Execute the projection. In dry-run mode, zero DB writes occur.
     */
    public function projectAll(bool $dryRun = false): array
    {
        if ($dryRun) {
            return $this->computePlan();
        }

        return $this->executeProjection();
    }

    private function executeProjection(): array
    {
        $subscriptions = $this->loadLegacySubscriptions();

        $stats = [
            'found' => [
                'workspaces' => $subscriptions->pluck('workspace_id')->unique()->count(),
                'legacy_period_groups' => 0,
                'legacy_payment_rows' => $subscriptions->filter(fn ($s) => $s->payment_id !== null)->count(),
                'legacy_zero_amount_grants' => $subscriptions->filter(fn ($s) => $s->amount_paid === 0 && $s->payment_id === null)->count(),
            ],
            'created' => [
                'canonical_subscriptions' => 0,
                'billing_cycles' => 0,
                'payment_attempts' => 0,
            ],
            'already_projected' => [
                'canonical_subscriptions' => 0,
                'billing_cycles' => 0,
                'payment_attempts' => 0,
            ],
            'ambiguous_groups' => [],
            'skipped_workspaces' => [],
        ];

        $periodGroups = $this->groupByPeriod($subscriptions);
        $stats['found']['legacy_period_groups'] = $periodGroups->count();

        $grouped = $subscriptions->groupBy(fn ($s) => "{$s->workspace_id}|{$s->tariff_plan_id}");

        DB::transaction(function () use ($grouped, &$stats) {
            foreach ($grouped as $key => $group) {
                [$workspaceId, $planId] = explode('|', $key);

                $result = $this->executeWorkspaceProjection($workspaceId, $planId, $group);

                $stats['created']['canonical_subscriptions'] += $result['sub_created'];
                $stats['created']['billing_cycles'] += $result['cycles_created'];
                $stats['created']['payment_attempts'] += $result['attempts_created'];

                $stats['already_projected']['canonical_subscriptions'] += $result['sub_existing'];
                $stats['already_projected']['billing_cycles'] += $result['cycles_existing'];
                $stats['already_projected']['payment_attempts'] += $result['attempts_existing'];

                if ($result['skipped']) {
                    $stats['skipped_workspaces'][] = $workspaceId;
                }

                $stats['ambiguous_groups'] = array_merge($stats['ambiguous_groups'], $result['ambiguous_groups']);
            }
        });

        return $stats;
    }

    // ── Plan computation (read-only) ──

    private function computeWorkspacePlan(
        string $workspaceId,
        string $planId,
        Collection $rows,
        Collection $allPeriodGroups,
    ): array {
        $result = $this->emptyResult();

        $plan = TariffPlan::find($planId);
        if (! $plan || ($plan->price_monthly === 0 && $plan->code === 'start')) {
            $result['skipped'] = true;
            return $result;
        }

        $existingSub = BillingSubscription::where('workspace_id', $workspaceId)
            ->where('tariff_plan_id', $planId)
            ->first();

        if ($existingSub) {
            $result['sub_existing'] = 1;
        } else {
            $result['sub_created'] = 1;
        }

        $billingSubId = $existingSub?->id;

        $periodGroups = $rows->groupBy(fn ($s) => "{$s->starts_at}|{$s->expires_at}");

        foreach ($periodGroups as $periodKey => $periodRows) {
            [$startsAt, $expiresAt] = explode('|', $periodKey);

            $existingCycle = $billingSubId
                ? BillingCycle::where('billing_subscription_id', $billingSubId)
                    ->where('period_start', $startsAt)
                    ->where('period_end', $expiresAt)
                    ->first()
                : null;

            if ($existingCycle) {
                $result['cycles_existing'] += 1;
            } else {
                $result['cycles_created'] += 1;
            }

            $paymentRows = $periodRows->filter(fn ($s) => $s->payment_id !== null);

            foreach ($paymentRows as $row) {
                $existingAttempt = PaymentAttempt::where('internal_order_id', $row->payment_id)->first();
                if ($existingAttempt) {
                    $result['attempts_existing'] += 1;
                } else {
                    $result['attempts_created'] += 1;
                }
            }

            $ambiguity = $this->detectAmbiguity($periodRows);
            if ($ambiguity) {
                $result['ambiguous_groups'][] = $ambiguity;
            }
        }

        return $result;
    }

    // ── Actual projection (writes) ──

    private function executeWorkspaceProjection(
        string $workspaceId,
        string $planId,
        Collection $rows,
    ): array {
        $result = $this->emptyResult();

        $plan = TariffPlan::find($planId);
        if (! $plan || ($plan->price_monthly === 0 && $plan->code === 'start')) {
            $result['skipped'] = true;
            return $result;
        }

        $existingSub = BillingSubscription::where('workspace_id', $workspaceId)
            ->where('tariff_plan_id', $planId)
            ->first();

        if ($existingSub) {
            $result['sub_existing'] = 1;
        } else {
            $existingSub = BillingSubscription::create([
                'workspace_id' => $workspaceId,
                'tariff_plan_id' => $planId,
                'status' => BillingSubscriptionStatus::PendingInitial,
            ]);
            $result['sub_created'] = 1;
        }

        $periodGroups = $rows->groupBy(fn ($s) => "{$s->starts_at}|{$s->expires_at}");
        $entitlementEnd = null;

        foreach ($periodGroups as $periodKey => $periodRows) {
            [$startsAt, $expiresAt] = explode('|', $periodKey);

            $ambiguity = $this->detectAmbiguity($periodRows);
            if ($ambiguity) {
                $result['ambiguous_groups'][] = $ambiguity;
                continue;
            }

            $cycleResult = $this->executeCycleProjection(
                $existingSub,
                $workspaceId,
                $planId,
                $periodRows,
                $startsAt,
                $expiresAt,
            );

            $result['cycles_created'] += $cycleResult['cycle_created'] ? 1 : 0;
            $result['cycles_existing'] += $cycleResult['cycle_created'] ? 0 : 1;
            $result['attempts_created'] += $cycleResult['attempts_created'];
            $result['attempts_existing'] += $cycleResult['attempts_existing'];

            if ($cycleResult['extends_entitlement'] && ($entitlementEnd === null || $expiresAt > $entitlementEnd)) {
                $entitlementEnd = $expiresAt;
            }
        }

        // Update canonical subscription status
        $subUpdate = [];
        if ($entitlementEnd !== null && strtotime($entitlementEnd) > time()) {
            $subUpdate['status'] = BillingSubscriptionStatus::Active;
        } elseif ($entitlementEnd !== null) {
            $subUpdate['status'] = BillingSubscriptionStatus::Expired;
        } else {
            $subUpdate['status'] = BillingSubscriptionStatus::Expired;
        }

        if ($entitlementEnd !== null) {
            $subUpdate['current_period_start'] = $rows->min('starts_at');
            $subUpdate['current_period_end'] = $entitlementEnd;
        }

        $existingSub->update($subUpdate);

        return $result;
    }

    private function executeCycleProjection(
        BillingSubscription $billingSub,
        string $workspaceId,
        string $planId,
        Collection $rows,
        string $startsAt,
        string $expiresAt,
    ): array {
        $result = [
            'cycle_created' => false,
            'attempts_created' => 0,
            'attempts_existing' => 0,
            'extends_entitlement' => false,
        ];

        $hasPaymentId = $rows->whereNotNull('payment_id')->isNotEmpty();
        $hasAmount = $rows->where('amount_paid', '>', 0)->isNotEmpty();
        $allFailed = $rows->every('status', 'failed');
        $anyActive = $rows->contains('status', 'active');

        // Determine origin
        if (! $hasPaymentId && ! $hasAmount) {
            $origin = BillingCycleOrigin::LegacyGrant;
            $result['extends_entitlement'] = true;
        } else {
            $origin = BillingCycleOrigin::Payment;
            $result['extends_entitlement'] = $anyActive;
        }

        // Determine cycle status
        if ($anyActive) {
            $cycleStatus = BillingCycleStatus::Paid;
        } elseif ($allFailed) {
            $cycleStatus = BillingCycleStatus::Failed;
        } else {
            $cycleStatus = BillingCycleStatus::Paid;
        }

        $amount = $rows->max('amount_paid') ?? 0;

        // Find or create cycle
        $existingCycle = BillingCycle::where('billing_subscription_id', $billingSub->id)
            ->where('period_start', $startsAt)
            ->where('period_end', $expiresAt)
            ->first();

        if ($existingCycle) {
            $billingCycle = $existingCycle;
        } else {
            $billingCycle = BillingCycle::create([
                'billing_subscription_id' => $billingSub->id,
                'workspace_id' => $workspaceId,
                'tariff_plan_id' => $planId,
                'period_start' => $startsAt,
                'period_end' => $expiresAt,
                'status' => $cycleStatus,
                'amount' => $amount,
                'currency' => 'RUB',
                'origin' => $origin,
                'price_snapshot' => $this->buildPriceSnapshot($rows, $amount),
            ]);
            $result['cycle_created'] = true;
        }

        // Create payment attempts for rows with payment_id, with deterministic numbering
        $paymentRows = $rows->filter(fn ($s) => $s->payment_id !== null)
            ->sortBy(fn ($s) => [$s->created_at, $s->id]);

        $attemptNumber = 0;
        foreach ($paymentRows as $row) {
            $attemptNumber++;

            $existingAttempt = PaymentAttempt::where('internal_order_id', $row->payment_id)->first();
            if ($existingAttempt) {
                $result['attempts_existing']++;
                continue;
            }

            $attemptStatus = match ($row->status) {
                'active' => PaymentAttemptStatus::Succeeded,
                // Legacy failed rows: we don't have failure_code/category/message,
                // so we map to Unknown, not FailedTerminal.
                'failed' => PaymentAttemptStatus::Unknown,
                'refunded' => PaymentAttemptStatus::Refunded,
                default => PaymentAttemptStatus::Unknown,
            };

            PaymentAttempt::create([
                'billing_cycle_id' => $billingCycle->id,
                'provider' => 'mock',
                'attempt_number' => $attemptNumber,
                'amount' => $row->amount_paid,
                'currency' => 'RUB',
                'internal_order_id' => $row->payment_id,
                'provider_payment_id' => $row->payment_id,
                'status' => $attemptStatus,
                'initiated_at' => $row->created_at,
                'finished_at' => $row->updated_at,
                'metadata' => [
                    'legacy' => true,
                    'legacy_subscription_id' => $row->id,
                    'failure_source' => $row->status === 'failed' ? 'legacy_unknown' : null,
                ],
            ]);

            $result['attempts_created']++;
        }

        return $result;
    }

    // ── Ambiguity detection ──

    private function detectAmbiguity(Collection $rows): ?string
    {
        // Multiple successful/active rows in same period = ambiguous
        $activeRows = $rows->filter(fn ($s) => $s->status === 'active');
        if ($activeRows->count() > 1) {
            return "Multiple active rows in period ({$rows->first()->starts_at} → {$rows->first()->expires_at}): {$activeRows->count()} active rows";
        }

        // Inconsistent non-zero amounts within same period = ambiguous
        $nonZeroAmounts = $rows->where('amount_paid', '>', 0)->pluck('amount_paid')->unique();
        if ($nonZeroAmounts->count() > 1) {
            return "Inconsistent amounts in period ({$rows->first()->starts_at} → {$rows->first()->expires_at}): ".implode('/', $nonZeroAmounts->toArray());
        }

        return null;
    }

    // ── Helpers ──

    private function loadLegacySubscriptions(): Collection
    {
        return Subscription::query()
            ->with(['tariffPlan'])
            ->whereNotNull('tariff_plan_id')
            ->get();
    }

    private function groupByPeriod(Collection $subscriptions): Collection
    {
        return $subscriptions->groupBy(fn ($s) => "{$s->workspace_id}|{$s->tariff_plan_id}|{$s->starts_at}|{$s->expires_at}");
    }

    private function buildPriceSnapshot(Collection $rows, int $amount): array
    {
        return [
            'amount_paid' => $amount,
            'source' => 'legacy_projection',
            'row_count' => $rows->count(),
        ];
    }

    private function emptyResult(): array
    {
        return [
            'sub_created' => 0,
            'sub_existing' => 0,
            'cycles_created' => 0,
            'cycles_existing' => 0,
            'attempts_created' => 0,
            'attempts_existing' => 0,
            'ambiguous_groups' => [],
            'skipped' => false,
        ];
    }
}
