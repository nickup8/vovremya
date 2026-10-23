<?php

namespace App\Services\Backups;

use App\Enums\BackupStatus;
use App\Enums\BackupTrigger;
use App\Exceptions\Backups\BackupAlreadyRunning;
use App\Exceptions\Backups\BackupException;
use App\Exceptions\Backups\DuplicateScheduledBackup;
use App\Jobs\RunDatabaseBackupJob;
use App\Models\DatabaseBackup;
use App\Models\SystemNotificationMessage;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\SuperAdminAuditLogger;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Единый сервис резервных копий PostgreSQL: ручной запуск, расписание
 * и Artisan-команда проходят через request(); выполнение — run().
 *
 * Порядок: pg_dump (custom) → проверка exit code/размера/pg_restore --list
 * → потоковая загрузка в S3 → чтение объекта и сверка размера и SHA-256
 * → только после этого статус success → ротация старых копий.
 */
class DatabaseBackupService
{
    public function __construct(
        private readonly BackupProcessRunner $processRunner,
    ) {}

    /**
     * Поставить копию в очередь: общая блокировка параллельных запусков
     * (partial unique index) + дедупликация scheduled за календарный день.
     *
     * @throws BackupAlreadyRunning
     * @throws DuplicateScheduledBackup
     */
    public function request(BackupTrigger $trigger, ?User $user = null): DatabaseBackup
    {
        $this->reclaimStaleRunning();

        try {
            // DB::transaction даёт savepoint: в PostgreSQL нарушение unique
            // не должен оставлять внешнюю транзакцию в aborted-состоянии.
            $backup = DB::transaction(fn () => DatabaseBackup::create([
                'trigger_type' => $trigger->value,
                'status' => BackupStatus::Queued,
                'requested_by' => $user?->getKey(),
                'requested_at' => now(),
                'dedup_key' => $trigger === BackupTrigger::Scheduled
                    ? now(config('backups.scheduled.timezone', 'Europe/Moscow'))->format('Y-m-d')
                    : null,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Какой из partial-unique индексов сработал: активная копия
            // (общий запрет параллельности) или дедуп scheduled за день.
            $hasActive = DatabaseBackup::query()
                ->whereIn('status', [BackupStatus::Queued->value, BackupStatus::Running->value])
                ->exists();

            if ($hasActive || $trigger === BackupTrigger::Manual) {
                throw new BackupAlreadyRunning;
            }

            throw new DuplicateScheduledBackup;
        }

        RunDatabaseBackupJob::dispatch($backup->getKey())
            ->onConnection('backups')
            ->onQueue('backups');

        return $backup;
    }

    /**
     * Полный цикл создания копии. Любой сбой → безопасная запись ошибки,
     * database-уведомление ROOT и rethrow (worker логирует в stderr,
     * job попадает в failed_jobs).
     */
    public function run(DatabaseBackup $backup): void
    {
        $this->begin($backup);

        $tmpDir = null;

        try {
            $tmpDir = $this->createTempDirectory($backup);
            $dumpPath = $tmpDir.'/database.dump';

            $this->runPgDump($tmpDir, $dumpPath);
            $this->assertArchiveReadable($dumpPath);

            $size = $this->requireNonEmptySize($dumpPath);
            $sha = (string) hash_file('sha256', $dumpPath);
            $pgVersion = $this->postgresVersion();
            $appVersion = (string) config('app.version');

            $objectKey = $this->objectKey($backup);
            $this->uploadVerified($dumpPath, $objectKey, $size, $sha);

            $manifestKey = $objectKey.'.manifest.json';
            $this->uploadManifest($tmpDir, $manifestKey, [
                'backup_id' => $backup->getKey(),
                'object_key' => $objectKey,
                'trigger' => $backup->trigger_type->value,
                'environment' => (string) config('app.env'),
                'requested_at' => $backup->requested_at?->toIso8601String(),
                'finished_at' => now()->toIso8601String(),
                'size_bytes' => $size,
                'sha256' => $sha,
                'pg_version' => $pgVersion,
                'app_version' => $appVersion,
            ]);

            $this->markSucceeded($backup, $objectKey, $manifestKey, $size, $sha, $pgVersion, $appVersion);
            $this->pruneOld($backup);
        } catch (Throwable $e) {
            $this->safeRecordFailure($backup, $e);

            throw $e;
        } finally {
            if ($tmpDir !== null) {
                self::deleteTree($tmpDir);
            }
        }
    }

    /**
     * Пометить зависшую running-копию прерванной (для повторного запуска).
     */
    public function reclaimStaleRunning(): void
    {
        $stale = DatabaseBackup::query()
            ->where('status', BackupStatus::Running->value)
            ->get()
            ->filter(fn (DatabaseBackup $backup) => $backup->isStale());

        foreach ($stale as $backup) {
            $this->markInterrupted($backup);
        }
    }

    /**
     * Последний шанс при сбое job: не оставлять копию навсегда running.
     */
    public function markInterruptedIfActive(string $backupId, string $message): void
    {
        try {
            $affected = DatabaseBackup::query()
                ->whereKey($backupId)
                ->whereIn('status', [BackupStatus::Queued->value, BackupStatus::Running->value])
                ->update([
                    'status' => BackupStatus::Failed->value,
                    'finished_at' => now(),
                    'error_message' => BackupError::sanitize($message),
                ]);

            if ($affected > 0) {
                Log::error('Backups: копия помечена прерванной', ['backup_id' => $backupId, 'message' => $message]);
            }
        } catch (Throwable $e) {
            // БД может быть недоступна — фиксируем хотя бы в системный лог.
            Log::error('Backups: не удалось пометить копию прерванной: '.BackupError::fromThrowable($e), [
                'backup_id' => $backupId,
            ]);
        }
    }

    public static function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }

    private function begin(DatabaseBackup $backup): void
    {
        $affected = DatabaseBackup::query()
            ->whereKey($backup->getKey())
            ->where('status', BackupStatus::Queued->value)
            ->update([
                'status' => BackupStatus::Running->value,
                'started_at' => now(),
            ]);

        if ($affected === 1) {
            $backup->refresh();

            return;
        }

        $backup->refresh();

        if ($backup->status === BackupStatus::Running && $backup->isStale()) {
            DatabaseBackup::query()
                ->whereKey($backup->getKey())
                ->update([
                    'status' => BackupStatus::Running->value,
                    'started_at' => now(),
                ]);
            $backup->refresh();

            return;
        }

        throw new BackupException('Копия уже обрабатывается другим процессом — запуск отменён.');
    }

    private function runPgDump(string $tmpDir, string $dumpPath): void
    {
        $conn = $this->databaseConfig();
        $pgpassPath = $this->writePgPass($tmpDir, $conn);

        $command = [
            (string) config('backups.pg_dump'),
            '--format=custom',
            '--no-owner',
            '--no-privileges',
            '--file', $dumpPath,
            '--host', (string) ($conn['host'] ?? ''),
            '--port', (string) ($conn['port'] ?? 5432),
            '--username', (string) ($conn['username'] ?? ''),
            '--dbname', (string) ($conn['database'] ?? ''),
        ];

        $timeout = (float) config('backups.process_timeout', 600);
        $result = $this->processRunner->run($command, ['PGPASSFILE' => $pgpassPath], $timeout);

        if ($result->timedOut) {
            throw new BackupException('pg_dump превысил таймаут.'.self::stderrSuffix($result));
        }

        if ($result->exitCode !== 0) {
            throw new BackupException(
                'pg_dump завершился с кодом '.$result->exitCode.'.'.self::stderrSuffix($result)
            );
        }
    }

    private function assertArchiveReadable(string $dumpPath): void
    {
        $command = [
            (string) config('backups.pg_restore'),
            '--list',
            $dumpPath,
        ];

        $timeout = (float) config('backups.process_timeout', 600);
        $result = $this->processRunner->run($command, [], $timeout);

        if ($result->timedOut) {
            throw new BackupException('pg_restore --list превысил таймаут.');
        }

        if ($result->exitCode !== 0) {
            throw new BackupException(
                'Архив не проходит проверку pg_restore --list (код '.$result->exitCode.').'
            );
        }
    }

    private function requireNonEmptySize(string $path): int
    {
        if (! is_file($path)) {
            throw new BackupException('pg_dump не создал файл дампа.');
        }

        $size = filesize($path);

        if ($size === false || $size <= 0) {
            throw new BackupException('pg_dump создал пустой файл дампа.');
        }

        return $size;
    }

    /**
     * Загрузка потоком + контроль: после записи объект читается заново,
     * размер и SHA-256 сверяются с локальным файлом.
     */
    private function uploadVerified(string $path, string $objectKey, int $size, string $sha): void
    {
        $disk = $this->disk();

        if ($disk->exists($objectKey)) {
            throw new BackupException('Объект уже существует в бакете — перезапись отменена.');
        }

        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw new BackupException('Не удалось открыть локальный файл для загрузки.');
        }

        try {
            $written = $disk->writeStream($objectKey, $stream, ['visibility' => 'private']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written === false) {
            throw new BackupException('Загрузка объекта в S3 не удалась.');
        }

        $this->assertRemoteMatches($disk, $objectKey, $size, $sha);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function uploadManifest(string $tmpDir, string $manifestKey, array $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new BackupException('Не удалось сформировать manifest.');
        }

        $manifestPath = $tmpDir.'/manifest.json';

        if (file_put_contents($manifestPath, $json) === false) {
            throw new BackupException('Не удалось записать manifest локально.');
        }

        chmod($manifestPath, 0600);

        $this->uploadVerified($manifestPath, $manifestKey, strlen($json), (string) hash('sha256', $json));
    }

    private function assertRemoteMatches(Filesystem $disk, string $objectKey, int $size, string $sha): void
    {
        $read = $disk->readStream($objectKey);

        if (! is_resource($read)) {
            throw new BackupException('Не удалось прочитать объект из S3 после загрузки.');
        }

        $hash = hash_init('sha256');
        $bytes = 0;

        try {
            while (! feof($read)) {
                $chunk = fread($read, 1048576);

                if ($chunk === false) {
                    break;
                }

                $bytes += strlen($chunk);
                hash_update($hash, $chunk);
            }
        } finally {
            fclose($read);
        }

        $remoteSha = hash_final($hash);

        if ($bytes !== $size || ! hash_equals($sha, $remoteSha)) {
            throw new BackupException(sprintf(
                'Проверка объекта в S3 не пройдена: размер %d (ожидалось %d), SHA-256 %s… (ожидалось %s…).',
                $bytes,
                $size,
                substr($remoteSha, 0, 12),
                substr($sha, 0, 12),
            ));
        }
    }

    private function markSucceeded(
        DatabaseBackup $backup,
        string $objectKey,
        string $manifestKey,
        int $size,
        string $sha,
        string $pgVersion,
        string $appVersion,
    ): void {
        DatabaseBackup::query()
            ->whereKey($backup->getKey())
            ->update([
                'status' => BackupStatus::Success->value,
                'finished_at' => now(),
                'object_key' => $objectKey,
                'manifest_key' => $manifestKey,
                'size_bytes' => $size,
                'sha256' => $sha,
                'pg_version' => Str::limit($pgVersion, 255, ''),
                'app_version' => Str::limit($appVersion, 64, ''),
                'error_message' => null,
            ]);

        $backup->refresh();

        if ($backup->trigger_type === BackupTrigger::Manual) {
            $this->auditManualResult($backup, 'backup.succeeded', [
                'backup_id' => $backup->getKey(),
                'size_bytes' => $size,
                'sha256' => $sha,
            ]);
        }
    }

    /**
     * Ротация: держим последние N успешных копий каждого типа отдельно.
     * Удаляются только объекты нашего префикса; сбой очистки не делает
     * новую копию невалидной — пишется prune_error_message.
     */
    private function pruneOld(DatabaseBackup $current): void
    {
        $keep = max(1, (int) config('backups.keep.'.$current->trigger_type->value, 7));

        $expired = DatabaseBackup::query()
            ->where('trigger_type', $current->trigger_type->value)
            ->where('status', BackupStatus::Success->value)
            ->whereKeyNot($current->getKey())
            ->orderByDesc('finished_at')
            ->skip($keep - 1)
            ->take(100)
            ->get();

        if ($expired->isEmpty()) {
            return;
        }

        $disk = $this->disk();
        $errors = [];

        foreach ($expired as $old) {
            try {
                foreach ([$old->object_key, $old->manifest_key] as $key) {
                    if ($key === null || $key === '') {
                        continue;
                    }

                    $this->assertOwnedKey($key);

                    if ($disk->exists($key)) {
                        $disk->delete($key);
                    }
                }

                $old->delete();
            } catch (Throwable $e) {
                $errors[] = BackupError::fromThrowable($e);
            }
        }

        if ($errors !== []) {
            $message = 'Очистка старых копий: '.implode(' | ', array_unique($errors));

            DatabaseBackup::query()
                ->whereKey($current->getKey())
                ->update(['prune_error_message' => BackupError::sanitize($message)]);

            $current->refresh();

            Log::warning('Backups: ошибка ротации старых копий', [
                'backup_id' => $current->getKey(),
                'errors' => $errors,
            ]);
        }
    }

    private function assertOwnedKey(string $key): void
    {
        $prefix = trim((string) config('backups.prefix'), '/').'/';

        if (! str_starts_with($key, $prefix)) {
            throw new BackupException('Объект вне префикса приложения — удаление отменено.');
        }
    }

    private function markInterrupted(DatabaseBackup $backup): void
    {
        DatabaseBackup::query()
            ->whereKey($backup->getKey())
            ->where('status', BackupStatus::Running->value)
            ->update([
                'status' => BackupStatus::Failed->value,
                'finished_at' => now(),
                'error_message' => 'Процесс прерван (устаревшее состояние).',
            ]);

        $backup->refresh();

        Log::warning('Backups: зависшая копия помечена прерванной', ['backup_id' => $backup->getKey()]);

        if ($backup->trigger_type === BackupTrigger::Manual) {
            $this->auditManualResult($backup, 'backup.failed', [
                'backup_id' => $backup->getKey(),
                'reason' => 'stale',
            ]);
        }
    }

    private function safeRecordFailure(DatabaseBackup $backup, Throwable $e): void
    {
        $message = BackupError::fromThrowable($e);

        try {
            DatabaseBackup::query()
                ->whereKey($backup->getKey())
                ->whereIn('status', [BackupStatus::Queued->value, BackupStatus::Running->value])
                ->update([
                    'status' => BackupStatus::Failed->value,
                    'finished_at' => now(),
                    'error_message' => $message,
                ]);

            $backup->refresh();
        } catch (Throwable $dbError) {
            // Сама БД недоступна: уведомить через database-канал не сможем,
            // но системный лог фиксируем обязательно; worker также получит
            // rethrown-исключение в stderr.
            Log::error('Backups: не удалось записать сбой копии в БД: '.BackupError::fromThrowable($dbError), [
                'backup_id' => $backup->getKey(),
            ]);
        }

        $this->notifyRootsFailure($message);

        if ($backup->trigger_type === BackupTrigger::Manual) {
            $this->auditManualResult($backup, 'backup.failed', [
                'backup_id' => $backup->getKey(),
                'error' => $message,
            ]);
        }

        Log::error('Backups: сбой резервной копии', [
            'backup_id' => $backup->getKey(),
            'trigger' => $backup->trigger_type->value,
            'error' => $message,
        ]);
    }

    private function notifyRootsFailure(string $message): void
    {
        try {
            $roots = User::query()
                ->where('is_super_admin', true)
                ->get(['id']);

            if ($roots->isEmpty()) {
                return;
            }

            $title = 'Сбой резервной копии БД';

            $systemMessage = SystemNotificationMessage::create([
                'title' => $title,
                'body' => $message,
                'created_by' => null,
            ]);

            foreach ($roots as $root) {
                $root->notify(new SystemNotification($title, $message, $systemMessage->getKey()));
            }
        } catch (Throwable $e) {
            Log::error('Backups: database-уведомление ROOT не отправлено: '.BackupError::fromThrowable($e));
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function auditManualResult(DatabaseBackup $backup, string $action, array $metadata): void
    {
        if (! $backup->requested_by) {
            return;
        }

        try {
            $actor = User::find($backup->requested_by);

            if (! $actor instanceof User) {
                return;
            }

            app(SuperAdminAuditLogger::class)->log($actor, $action, $backup, metadata: $metadata);
        } catch (Throwable $e) {
            Log::error('Backups: аудит не записан: '.BackupError::fromThrowable($e), [
                'backup_id' => $backup->getKey(),
                'action' => $action,
            ]);
        }
    }

    private function createTempDirectory(DatabaseBackup $backup): string
    {
        $base = rtrim((string) config('backups.tmp_dir'), '/\\');

        if (! is_dir($base) && ! @mkdir($base, 0700, true) && ! is_dir($base)) {
            throw new BackupException('Не удалось создать временную директорию резервных копий.');
        }

        $dir = $base.'/'.$backup->getKey();

        if (! @mkdir($dir, 0700) && ! is_dir($dir)) {
            throw new BackupException('Не удалось создать временную директорию копии.');
        }

        chmod($dir, 0700);

        return $dir;
    }

    /**
     * pgpass-файл: пароль уходит только через PGPASSFILE, никогда —
     * в аргументы команды, логи или сериализованный job.
     *
     * @param  array<string, mixed>  $conn
     */
    private function writePgPass(string $tmpDir, array $conn): string
    {
        $escape = static fn (string $value): string => str_replace(['\\', ':'], ['\\\\', '\\:'], $value);

        $line = sprintf(
            "%s:%s:%s:%s:%s\n",
            $escape((string) $conn['host']),
            $escape((string) ($conn['port'] ?? 5432)),
            $escape((string) $conn['database']),
            $escape((string) $conn['username']),
            $escape((string) ($conn['password'] ?? '')),
        );

        $path = $tmpDir.'/pgpass';

        if (file_put_contents($path, $line) === false) {
            throw new BackupException('Не удалось записать файл авторизации PostgreSQL.');
        }

        chmod($path, 0600);

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseConfig(): array
    {
        $default = (string) config('database.default');
        $conn = config("database.connections.{$default}");

        if (! is_array($conn) || ($conn['driver'] ?? null) !== 'pgsql') {
            throw new BackupException('Резервирование поддерживается только для PostgreSQL.');
        }

        return $conn;
    }

    private function postgresVersion(): string
    {
        $rows = DB::select('select version() as version');

        return (string) ($rows[0]->version ?? '');
    }

    private function objectKey(DatabaseBackup $backup): string
    {
        $prefix = trim((string) config('backups.prefix'), '/');
        $environment = (string) config('app.env');
        $day = ($backup->requested_at ?? now())->setTimezone('UTC');

        return sprintf(
            '%s/%s/%s/%s/%s/%s/%s.dump',
            $prefix,
            $environment,
            $backup->trigger_type->value,
            $day->format('Y'),
            $day->format('m'),
            $day->format('d'),
            $backup->getKey(),
        );
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('backups.disk'));
    }

    private static function stderrSuffix(BackupProcessResult $result): string
    {
        $detail = BackupError::fromStderr($result->stderr);

        return $detail !== '' ? ' '.$detail : '';
    }
}
