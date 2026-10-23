<?php

namespace App\Services\Backups;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

final class LocalBackupProcessRunner implements BackupProcessRunner
{
    public function run(array $command, array $env = [], ?float $timeoutSeconds = null): BackupProcessResult
    {
        $pending = Process::env($env);

        if ($timeoutSeconds !== null) {
            $pending = $pending->timeout((int) ceil($timeoutSeconds));
        }

        try {
            $result = $pending->run($command);

            return new BackupProcessResult(
                exitCode: $result->exitCode(),
                stdout: $result->output(),
                stderr: $result->errorOutput(),
                timedOut: false,
            );
        } catch (ProcessTimedOutException $e) {
            $processResult = $e->result;

            return new BackupProcessResult(
                exitCode: $processResult->exitCode(),
                stdout: $processResult->output(),
                stderr: $processResult->errorOutput(),
                timedOut: true,
            );
        }
    }
}
