<?php

namespace App\Services\Backups;

/**
 * Результат запуска внешней команды без stdout-секретов.
 */
final class BackupProcessResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly bool $timedOut,
    ) {}
}
