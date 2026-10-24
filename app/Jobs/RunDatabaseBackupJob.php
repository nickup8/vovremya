<?php

namespace App\Jobs;

use App\Enums\BackupStatus;
use App\Exceptions\Backups\BackupCaptureLost;
use App\Models\DatabaseBackup;
use App\Services\Backups\BackupError;
use App\Services\Backups\DatabaseBackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Выполнение резервной копии в очереди 'backups' (connection 'backups').
 * Сериализуется только id копии и токен доставки — никаких моделей,
 * секретов и параметров подключения. Повторная доставка идемпотентна.
 */
class RunDatabaseBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Одна попытка: повторный параллельный pg_dump недопустим. */
    public int $tries = 1;

    public int $timeout;

    /**
     * Токен этой доставки — он же execution_token копии. Хранится в job,
     * чтобы failed()-хук (отдельная десериализация команды) мог fenced-
     * прервать ТОЛЬКО своё исполнение, а не чужой/перехваченный запуск.
     */
    public ?string $executionToken = null;

    public function __construct(
        private readonly string $backupId,
        ?string $executionToken = null,
    ) {
        $this->executionToken = $executionToken;
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

        $this->executionToken ??= (string) Str::uuid();

        try {
            $service->run($backup, $this->executionToken);
        } catch (BackupCaptureLost) {
            // Гонка доставок: между проверкой статуса и begin() копию
            // захватил другой воркер. No-op: failed()-хук не должен
            // пометить прерванной чужую живую попытку.
            Log::info('Backups: доставка job проиграла гонку захвата — no-op', [
                'backup_id' => $this->backupId,
            ]);
        }
    }

    /**
     * В failed_jobs уходит только санитизированное сообщение BackupException
     * (run() не пробрасывает исходное исключение и его previous-цепочку).
     * Прерывается только исполнение этой доставки (execution_token).
     */
    public function failed(Throwable $e): void
    {
        $safe = BackupError::fromThrowable($e);

        app(DatabaseBackupService::class)->markInterruptedIfActive(
            $this->backupId,
            'Задание прервано: '.$safe,
            $this->executionToken,
        );

        Log::error('Backups: job завершился с ошибкой', [
            'backup_id' => $this->backupId,
            'error' => $safe,
        ]);
    }
}
