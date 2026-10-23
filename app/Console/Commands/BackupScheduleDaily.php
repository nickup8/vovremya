<?php

namespace App\Console\Commands;

use App\Enums\BackupTrigger;
use App\Exceptions\Backups\BackupAlreadyRunning;
use App\Exceptions\Backups\DuplicateScheduledBackup;
use App\Services\Backups\DatabaseBackupService;
use Illuminate\Console\Command;

/**
 * Ежедневный автоматический запуск из scheduler (03:30 Europe/Moscow).
 * Включение — BACKUP_SCHEDULED_ENABLED=true; по умолчанию выключен.
 */
class BackupScheduleDaily extends Command
{
    protected $signature = 'backups:schedule-daily';

    protected $description = 'Ежедневная автоматическая резервная копия PostgreSQL (с дедупликацией за день)';

    public function handle(DatabaseBackupService $service): int
    {
        if (! config('backups.scheduled.enabled')) {
            $this->info('Автоматические резервные копии выключены (BACKUP_SCHEDULED_ENABLED=false).');

            return self::SUCCESS;
        }

        try {
            $backup = $service->request(BackupTrigger::Scheduled);
        } catch (DuplicateScheduledBackup|BackupAlreadyRunning $e) {
            $this->info($e->getMessage());

            return self::SUCCESS;
        }

        $this->info('Автоматическая резервная копия поставлена в очередь: '.$backup->getKey());

        return self::SUCCESS;
    }
}
