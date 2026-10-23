<?php

namespace App\Exceptions\Backups;

/**
 * За текущий календарный день (Europe/Moscow) автоматическая копия уже
 * создана успешно или ожидает в очереди.
 */
class DuplicateScheduledBackup extends BackupException
{
    public function __construct()
    {
        parent::__construct('Автоматическая резервная копия за сегодня уже создана.');
    }
}
