<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\PlanAccessService;
use Illuminate\Console\Command;

class BillingVerifyEntitlement extends Command
{
    protected $signature = 'billing:verify-entitlement';

    protected $description = 'Read-only parity check: compare legacy entitlement vs Core entitlement reader';

    public function handle(PlanAccessService $legacy, EntitlementService $core): int
    {
        $workspaces = Workspace::query()->with('subscriptions.tariffPlan')->get();

        $matches = 0;
        $mismatches = 0;
        $details = [];

        foreach ($workspaces as $workspace) {
            $legacyPlan = $legacy->currentPlanCode($workspace);
            $corePlan = $core->currentPlan($workspace);
            $corePlanCode = $corePlan?->code ?? 'start';

            $legacyEntitled = $legacyPlan !== 'start';
            $coreEntitled = $corePlanCode !== 'start';

            $legacyEnd = null;
            $activeSub = $workspace->activeSubscription();
            if ($activeSub) {
                $legacyEnd = $activeSub->expires_at?->format('Y-m-d H:i:s');
            }

            $coreEnd = null;
            if ($corePlan) {
                $coreEnd = $corePlan->expiresAt?->format('Y-m-d H:i:s');
            }

            $reason = null;

            if ($legacyPlan !== $corePlanCode) {
                $reason = 'plan_mismatch';
            } elseif ($legacyEntitled !== $coreEntitled) {
                $reason = 'entitlement_mismatch';
            } elseif ($legacyEnd !== $coreEnd) {
                $reason = 'expiry_mismatch';
            }

            if ($reason) {
                $mismatches++;
                $details[] = [
                    'workspace_id' => $workspace->id,
                    'legacy_plan' => $legacyPlan,
                    'core_plan' => $corePlanCode,
                    'legacy_expires_at' => $legacyEnd ?? 'null',
                    'core_entitlement_end' => $coreEnd ?? 'null',
                    'reason' => $reason,
                ];
            } else {
                $matches++;
            }
        }

        $this->newLine();
        $this->line('<info>SUMMARY</info>');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Workspaces checked', $workspaces->count()],
                ['Matches', $matches],
                ['Mismatches', $mismatches],
            ]
        );

        if ($mismatches > 0) {
            $this->newLine();
            $this->line('<error>MISMATCHES</error>');
            $this->table(
                ['workspace_id', 'legacy_plan', 'core_plan', 'legacy_expires_at', 'core_entitlement_end', 'reason'],
                $details
            );

            return self::FAILURE;
        }

        $this->info('All workspaces match.');

        return self::SUCCESS;
    }
}
