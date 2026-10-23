<?php

namespace App\Exceptions\Backups;

/**
 * Уже есть активная (queued/running) резервная копия — ручной и
 * автоматический запуски блокируются вместе.
 */
class BackupAlreadyRunning extends BackupException
{
    public function __construct()
    {
        parent::__construct('Резервная копия уже выполняется или ожидает в очереди.');
    }
}
