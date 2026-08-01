<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class BackfillWorkingHours extends Command
{
    protected $signature = 'working-hours:backfill {--dry-run}';

    protected $description = 'Создать дефолтные рабочие часы мастерам, у которых их нет';

    public function handle(): int
    {
        $masters = User::where('is_master', true)
            ->whereDoesntHave('workingHours')
            ->get();

        $this->info("Найдено мастеров без часов: {$masters->count()}");

        if ($this->option('dry-run')) {
            $masters->each(fn (User $u) => $this->line("  - {$u->name} ({$u->id})"));

            return self::SUCCESS;
        }

        foreach ($masters as $master) {
            $master->createDefaultWorkingHours();
            $this->line("✓ {$master->name}");
        }

        $this->info('Готово.');

        return self::SUCCESS;
    }
}
