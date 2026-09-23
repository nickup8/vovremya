<?php

namespace App\Services\Billing;

use App\Enums\PaymentAttemptStatus;
use App\Models\BillingCycle;
use App\Models\PaymentAttempt;
use Illuminate\Support\Collection;

class LegacyProjectionRepairService
{
    /**
     * Compute what repair would do, without writing anything.
     */
    public function computeRepairPlan(): array
    {
        $cycles = BillingCycle::with('paymentAttempts')->get();

        $plan = [
            'cycles_scanned' => $cycles->count(),
            'attempts_scanned' => 0,
            'numbering_changes' => [],
            'status_changes' => [],
            'metadata_enrichments' => [],
            'cycles_unchanged' => 0,
        ];

        foreach ($cycles as $cycle) {
            $legacyAttempts = $this->getLegacyAttempts($cycle);
            $plan['attempts_scanned'] += $legacyAttempts->count();

            if ($legacyAttempts->isEmpty()) {
                $plan['cycles_unchanged']++;
                continue;
            }

            $cycleChanged = false;

            // Check numbering
            $sortedAttempts = $this->sortLegacyAttempts($legacyAttempts);
            $numberingOk = true;
            foreach ($sortedAttempts->values() as $idx => $attempt) {
                $expectedNumber = $idx + 1;
                if ($attempt->attempt_number !== $expectedNumber) {
                    $numberingOk = false;
                    break;
                }
            }

            if (! $numberingOk) {
                $plan['numbering_changes'][] = [
                    'cycle_id' => $cycle->id,
                    'period' => "{$cycle->period_start} → {$cycle->period_end}",
                    'current_numbers' => $legacyAttempts->pluck('attempt_number')->toArray(),
                    'desired_numbers' => range(1, $legacyAttempts->count()),
                ];
                $cycleChanged = true;
            }

            // Check status fixes
            foreach ($legacyAttempts as $attempt) {
                if ($attempt->status === PaymentAttemptStatus::FailedTerminal
                    && $attempt->failure_code === null
                    && $attempt->failure_category === null
                    && $attempt->failure_message === null
                ) {
                    $plan['status_changes'][] = [
                        'attempt_id' => $attempt->id,
                        'internal_order_id' => $attempt->internal_order_id,
                        'from' => PaymentAttemptStatus::FailedTerminal->value,
                        'to' => PaymentAttemptStatus::Unknown->value,
                    ];
                    $cycleChanged = true;
                }

                // Check metadata enrichment
                $metadata = $attempt->metadata ?? [];
                if (! isset($metadata['legacy']) || ! isset($metadata['failure_source'])) {
                    $plan['metadata_enrichments'][] = [
                        'attempt_id' => $attempt->id,
                        'internal_order_id' => $attempt->internal_order_id,
                    ];
                    $cycleChanged = true;
                }
            }

            if ($cycleChanged) {
                // Don't increment cycles_unchanged
            } else {
                $plan['cycles_unchanged']++;
            }
        }

        return $plan;
    }

    /**
     * Execute the repair. In dry-run mode, zero DB writes occur.
     */
    public function repair(bool $dryRun = false): array
    {
        if ($dryRun) {
            return $this->computeRepairPlan();
        }

        return $this->executeRepair();
    }

    private function executeRepair(): array
    {
        $stats = [
            'cycles_scanned' => 0,
            'attempts_scanned' => 0,
            'numbering_fixed' => 0,
            'statuses_fixed' => 0,
            'metadata_enriched' => 0,
            'cycles_unchanged' => 0,
        ];

        $cycles = BillingCycle::with('paymentAttempts')->get();
        $stats['cycles_scanned'] = $cycles->count();

        foreach ($cycles as $cycle) {
            $legacyAttempts = $this->getLegacyAttempts($cycle);
            $stats['attempts_scanned'] += $legacyAttempts->count();

            if ($legacyAttempts->isEmpty()) {
                $stats['cycles_unchanged']++;
                continue;
            }

            $cycleChanged = false;

            // Phase 1: Fix status (failed_terminal → unknown for legacy without failure data)
            foreach ($legacyAttempts as $attempt) {
                if ($attempt->status === PaymentAttemptStatus::FailedTerminal
                    && $attempt->failure_code === null
                    && $attempt->failure_category === null
                    && $attempt->failure_message === null
                ) {
                    $attempt->update(['status' => PaymentAttemptStatus::Unknown]);
                    $stats['statuses_fixed']++;
                    $cycleChanged = true;
                }
            }

            // Phase 2: Enrich metadata
            foreach ($legacyAttempts as $attempt) {
                $metadata = $attempt->metadata ?? [];
                $needsUpdate = false;

                if (! isset($metadata['legacy'])) {
                    $metadata['legacy'] = true;
                    $needsUpdate = true;
                }

                if (! isset($metadata['failure_source']) && $attempt->status === PaymentAttemptStatus::Unknown) {
                    $metadata['failure_source'] = 'legacy_unknown';
                    $needsUpdate = true;
                }

                if ($needsUpdate) {
                    $attempt->update(['metadata' => $metadata]);
                    $stats['metadata_enriched']++;
                    $cycleChanged = true;
                }
            }

            // Phase 3: Renumber (two-phase for future unique constraint safety)
            $sortedAttempts = $this->sortLegacyAttempts($legacyAttempts);
            $needsRenumber = false;

            foreach ($sortedAttempts->values() as $idx => $attempt) {
                $expectedNumber = $idx + 1;
                if ($attempt->attempt_number !== $expectedNumber) {
                    $needsRenumber = true;
                    break;
                }
            }

            if ($needsRenumber) {
                // Phase 3a: Assign temporary unique numbers (negative offset)
                foreach ($sortedAttempts->values() as $idx => $attempt) {
                    $tempNumber = -($idx + 1000); // Temporary negative number
                    if ($attempt->attempt_number !== $tempNumber) {
                        $attempt->update(['attempt_number' => $tempNumber]);
                    }
                }

                // Phase 3b: Assign final 1..N numbers
                foreach ($sortedAttempts->values() as $idx => $attempt) {
                    $finalNumber = $idx + 1;
                    $attempt->update(['attempt_number' => $finalNumber]);
                    $stats['numbering_fixed']++;
                }

                $cycleChanged = true;
            }

            if ($cycleChanged) {
                // Already counted individual changes
            } else {
                $stats['cycles_unchanged']++;
            }
        }

        return $stats;
    }

    /**
     * Get legacy attempts for a billing cycle.
     * Legacy attempts are identified by metadata.legacy = true
     * OR by provider = 'mock' (legacy mock gateway).
     */
    private function getLegacyAttempts(BillingCycle $cycle): Collection
    {
        return $cycle->paymentAttempts()
            ->where(fn ($q) => $q->where('provider', 'mock')
                ->orWhere(fn ($q2) => $q2->whereRaw("metadata->>'legacy' = 'true'")))
            ->get();
    }

    /**
     * Sort legacy attempts deterministically:
     * 1. initiated_at ASC (nullable first)
     * 2. legacy_subscription_id ASC as stable tie-breaker
     */
    private function sortLegacyAttempts(Collection $attempts): Collection
    {
        return $attempts->sortBy([
            fn ($a) => $a->initiated_at?->timestamp ?? PHP_INT_MIN,
            fn ($a) => $a->metadata['legacy_subscription_id'] ?? '',
        ])->values();
    }
}
