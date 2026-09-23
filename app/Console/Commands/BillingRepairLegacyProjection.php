<?php

namespace App\Console\Commands;

use App\Services\Billing\LegacyProjectionRepairService;
use Illuminate\Console\Command;

class BillingRepairLegacyProjection extends Command
{
    protected $signature = 'billing:repair-legacy-projection {--dry-run : Show what would be repaired without writing}';

    protected $description = 'Repair legacy billing projection: renumber attempts, fix failure statuses, enrich metadata';

    public function handle(LegacyProjectionRepairService $repair): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no data will be modified');
            $this->newLine();
        }

        $stats = $repair->repair(dryRun: $dryRun);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Legacy cycles scanned', $stats['cycles_scanned']],
                ['Legacy attempts scanned', $stats['attempts_scanned']],
                ['Attempt numbers to change', is_array($stats['numbering_fixed'] ?? null) ? count($stats['numbering_fixed']) : ($stats['numbering_fixed'] ?? 0)],
                ['failed_terminal → unknown', is_array($stats['status_changes'] ?? null) ? count($stats['status_changes']) : ($stats['statuses_fixed'] ?? 0)],
                ['Metadata enrichments', is_array($stats['metadata_enrichments'] ?? null) ? count($stats['metadata_enrichments']) : ($stats['metadata_enriched'] ?? 0)],
                ['Cycles unchanged', $stats['cycles_unchanged']],
            ],
        );

        if ($dryRun) {
            $this->info('Dry run complete. No data was modified.');
        } else {
            $this->info('Legacy projection repair complete.');
        }

        return self::SUCCESS;
    }
}
