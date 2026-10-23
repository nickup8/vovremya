<?php

namespace App\Exceptions\Backups;

/**
 * Проигрыш гонки захвата: другой процесс уже владеет этой копией
 * (свежий running с чужим execution_token либо терминальный статус).
 * Доставка job в этом случае — no-op: failed()-хук не вызывается,
 * чтобы не пометить прерванной чужой живой запуск.
 */
class BackupCaptureLost extends BackupException
{
    public function __construct()
    {
        parent::__construct('Копия уже обрабатывается другим процессом — запуск отменён.');
    }
}
