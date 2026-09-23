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

        $this->table(
            ['Metric', 'Count'],
            [
                ['Workspaces found', $stats['workspaces_found']],
                ['Canonical subscriptions to create', $stats['canonical_subscriptions_to_create']],
                ['Billing cycles to create', $stats['billing_cycles_to_create']],
                ['Payment attempts to create', $stats['payment_attempts_to_create']],
                ['Admin/zero-amount grants', $stats['admin_grants']],
                ['Ambiguous rows', $stats['ambiguous_rows']],
            ],
        );

        if ($stats['skipped_workspaces']) {
            $this->info('Skipped workspaces (no paid plan): '.implode(', ', $stats['skipped_workspaces']));
        }

        if ($dryRun) {
            $this->info('Dry run complete. No data was written.');
        } else {
            $this->info('Legacy projection complete.');
        }

        return self::SUCCESS;
    }
}
