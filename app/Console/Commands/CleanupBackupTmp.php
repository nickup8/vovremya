<?php

namespace App\Console\Commands;

use App\Services\Backups\DatabaseBackupService;
use Illuminate\Console\Command;

/**
 * Уборка временных файлов копий, оставшихся после аварийных завершений
 * (воркер убит до finally). umask сессии не меняется.
 */
class CleanupBackupTmp extends Command
{
    protected $signature = 'backups:cleanup-tmp';

    protected $description = 'Удалить устаревшие временные файлы резервных копий';

    public function handle(): int
    {
        $base = rtrim((string) config('backups.tmp_dir'), '/\\');

        if ($base === '' || ! is_dir($base)) {
            return self::SUCCESS;
        }

        $cutoff = now()->subHours(max(1, (int) config('backups.tmp_max_age_hours', 24)))->getTimestamp();
        $removed = 0;

        foreach (scandir($base) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $base.'/'.$entry;

            if (@filemtime($path) === false || filemtime($path) >= $cutoff) {
                continue;
            }

            if (is_dir($path)) {
                DatabaseBackupService::deleteTree($path);
            } else {
                @unlink($path);
            }

            $removed++;
        }

        $this->info('Удалено устаревших записей: '.$removed);

        return self::SUCCESS;
    }
}
