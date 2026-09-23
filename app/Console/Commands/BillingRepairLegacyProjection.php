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
                ['Attempt numbers to change', $stats['numbers_to_change']],
                ['failed_terminal → unknown', $stats['statuses_fixed']],
                ['Metadata enrichments', $stats['metadata_enriched']],
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
