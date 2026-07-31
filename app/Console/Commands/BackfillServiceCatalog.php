<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Services\CreateServiceAction;
use Illuminate\Console\Command;

class BackfillServiceCatalog extends Command
{
    protected $signature = 'services:backfill-catalog {--dry-run : Показать что будет сделано без записи}';

    protected $description = 'Backfill legacy services → service_catalog + master_service (двухуровневая модель, идемпотентно)';

    public function handle(CreateServiceAction $action): int
    {
        $dryRun = $this->option('dry-run');
        $services = Service::with('master')->get();

        $this->info("Найдено services: {$services->count()}");

        if ($dryRun) {
            $this->warn('РЕЖИМ DRY-RUN — записи НЕ будет');
        }

        $processed = 0;
        $skipped = 0;

        foreach ($services as $service) {
            $master = $service->master;
            $wsShort = $master && $master->workspace_id ? substr($master->workspace_id, 0, 8) : 'NULL';
            $label = "svc ".substr($service->id, 0, 8)." | {$service->title} | ws {$wsShort}";

            if (! $master || ! $master->workspace_id) {
                $this->error("SKIP {$label} — нет мастера или workspace_id");
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $this->line("WOULD SYNC {$label}");
                $processed++;
                continue;
            }

            try {
                $action->syncCatalogAndMaster($service);
                $this->line("OK {$label}");
                $processed++;
            } catch (\Throwable $e) {
                $this->error("SKIP {$label} — {$e->getMessage()}");
                $skipped++;
            }
        }

        $this->newLine();
        $this->info("Обработано: {$processed} | Пропущено: {$skipped}");

        return self::SUCCESS;
    }
}
