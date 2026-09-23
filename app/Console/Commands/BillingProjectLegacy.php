<?php

namespace App\Console\Commands;

use App\Services\Billing\LegacyProjectionService;
use Illuminate\Console\Command;

class BillingProjectLegacy extends Command
{
    protected $signature = 'billing:project-legacy {--dry-run : Show what would be created without writing}';

    protected $description = 'Project legacy subscription rows into billing core domain models';

    public function handle(LegacyProjectionService $projection): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no data will be written');
            $this->newLine();
        }

        $stats = $projection->projectAll(dryRun: $dryRun);

        if ($dryRun) {
            // Show plan-style output
            $this->info('── FOUND ──');
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Workspaces', $stats['found']['workspaces']],
                    ['Legacy period groups', $stats['found']['legacy_period_groups']],
                    ['Legacy payment rows', $stats['found']['legacy_payment_rows']],
                    ['Legacy zero-amount grants', $stats['found']['legacy_zero_amount_grants']],
                ],
            );

            $this->newLine();
            $this->info('── TO CREATE ──');
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Canonical subscriptions', $stats['to_create']['canonical_subscriptions']],
                    ['Billing cycles', $stats['to_create']['billing_cycles']],
                    ['Payment attempts', $stats['to_create']['payment_attempts']],
                ],
            );

            $this->newLine();
            $this->info('── ALREADY PROJECTED ──');
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Canonical subscriptions', $stats['already_projected']['canonical_subscriptions']],
                    ['Billing cycles', $stats['already_projected']['billing_cycles']],
                    ['Payment attempts', $stats['already_projected']['payment_attempts']],
                ],
            );
        } else {
            // Show execution-style output
            $this->info('── CREATED ──');
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Canonical subscriptions', $stats['created']['canonical_subscriptions']],
                    ['Billing cycles', $stats['created']['billing_cycles']],
                    ['Payment attempts', $stats['created']['payment_attempts']],
                ],
            );

            $this->newLine();
            $this->info('── ALREADY EXISTED ──');
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Canonical subscriptions', $stats['already_projected']['canonical_subscriptions']],
                    ['Billing cycles', $stats['already_projected']['billing_cycles']],
                    ['Payment attempts', $stats['already_projected']['payment_attempts'] ?? 0],
                ],
            );
        }

        if ($stats['ambiguous_groups']) {
            $this->newLine();
            $this->warn('── AMBIGUOUS GROUPS (skipped) ──');
            foreach ($stats['ambiguous_groups'] as $ambiguity) {
                $this->warn("  • {$ambiguity}");
            }
        }

        if ($stats['skipped_workspaces']) {
            $this->newLine();
            $this->info('Skipped workspaces (free plan): '.implode(', ', $stats['skipped_workspaces']));
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('Dry run complete. No data was written.');
        } else {
            $this->newLine();
            $this->info('Legacy projection complete.');
        }

        return self::SUCCESS;
    }
}
