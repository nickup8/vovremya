<?php

namespace App\Services\Backups;

use App\Enums\BackupStatus;
use App\Enums\BackupTrigger;
use App\Exceptions\Backups\BackupAlreadyRunning;
use App\Exceptions\Backups\BackupCaptureLost;
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
 *
 * Атомарность постановки: при database-очереди на том же DB-соединении
 * строка database_backups и job в jobs коммитятся одной транзакцией.
 * Иначе — create, затем dispatch; сбой dispatch помечает копию failed
 * (см. docs/DB_BACKUPS.md, раздел про BACKUP_DB_QUEUE_CONNECTION).
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
     * @throws BackupException
     */
    public function request(BackupTrigger $trigger, ?User $user = null): DatabaseBackup
    {
        $this->recoverActiveBackups();

        $payload = [
            'trigger_type' => $trigger->value,
            'status' => BackupStatus::Queued,
            'requested_by' => $user?->getKey(),
            'requested_at' => now(),
            'dedup_key' => $trigger === BackupTrigger::Scheduled
                ? now(config('backups.scheduled.timezone', 'Europe/Moscow'))->format('Y-m-d')
                : null,
        ];

        if ($this->canDispatchAtomically()) {
            try {
                // DB::transaction даёт savepoint: в PostgreSQL нарушение unique
                // не должно оставлять внешнюю транзакцию в aborted-состоянии.
                // job в базе той же СУБД коммитится вместе со строкой копии.
                return DB::transaction(function () use ($payload) {
                    $backup = DatabaseBackup::create($payload);

                    $this->pushJob($backup);

                    return $backup;
                });
            } catch (UniqueConstraintViolationException) {
                throw $this->mapUniqueViolation($trigger);
            } catch (BackupException $e) {
                throw $e;
            } catch (Throwable $e) {
                // Сбой постановки внутри транзакции: строка откатилась,
                // вечного queued не остаётся.
                throw new BackupException(
                    'Не удалось поставить резервную копию в очередь: '.BackupError::fromThrowable($e),
                );
            }
        }

        try {
            $backup = DatabaseBackup::create($payload);
        } catch (UniqueConstraintViolationException) {
            throw $this->mapUniqueViolation($trigger);
        }

        try {
            $this->pushJob($backup);
        } catch (Throwable $e) {
            $message = 'Не удалось поставить задание в очередь: '.BackupError::fromThrowable($e);

            DatabaseBackup::query()
                ->whereKey($backup->getKey())
                ->where('status', BackupStatus::Queued->value)
                ->update([
                    'status' => BackupStatus::Failed->value,
                    'finished_at' => now(),
                    'error_message' => BackupError::sanitize($message),
                ]);

            $this->notifyRootsFailure(BackupError::sanitize($message));

            throw new BackupException($message);
        }

        return $backup;
    }

    /**
     * Периодическое восстановление активных состояний. Не зависит от
     * ежедневного расписания и доступно при выключенном автозапуске.
     *
     * Различает: queued с job (ждёт живого воркера), queued без job
     * (потерянное задание → повторная постановка), running stale
     * (прерванное выполнение → failed + очистка незавершённых объектов).
     */
    public function recoverActiveBackups(): void
    {
        $this->reclaimStaleRunning();
        $this->redispatchLostQueued();
    }

    /**
     * Полный цикл создания копии. Любой сбой → безопасная запись ошибки,
     * database-уведомление ROOT, очистка незавершённых объектов и rethrow
     * УЖЕ санитизированного BackupException (без исходного previous).
     */
    public function run(DatabaseBackup $backup): void
    {
        $token = $this->begin($backup);

        $tmpDir = null;
        $intendedKeys = [];

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
            $manifestKey = $objectKey.'.manifest.json';

            // Учёт намерения ДО HTTP: recovery/ошибка смогут убрать объекты.
            $this->recordIntendedKeys($backup, $token, $objectKey, $manifestKey);
            $intendedKeys = [$objectKey, $manifestKey];

            $this->uploadVerified($dumpPath, $objectKey, $size, $sha);
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

            $this->markSucceeded($backup, $token, $objectKey, $manifestKey, $size, $sha, $pgVersion, $appVersion);
            $this->pruneOld($backup);
        } catch (Throwable $e) {
            $this->safeRecordFailure($backup, $token, $e);
            $this->cleanupIntendedObjects($backup, $intendedKeys);

            // Не пробрасываем исходное исключение: оно может содержать
            // секреты и попадёт в failed_jobs/логи воркера через цепочку.
            throw new BackupException(BackupError::fromThrowable($e));
        } finally {
            if ($tmpDir !== null) {
                self::deleteTree($tmpDir);
            }
        }
    }

    /**
     * Пометить зависшую running-копию прерванной и убрать её
     * незавершённые S3-объекты.
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
     * Вызывается из failed()-хука; уведомляет ROOT при доступной БД.
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
                    'execution_token' => null,
                ]);

            if ($affected > 0) {
                Log::error('Backups: копия помечена прерванной', ['backup_id' => $backupId, 'message' => BackupError::sanitize($message)]);

                $backup = DatabaseBackup::query()->find($backupId);

                if ($backup instanceof DatabaseBackup) {
                    $this->cleanupIntendedObjects($backup, [
                        $backup->object_key,
                        $backup->manifest_key,
                    ]);
                }

                $this->notifyRootsFailure(BackupError::sanitize($message));
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

    /**
     * Атомарный захват: queued→running либо (stale running→running)
     * одним CAS-UPDATE по status. Возвращает execution_token — только
     * его владелец может завершить/прервать эту попытку.
     *
     * @throws BackupException
     */
    private function begin(DatabaseBackup $backup): string
    {
        $token = (string) Str::uuid();
        $staleBefore = now()->subSeconds((int) config('backups.stale_after', 1800));

        $affected = DatabaseBackup::query()
            ->whereKey($backup->getKey())
            ->where(function ($query) use ($staleBefore) {
                $query->where('status', BackupStatus::Queued->value)
                    ->orWhere(function ($q) use ($staleBefore) {
                        $q->where('status', BackupStatus::Running->value)
                            ->where(function ($q2) use ($staleBefore) {
                                $q2->whereNull('started_at')
                                    ->orWhere('started_at', '<', $staleBefore);
                            });
                    });
            })
            ->update([
                'status' => BackupStatus::Running->value,
                'started_at' => now(),
                'execution_token' => $token,
            ]);

        if ($affected !== 1) {
            // Отдельный тип: job-доставка ловит его как no-op и не дёргает
            // failed()-хук, который иначе пометил бы прерванной чужой запуск.
            throw new BackupCaptureLost;
        }

        $backup->refresh();

        return $token;
    }

    /**
     * Записать ключи объектов до HTTP-загрузки (только своим токеном).
     *
     * @throws BackupException
     */
    private function recordIntendedKeys(DatabaseBackup $backup, string $token, string $objectKey, string $manifestKey): void
    {
        $affected = DatabaseBackup::query()
            ->whereKey($backup->getKey())
            ->where('status', BackupStatus::Running->value)
            ->where('execution_token', $token)
            ->update([
                'object_key' => $objectKey,
                'manifest_key' => $manifestKey,
            ]);

        if ($affected !== 1) {
            throw new BackupException('Владение выполнением потеряно — загрузка отменена.');
        }

        $backup->refresh();
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

    /**
     * @throws BackupException
     */
    private function markSucceeded(
        DatabaseBackup $backup,
        string $token,
        string $objectKey,
        string $manifestKey,
        int $size,
        string $sha,
        string $pgVersion,
        string $appVersion,
    ): void {
        $affected = DatabaseBackup::query()
            ->whereKey($backup->getKey())
            ->where('status', BackupStatus::Running->value)
            ->where('execution_token', $token)
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
                'execution_token' => null,
            ]);

        if ($affected !== 1) {
            throw new BackupException('Результат не записан: исполнение устарело или передано другому процессу.');
        }

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
     * Удаляются только объекты нашего namespace (prefix/env/type) и только
     * с проверкой false от delete; сбой очистки не делает новую копию
     * невалидной — пишется prune_error_message.
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

                    $this->assertOwnedKeyForBackup($key, $old);

                    if ($disk->exists($key)) {
                        $deleted = $disk->delete($key);

                        if ($deleted === false) {
                            throw new BackupException('S3 delete вернул false для '.$key.'.');
                        }
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

    /**
     * Namespace владения: {prefix}/{env}/{trigger}/… и полный id копии
     * в имени объекта. Чужие ключи, другие env/тип и живые success-копии
     * не затрагиваются.
     */
    private function assertOwnedKeyForBackup(string $key, DatabaseBackup $backup): void
    {
        $namespace = $this->objectNamespace($backup);
        $id = $backup->getKey();

        $owned = str_starts_with($key, $namespace)
            && (str_ends_with($key, '/'.$id.'.dump') || str_ends_with($key, '/'.$id.'.dump.manifest.json'));

        if (! $owned) {
            throw new BackupException('Объект вне namespace запуска — удаление отменено.');
        }
    }

    private function objectNamespace(DatabaseBackup $backup): string
    {
        return sprintf(
            '%s/%s/%s/',
            trim((string) config('backups.prefix'), '/'),
            (string) config('app.env'),
            $backup->trigger_type->value,
        );
    }

    /**
     * Удалить незавершённые объекты этого запуска (только свои ключи).
     *
     * @param  list<string|null>  $keys
     */
    private function cleanupIntendedObjects(DatabaseBackup $backup, array $keys): void
    {
        $keys = array_unique(array_filter($keys));

        if ($keys === []) {
            // Нечего убирать: не требуем настроенного S3-диска
            // (например, recover при ещё не загруженных ключах).
            return;
        }

        $disk = $this->disk();
        $errors = [];

        foreach ($keys as $key) {
            try {
                $this->assertOwnedKeyForBackup($key, $backup);

                if ($disk->exists($key)) {
                    $deleted = $disk->delete($key);

                    if ($deleted === false) {
                        throw new BackupException('S3 delete вернул false для '.$key.'.');
                    }
                }
            } catch (Throwable $e) {
                $errors[] = BackupError::fromThrowable($e);
                Log::warning('Backups: не удалось удалить незавершённый объект', [
                    'backup_id' => $backup->getKey(),
                    'key' => $key,
                    'error' => BackupError::fromThrowable($e),
                ]);
            }
        }

        if ($errors !== []) {
            $message = 'Очистка незавершённых объектов: '.implode(' | ', array_unique($errors));

            DatabaseBackup::query()
                ->whereKey($backup->getKey())
                ->update(['prune_error_message' => BackupError::sanitize($message)]);

            $backup->refresh();
        }
    }

    private function markInterrupted(DatabaseBackup $backup): void
    {
        $affected = DatabaseBackup::query()
            ->whereKey($backup->getKey())
            ->where('status', BackupStatus::Running->value)
            ->update([
                'status' => BackupStatus::Failed->value,
                'finished_at' => now(),
                'error_message' => 'Процесс прерван (устаревшее состояние).',
                'execution_token' => null,
            ]);

        if ($affected !== 1) {
            return;
        }

        $backup->refresh();

        $this->cleanupIntendedObjects($backup, [
            $backup->object_key,
            $backup->manifest_key,
        ]);

        Log::warning('Backups: зависшая копия помечена прерванной', ['backup_id' => $backup->getKey()]);

        $this->notifyRootsFailure('Резервная копия прервана (устаревшее состояние): '.$backup->getKey());

        if ($backup->trigger_type === BackupTrigger::Manual) {
            $this->auditManualResult($backup, 'backup.failed', [
                'backup_id' => $backup->getKey(),
                'reason' => 'stale',
            ]);
        }
    }

    private function safeRecordFailure(DatabaseBackup $backup, string $token, Throwable $e): void
    {
        $message = BackupError::fromThrowable($e);

        try {
            DatabaseBackup::query()
                ->whereKey($backup->getKey())
                ->where('status', BackupStatus::Running->value)
                ->where('execution_token', $token)
                ->update([
                    'status' => BackupStatus::Failed->value,
                    'finished_at' => now(),
                    'error_message' => $message,
                    'execution_token' => null,
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

    /**
     * queued без job в базе очереди: задание потеряно (сбой постановки,
     * ручное вмешательство). Повторная постановка; сбой — failed, не queued.
     */
    private function redispatchLostQueued(): void
    {
        $graceSeconds = max(30, (int) config('backups.retry_after', 1200));

        DatabaseBackup::query()
            ->where('status', BackupStatus::Queued->value)
            ->where('requested_at', '<', now()->subSeconds($graceSeconds))
            ->orderBy('requested_at')
            ->take(20)
            ->get()
            ->each(function (DatabaseBackup $backup) {
                if ($this->queueHasJob($backup)) {
                    return;
                }

                try {
                    $this->pushJob($backup);

                    Log::warning('Backups: потерянное задание повторно поставлено в очередь', [
                        'backup_id' => $backup->getKey(),
                    ]);
                } catch (Throwable $e) {
                    $message = 'Задание потеряно и не восстановлено: '.BackupError::fromThrowable($e);

                    DatabaseBackup::query()
                        ->whereKey($backup->getKey())
                        ->where('status', BackupStatus::Queued->value)
                        ->update([
                            'status' => BackupStatus::Failed->value,
                            'finished_at' => now(),
                            'error_message' => BackupError::sanitize($message),
                        ]);

                    $this->notifyRootsFailure(BackupError::sanitize($message));

                    Log::error('Backups: не удалось восстановить потерянное задание', [
                        'backup_id' => $backup->getKey(),
                        'error' => BackupError::fromThrowable($e),
                    ]);
                }
            });
    }

    private function queueHasJob(DatabaseBackup $backup): bool
    {
        try {
            $config = config('queue.connections.backups');

            if (! is_array($config) || ($config['driver'] ?? null) !== 'database') {
                // Не-базовая очередь: наличие job не проверяем — не рискуем
                // двойной постановкой.
                return true;
            }

            $connection = $config['connection'] ?? null;
            $connection = ($connection === null || $connection === '') ? null : $connection;
            $table = (string) ($config['table'] ?? 'jobs');
            $queue = (string) ($config['queue'] ?? 'backups');

            return DB::connection($connection)
                ->table($table)
                ->where('queue', $queue)
                ->where('payload', 'like', '%'.$backup->getKey().'%')
                ->exists();
        } catch (Throwable) {
            // Не можем проверить — считаем, что job ещё в очереди.
            return true;
        }
    }

    /**
     * database-driver на том же соединении, что и приложение, и без
     * after_commit: job INSERT участвует в той же транзакции.
     */
    private function canDispatchAtomically(): bool
    {
        $config = config('queue.connections.backups');

        if (! is_array($config) || ($config['driver'] ?? null) !== 'database') {
            return false;
        }

        if (! empty($config['after_commit'])) {
            return false;
        }

        $queueConnection = $config['connection'] ?? null;

        if ($queueConnection === null || $queueConnection === '') {
            return true;
        }

        return $queueConnection === config('database.default');
    }

    /**
     * Вынесено protected — в тестах подменяется сбой постановки job.
     */
    protected function pushJob(DatabaseBackup $backup): void
    {
        RunDatabaseBackupJob::dispatch($backup->getKey())
            ->onConnection('backups')
            ->onQueue('backups');
    }

    private function mapUniqueViolation(BackupTrigger $trigger): BackupException|BackupAlreadyRunning|DuplicateScheduledBackup
    {
        // Какой из partial-unique индексов сработал: активная копия
        // (общий запрет параллельности) или дедуп scheduled за день.
        $hasActive = DatabaseBackup::query()
            ->whereIn('status', [BackupStatus::Queued->value, BackupStatus::Running->value])
            ->exists();

        if ($hasActive || $trigger === BackupTrigger::Manual) {
            return new BackupAlreadyRunning;
        }

        return new DuplicateScheduledBackup;
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
        return $this->objectNamespace($backup).sprintf(
            '%s/%s/%s/%s.dump',
            ($backup->requested_at ?? now())->setTimezone('UTC')->format('Y'),
            ($backup->requested_at ?? now())->setTimezone('UTC')->format('m'),
            ($backup->requested_at ?? now())->setTimezone('UTC')->format('d'),
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
