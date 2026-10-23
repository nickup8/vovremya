<?php

namespace App\Services\Backups;

/**
 * Подменяемый запуск внешних процессов (pg_dump / pg_restore).
 * Аргументы — всегда массив без shell-интерполяции; секреты передаются
 * только через окружение (PGPASSFILE), не через аргументы.
 */
interface BackupProcessRunner
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    public function run(array $command, array $env = [], ?float $timeoutSeconds = null): BackupProcessResult;
}
