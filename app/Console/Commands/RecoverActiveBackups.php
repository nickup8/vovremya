<?php

namespace App\Console\Commands;

use App\Services\Backups\DatabaseBackupService;
use Illuminate\Console\Command;

/**
 * Периодическое восстановление активных состояний резервных копий.
 * Не зависит от BACKUP_SCHEDULED_ENABLED: доступно и при выключенном
 * ежедневном расписании. Регистрируется в routes/console.php.
 */
class RecoverActiveBackups extends Command
{
    protected $signature = 'backups:recover';

    protected $description = 'Восстановить зависшие running/queued резервные копии (stale, потерянные задания)';

    public function handle(DatabaseBackupService $service): int
    {
        $service->recoverActiveBackups();

        $this->info('Проверка активных резервных копий завершена.');

        return self::SUCCESS;
    }
}
