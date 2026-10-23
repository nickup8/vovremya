<?php

namespace Tests\Support\Backups;

use App\Services\Backups\BackupProcessResult;
use App\Services\Backups\BackupProcessRunner;

/**
 * Подменяемый runner для тестов резервного копирования: pg_dump «пишет»
 * фиктивный дамп в --file, pg_restore --list возвращает настраиваемый
 * exit code. Реальные бинарники не вызываются.
 */
final class FakeBackupProcessRunner implements BackupProcessRunner
{
    /** @var list<array{command: list<string>, env: array<string, string>}> */
    public array $calls = [];

    public string $dumpContent = 'FAKE_PG_DUMP_ARCHIVE_CONTENTxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

    public int $dumpExitCode = 0;

    public bool $dumpTimedOut = false;

    public string $dumpStderr = '';

    public bool $skipDumpFile = false;

    public int $restoreExitCode = 0;

    public bool $restoreTimedOut = false;

    public function run(array $command, array $env = [], ?float $timeoutSeconds = null): BackupProcessResult
    {
        $this->calls[] = ['command' => $command, 'env' => $env];

        if (in_array('--format=custom', $command, true)) {
            return $this->runPgDump($command);
        }

        return new BackupProcessResult($this->restoreExitCode, '', '', $this->restoreTimedOut);
    }

    /** @param  list<string>  $command */
    private function runPgDump(array $command): BackupProcessResult
    {
        if ($this->dumpTimedOut || $this->dumpExitCode !== 0) {
            return new BackupProcessResult($this->dumpExitCode, '', $this->dumpStderr, $this->dumpTimedOut);
        }

        if (! $this->skipDumpFile) {
            $index = array_search('--file', $command, true);
            $path = $index === false ? null : ($command[$index + 1] ?? null);

            if (is_string($path) && $path !== '') {
                file_put_contents($path, $this->dumpContent);
            }
        }

        return new BackupProcessResult(0, '', '', false);
    }

    /** @return list<list<string>> */
    public function commands(): array
    {
        return array_map(fn (array $call) => $call['command'], $this->calls);
    }

    public function containsPasswordInAnyCommand(string $password): bool
    {
        foreach ($this->calls as $call) {
            if (in_array($password, $call['command'], true)) {
                return true;
            }

            foreach ($call['env'] as $key => $value) {
                if ($key !== 'PGPASSFILE' && str_contains($value, $password)) {
                    return true;
                }
            }
        }

        return false;
    }
}
