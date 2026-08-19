<?php

namespace App\Console\Commands;

use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackfillSoloServices extends Command
{
    protected $signature = 'services:backfill-solo {--dry-run : Show what would be done without making changes}';

    protected $description = 'Backfill missing MasterService records for solo catalog items and normalize is_active';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $anomalyCount = 0;
        $createdCount = 0;
        $normalizedCount = 0;
        $skippedCount = 0;

        $catalogs = ServiceCatalog::with('masterServices')->get();

        foreach ($catalogs as $catalog) {
            $workspaceId = $catalog->workspace_id;

            $masters = User::where('workspace_id', $workspaceId)
                ->where('is_master', true)
                ->get();

            if ($masters->count() !== 1) {
                $this->warn("SKIP catalog '{$catalog->title}' ({$catalog->id}): workspace has {$masters->count()} masters");
                $skippedCount++;

                continue;
            }

            $master = $masters->first();

            if ($master->workspace_id !== $catalog->workspace_id) {
                $this->error("ANOMALY: master {$master->id} workspace_id != catalog {$catalog->id} workspace_id");
                Log::error('BackfillSoloServices: cross-workspace anomaly', [
                    'master_id' => $master->id,
                    'master_workspace' => $master->workspace_id,
                    'catalog_id' => $catalog->id,
                    'catalog_workspace' => $catalog->workspace_id,
                ]);
                $anomalyCount++;

                continue;
            }

            $existingMs = $catalog->masterServices()
                ->where('master_id', $master->id)
                ->first();

            if (! $existingMs) {
                $this->info("CREATE MS: catalog '{$catalog->title}' -> master '{$master->name}'");
                if (! $dryRun) {
                    MasterService::firstOrCreate(
                        ['master_id' => $master->id, 'catalog_id' => $catalog->id],
                        ['is_active' => true, 'price_override' => null, 'duration_override' => null]
                    );
                }
                $createdCount++;
            } elseif (! $existingMs->is_active) {
                $this->info("NORMALIZE MS.active: '{$catalog->title}' ({$existingMs->id}) false -> true");
                if (! $dryRun) {
                    $existingMs->update(['is_active' => true]);
                }
                $normalizedCount++;
            }
        }

        $this->newLine();
        $this->info('Summary:');
        $this->info("  Created: {$createdCount}");
        $this->info("  Normalized: {$normalizedCount}");
        $this->info("  Skipped (masters != 1): {$skippedCount}");
        $this->info("  Anomalies: {$anomalyCount}");

        if ($anomalyCount > 0) {
            $this->error('Anomalies found! Check logs.');

            return 1;
        }

        return 0;
    }
}
