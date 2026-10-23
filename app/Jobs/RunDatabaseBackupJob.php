<?php

namespace App\Jobs;

use App\Enums\BackupStatus;
use App\Models\DatabaseBackup;
use App\Services\Backups\BackupError;
use App\Services\Backups\DatabaseBackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Выполнение резервной копии в очереди 'backups' (connection 'backups').
 * Сериализуется только id копии — никаких моделей, секретов и параметров
 * подключения. Повторная доставка идемпотентна.
 */
class RunDatabaseBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Одна попытка: повторный параллельный pg_dump недопустим. */
    public int $tries = 1;

    public int $timeout;

    public function __construct(
        private readonly string $backupId,
    ) {
        $this->timeout = (int) config('backups.job_timeout', 900);
    }

    public function handle(DatabaseBackupService $service): void
    {
        $backup = DatabaseBackup::find($this->backupId);

        if (! $backup instanceof DatabaseBackup) {
            return;
        }

        $status = $backup->status;

        // Повторная доставка уже обработанного job — no-op.
        if ($status === BackupStatus::Success || $status === BackupStatus::Failed) {
            return;
        }

        // Копию честно выполняет другой процесс — не мешаем.
        if ($status === BackupStatus::Running && ! $backup->isStale()) {
            return;
        }

        $service->run($backup);
    }

    public function failed(Throwable $e): void
    {
        app(DatabaseBackupService::class)->markInterruptedIfActive(
            $this->backupId,
            'Задание прервано: '.BackupError::fromThrowable($e),
        );

        Log::error('Backups: job завершился с ошибкой', [
            'backup_id' => $this->backupId,
            'error' => BackupError::fromThrowable($e),
        ]);
    }
}
