<?php

namespace App\Console\Commands;

use App\Enums\BackupTrigger;
use App\Exceptions\Backups\BackupAlreadyRunning;
use App\Services\Backups\DatabaseBackupService;
use Illuminate\Console\Command;

class RunDatabaseBackup extends Command
{
    protected $signature = 'backups:run';

    protected $description = 'Поставить в очередь ручную резервную копию PostgreSQL';

    public function handle(DatabaseBackupService $service): int
    {
        try {
            $backup = $service->request(BackupTrigger::Manual);
        } catch (BackupAlreadyRunning $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Резервная копия поставлена в очередь: '.$backup->getKey());

        return self::SUCCESS;
    }
}
