<?php

namespace Tests\Feature\Backups;

use App\Enums\BackupStatus;
use App\Enums\BackupTrigger;
use App\Exceptions\Backups\BackupAlreadyRunning;
use App\Exceptions\Backups\BackupCaptureLost;
use App\Exceptions\Backups\BackupException;
use App\Jobs\RunDatabaseBackupJob;
use App\Models\DatabaseBackup;
use App\Models\User;
use App\Services\Backups\BackupProcessRunner;
use App\Services\Backups\DatabaseBackupService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\Backups\BackupDiskStub;
use Tests\Support\Backups\FakeBackupProcessRunner;
use Tests\TestCase;

class DatabaseBackupServiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeBackupProcessRunner $runner;

    private DatabaseBackupService $service;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = storage_path('framework/testing/backups-svc-'.uniqid('', true));

        config([
            'backups.disk' => 'backups_s3',
            'backups.prefix' => 'irsi-app',
            'backups.tmp_dir' => $this->tmpDir,
            'backups.keep.manual' => 7,
            'backups.keep.scheduled' => 7,
            'backups.process_timeout' => 600,
            'backups.job_timeout' => 900,
            'backups.retry_after' => 1200,
            'backups.stale_after' => 1800,
            'backups.scheduled.enabled' => false,
            'queue.connections.backups.driver' => 'database',
            'queue.connections.backups.connection' => null,
            'queue.connections.backups.after_commit' => false,
        ]);

        Storage::fake('backups_s3');

        $this->runner = new FakeBackupProcessRunner;
        $this->app->instance(BackupProcessRunner::class, $this->runner);
        $this->service = $this->app->make(DatabaseBackupService::class);
    }

    protected function tearDown(): void
    {
        DatabaseBackupService::deleteTree($this->tmpDir);

        parent::tearDown();
    }

    private function makeQueued(BackupTrigger $trigger = BackupTrigger::Manual, ?User $user = null): DatabaseBackup
    {
        return DatabaseBackup::create([
            'trigger_type' => $trigger->value,
            'status' => BackupStatus::Queued,
            'requested_by' => $user?->getKey(),
            'requested_at' => now(),
            'dedup_key' => $trigger === BackupTrigger::Scheduled
                ? now('Europe/Moscow')->format('Y-m-d')
                : null,
        ]);
    }

    private function makeSuccess(BackupTrigger $trigger, int $minutesAgo, string $keySuffix = ''): DatabaseBackup
    {
        // id не в fillable: create() генерирует свой UUID, поэтому ключ
        // объекта строим по фактическому ID уже сохранённой записи.
        $backup = DatabaseBackup::create([
            'trigger_type' => $trigger->value,
            'status' => BackupStatus::Success,
            'requested_at' => now()->subMinutes($minutesAgo + 1),
            'finished_at' => now()->subMinutes($minutesAgo),
            'size_bytes' => 123,
            'sha256' => str_repeat('a', 64),
        ]);

        $objectKey = sprintf('irsi-app/testing/%s/2026/01/01/%s%s.dump', $trigger->value, $backup->getKey(), $keySuffix);

        $backup->update([
            'object_key' => $objectKey,
            'manifest_key' => $objectKey.'.manifest.json',
        ]);

        Storage::disk('backups_s3')->put($objectKey, 'old-dump');
        Storage::disk('backups_s3')->put($objectKey.'.manifest.json', '{}');

        return $backup;
    }

    private function invokePrivate(string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod(DatabaseBackupService::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke($this->service, ...$args);
    }

    public function test_successful_backup_flow_verifies_s3_and_marks_success(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => true]);
        $backup = $this->makeQueued(BackupTrigger::Manual, $admin);

        $this->service->run($backup);

        $backup->refresh();

        $this->assertSame(BackupStatus::Success, $backup->status);
        $this->assertNotNull($backup->finished_at);
        $this->assertNotNull($backup->started_at);
        $this->assertSame(strlen($this->runner->dumpContent), $backup->size_bytes);
        $this->assertSame(hash('sha256', $this->runner->dumpContent), $backup->sha256);
        $this->assertNotEmpty($backup->pg_version);
        $this->assertSame((string) config('app.version'), $backup->app_version);
        $this->assertNull($backup->error_message);
        $this->assertNull($backup->execution_token);

        $this->assertNotNull($backup->object_key);
        $this->assertStringStartsWith('irsi-app/testing/manual/', $backup->object_key);
        $this->assertStringEndsWith('/'.$backup->getKey().'.dump', $backup->object_key);
        $this->assertSame($backup->object_key.'.manifest.json', $backup->manifest_key);

        $disk = Storage::disk('backups_s3');
        $disk->assertExists($backup->object_key);
        $disk->assertExists($backup->manifest_key);

        $manifest = json_decode((string) $disk->get($backup->manifest_key), true);
        $this->assertSame($backup->getKey(), $manifest['backup_id']);
        $this->assertSame($backup->sha256, $manifest['sha256']);
        $this->assertSame($backup->size_bytes, $manifest['size_bytes']);
        $this->assertSame('manual', $manifest['trigger']);
        $this->assertSame((string) config('app.version'), $manifest['app_version']);

        // Временные файлы (дамп и pgpass) убраны.
        $this->assertDirectoryDoesNotExist($this->tmpDir.'/'.$backup->getKey());

        // Ручной результат — в аудите.
        $this->assertDatabaseHas('super_admin_audit_logs', [
            'super_admin_id' => $admin->id,
            'action' => 'backup.succeeded',
        ]);
    }

    public function test_pg_dump_password_never_appears_in_arguments_env_or_serialized_job(): void
    {
        $backup = $this->makeQueued();

        // Меняем пароль только в конфиге — соединение уже установлено в setUp.
        config(['database.connections.pgsql.password' => 'S3cret-Passw0rd!']);

        $this->service->run($backup);

        $this->assertFalse($this->runner->containsPasswordInAnyCommand('S3cret-Passw0rd!'));

        // pgpass-файл существовал с паролем и удалён после очистки.
        $this->assertSame([], glob($this->tmpDir.'/*') ?: []);

        $serialized = serialize(new RunDatabaseBackupJob($backup->getKey()));
        $this->assertStringNotContainsString('S3cret-Passw0rd!', $serialized);
        $this->assertStringNotContainsString('password', $serialized);
    }

    public function test_pg_dump_nonzero_exit_marks_failed_and_notifies_root(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => true]);
        $backup = $this->makeQueued(BackupTrigger::Manual, $admin);

        $this->runner->dumpExitCode = 1;
        $this->runner->dumpStderr = 'pg_dump: error: connection failed';

        try {
            $this->service->run($backup);
            $this->fail('Ожидалось исключение BackupException');
        } catch (BackupException) {
            // ожидаемо
        }

        $backup->refresh();
        $this->assertSame(BackupStatus::Failed, $backup->status);
        $this->assertNotNull($backup->finished_at);
        $this->assertStringContainsString('кодом 1', (string) $backup->error_message);
        $this->assertStringContainsString('connection failed', (string) $backup->error_message);

        Storage::disk('backups_s3')->assertDirectoryEmpty('irsi-app');

        $this->assertDatabaseHas('super_admin_audit_logs', [
            'super_admin_id' => $admin->id,
            'action' => 'backup.failed',
        ]);

        $this->assertGreaterThan(0, $admin->notifications()->count());

        $this->assertDirectoryDoesNotExist($this->tmpDir.'/'.$backup->getKey());
    }

    public function test_pg_dump_timeout_marks_failed_and_notifies_root(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => true]);
        $backup = $this->makeQueued(BackupTrigger::Manual, $admin);

        $this->runner->dumpTimedOut = true;

        try {
            $this->service->run($backup);
            $this->fail('Ожидалось исключение BackupException');
        } catch (BackupException $e) {
            $this->assertStringContainsString('таймаут', $e->getMessage());
        }

        $backup->refresh();
        $this->assertSame(BackupStatus::Failed, $backup->status);
        $this->assertStringContainsString('таймаут', (string) $backup->error_message);
        $this->assertGreaterThan(0, $admin->notifications()->count());
    }

    public function test_empty_dump_file_marks_failed(): void
    {
        $backup = $this->makeQueued();

        $this->runner->skipDumpFile = true;

        try {
            $this->service->run($backup);
            $this->fail('Ожидалось исключение BackupException');
        } catch (BackupException) {
            // ожидаемо
        }

        $backup->refresh();
        $this->assertSame(BackupStatus::Failed, $backup->status);
        $this->assertStringContainsString('не создал файл', (string) $backup->error_message);
    }

    public function test_pg_restore_list_failure_marks_failed(): void
    {
        $backup = $this->makeQueued();

        $this->runner->restoreExitCode = 1;

        try {
            $this->service->run($backup);
            $this->fail('Ожидалось исключение BackupException');
        } catch (BackupException) {
            // ожидаемо
        }

        $backup->refresh();
        $this->assertSame(BackupStatus::Failed, $backup->status);
        $this->assertStringContainsString('pg_restore --list', (string) $backup->error_message);

        Storage::disk('backups_s3')->assertDirectoryEmpty('irsi-app');
    }

    public function test_s3_write_failure_marks_failed_without_success(): void
    {
        $backup = $this->makeQueued();

        $stub = new BackupDiskStub(Storage::disk('backups_s3'));
        $stub->failWrites = true;
        Storage::set('backups_s3', $stub);

        try {
            $this->service->run($backup);
            $this->fail('Ожидалось исключение BackupException');
        } catch (BackupException) {
            // безопасный rethrow вместо исходного RuntimeException
        }

        $backup->refresh();
        $this->assertSame(BackupStatus::Failed, $backup->status);
    }

    public function test_s3_size_or_sha_mismatch_marks_failed_and_removes_objects(): void
    {
        $backup = $this->makeQueued();

        $stub = new BackupDiskStub(Storage::disk('backups_s3'));
        $stub->tamperReads = true;
        Storage::set('backups_s3', $stub);

        try {
            $this->service->run($backup);
            $this->fail('Ожидалось исключение BackupException');
        } catch (BackupException $e) {
            $this->assertStringContainsString('Проверка объекта в S3 не пройдена', $e->getMessage());
        }

        $backup->refresh();
        $this->assertSame(BackupStatus::Failed, $backup->status);
        $this->assertStringContainsString('Проверка объекта в S3 не пройдена', (string) $backup->error_message);

        // Незавершённый dump удалён cleanup'ом (намерение было записано).
        $this->assertNotNull($backup->object_key);
        $this->assertFalse(Storage::disk('backups_s3')->exists((string) $backup->object_key));
    }

    public function test_failure_after_dump_upload_removes_dump_object(): void
    {
        $backup = $this->makeQueued();

        // Manifest-загрузка падает: dump уже в бакете.
        $stub = new class(Storage::disk('backups_s3')) extends BackupDiskStub
        {
            private int $writes = 0;

            public function writeStream($path, $resource, array $options = [])
            {
                $this->writes++;

                if ($this->writes > 1) {
                    throw new RuntimeException('manifest upload failed');
                }

                return parent::writeStream($path, $resource, $options);
            }
        };
        Storage::set('backups_s3', $stub);

        try {
            $this->service->run($backup);
            $this->fail('Ожидалось исключение BackupException');
        } catch (BackupException) {
            // ожидаемо
        }

        $backup->refresh();
        $this->assertSame(BackupStatus::Failed, $backup->status);

        $objectKey = (string) $backup->object_key;
        $this->assertFalse(Storage::disk('backups_s3')->exists($objectKey), 'dump должен быть удалён после сбоя manifest');
        $this->assertFalse(Storage::disk('backups_s3')->exists($objectKey.'.manifest.json'));
    }

    public function test_delete_false_is_recorded_as_prune_error_on_cleanup(): void
    {
        $backup = $this->makeQueued();

        $stub = new BackupDiskStub(Storage::disk('backups_s3'));
        $stub->tamperReads = true;
        $stub->deleteReturnsFalse = true;
        Storage::set('backups_s3', $stub);

        try {
            $this->service->run($backup);
        } catch (BackupException) {
            // ожидаемо
        }

        $backup->refresh();
        $this->assertSame(BackupStatus::Failed, $backup->status);
        $this->assertNotNull($backup->prune_error_message);
        $this->assertStringContainsString('delete', (string) $backup->prune_error_message);
        $this->assertNotEmpty($stub->deleted);
    }

    public function test_cleanup_does_not_touch_foreign_or_other_env_keys(): void
    {
        $backup = $this->makeQueued();

        // Чужой ключ в другом namespace и «успешная» чужая копия.
        Storage::disk('backups_s3')->put('other-app/production/manual/x.dump', 'foreign');
        $foreign = $this->makeSuccess(BackupTrigger::Manual, 50);

        $stub = new BackupDiskStub(Storage::disk('backups_s3'));
        $stub->tamperReads = true;
        Storage::set('backups_s3', $stub);

        try {
            $this->service->run($backup);
        } catch (BackupException) {
            // ожидаемо
        }

        $this->assertTrue(Storage::disk('backups_s3')->exists('other-app/production/manual/x.dump'));
        $this->assertTrue(Storage::disk('backups_s3')->exists((string) $foreign->object_key));
        $this->assertNotContains('other-app/production/manual/x.dump', $stub->deleted);
    }

    public function test_existing_object_is_never_overwritten(): void
    {
        $backup = $this->makeQueued();

        // Объект уже лежит по ключу, который это исполнение собирается
        // занять: ключ зарезервирован, но объект не наш.
        $stub = new class(Storage::disk('backups_s3')) extends BackupDiskStub
        {
            public bool $seeded = false;

            public string $seededKey = '';

            public string $seededContent = "pre-existing\0bytes";

            public function exists($path)
            {
                if (! $this->seeded && str_ends_with((string) $path, '.dump')) {
                    $this->seeded = true;
                    $this->seededKey = (string) $path;
                    $this->put($this->seededKey, $this->seededContent);
                }

                return parent::exists($path);
            }
        };
        Storage::set('backups_s3', $stub);

        try {
            $this->service->run($backup);
            $this->fail('Ожидалось исключение BackupException');
        } catch (BackupException $e) {
            $this->assertStringContainsString('перезапись отменена', $e->getMessage());
        }

        $backup->refresh();
        $this->assertSame(BackupStatus::Failed, $backup->status);

        // Отказ при exists=true: найденный объект остаётся байт-в-байт.
        // Намерение загрузки не даёт права его удалить.
        $this->assertTrue($stub->seeded);
        $this->assertTrue(Storage::disk('backups_s3')->exists($stub->seededKey));
        $this->assertSame($stub->seededContent, Storage::disk('backups_s3')->get($stub->seededKey));
    }

    public function test_rotation_exception_after_success_keeps_new_copy_and_records_prune_error(): void
    {
        config(['backups.keep.manual' => 1]);

        $old = $this->makeSuccess(BackupTrigger::Manual, 100);

        $service = new class(app(BackupProcessRunner::class)) extends DatabaseBackupService
        {
            protected function pruneOld(DatabaseBackup $current): void
            {
                throw new BackupException('Ротация недоступна (тест).');
            }
        };

        $backup = $this->makeQueued(BackupTrigger::Manual);
        $service->run($backup);

        $fresh = $backup->fresh();
        $this->assertSame(BackupStatus::Success, $fresh->status);
        $this->assertNull($fresh->error_message);
        $this->assertNotNull($fresh->sha256);

        // Новая копия и manifest после подтверждённого success не тронуты
        // cleanup'ом; SHA сходится с содержимым объекта.
        $disk = Storage::disk('backups_s3');
        $this->assertNotNull($fresh->object_key);
        $disk->assertExists((string) $fresh->object_key);
        $disk->assertExists((string) $fresh->manifest_key);
        $this->assertSame($fresh->sha256, hash('sha256', (string) $disk->get((string) $fresh->object_key)));

        // Ошибка ротации записана отдельно, копия не переведена в failed.
        $this->assertStringContainsString('Ротация недоступна', (string) $fresh->prune_error_message);
        $this->assertDatabaseHas('database_backups', ['id' => $old->getKey()]);
        $this->assertTrue($disk->exists((string) $old->object_key));
    }

    public function test_late_execution_after_stale_reclaim_keeps_new_run_success_and_files(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => true]);
        $backup = $this->makeQueued(BackupTrigger::Manual, $admin);

        // Исполнение A зависло на загрузке дампа: started_at устаревает,
        // другой воркер перехватывает копию и доводит её до success.
        $stub = new class(Storage::disk('backups_s3')) extends BackupDiskStub
        {
            public ?DatabaseBackupService $service = null;

            public ?DatabaseBackup $backup = null;

            public bool $intercepted = false;

            /** @var list<string> */
            public array $written = [];

            public function writeStream($path, $resource, array $options = [])
            {
                if (! $this->intercepted) {
                    $this->intercepted = true;

                    DatabaseBackup::query()->update(['started_at' => now()->subHours(2)]);
                    $this->service->run($this->backup->fresh());
                }

                $this->written[] = (string) $path;

                return parent::writeStream($path, $resource, $options);
            }
        };
        $stub->service = $this->service;
        $stub->backup = $backup;
        Storage::set('backups_s3', $stub);

        try {
            $this->service->run($backup);
            $this->fail('Ожидалось исключение BackupException');
        } catch (BackupException) {
            // запоздавшее исполнение A проиграло владение — ожидаемо
        }

        $fresh = $backup->fresh();
        $this->assertSame(BackupStatus::Success, $fresh->status);
        $this->assertNull($fresh->execution_token);
        $this->assertNull($fresh->error_message);

        // Файлы успешного исполнения B не удалены и не перезаписаны.
        $disk = Storage::disk('backups_s3');
        $this->assertTrue($disk->exists((string) $fresh->object_key));
        $this->assertTrue($disk->exists((string) $fresh->manifest_key));
        $this->assertSame($fresh->sha256, hash('sha256', (string) $disk->get((string) $fresh->object_key)));

        // Cleanup запоздавшего A убрал только собственный объект:
        // записи — dump B, manifest B, затем dump A; удалён только A.
        $this->assertCount(3, $stub->written);
        $this->assertSame($fresh->object_key, $stub->written[0]);
        $lateKey = $stub->written[2];
        $this->assertNotSame($fresh->object_key, $lateKey);
        $this->assertFalse($disk->exists($lateKey));

        // Ложное уведомление о сбое чужого запуска не отправлено.
        $this->assertSame(0, $admin->notifications()->count());
        $this->assertDatabaseHas('super_admin_audit_logs', [
            'super_admin_id' => $admin->id,
            'action' => 'backup.succeeded',
        ]);
        $this->assertDatabaseMissing('super_admin_audit_logs', [
            'super_admin_id' => $admin->id,
            'action' => 'backup.failed',
        ]);
    }

    /**
     * Полный сценарий позднего исполнения: A зависла на первой загрузке,
     * исполнение B перехватило строку и завершилось успехом; продолживший A
     * потерял владение, его cleanup отказался (S3 delete падает). Возвращает
     * копию и stub диска, чтобы тест сами проверяли исход очистки/recovery.
     *
     * @return array{DatabaseBackup, BackupDiskStub}
     */
    private function simulateLateCleanupAfterSupersededRun(): array
    {
        $healthy = Storage::disk('backups_s3');
        $backup = $this->makeQueued(BackupTrigger::Manual);

        $stub = new class($healthy) extends BackupDiskStub
        {
            public ?DatabaseBackupService $service = null;

            public ?DatabaseBackup $backup = null;

            public bool $intercepted = false;

            /** @var list<string> */
            public array $written = [];

            public function writeStream($path, $resource, array $options = [])
            {
                if (! $this->intercepted) {
                    $this->intercepted = true;

                    // A зависла на загрузке: строка устаревает, воркер B
                    // перехватывает копию и доводит её до success.
                    DatabaseBackup::query()->update(['started_at' => now()->subHours(2)]);
                    $this->service->run($this->backup->fresh());

                    // Отказ S3 delete достаётся только позднему A.
                    $this->failDeletes = true;
                }

                $this->written[] = (string) $path;

                return parent::writeStream($path, $resource, $options);
            }
        };
        $stub->service = $this->service;
        $stub->backup = $backup;
        Storage::set('backups_s3', $stub);

        try {
            $this->service->run($backup);
            $this->fail('Ожидалось исключение BackupException');
        } catch (BackupException) {
            // позднее исполнение A проиграло владение — ожидаемо
        }

        return [$backup, $stub];
    }

    public function test_recovery_after_late_cleanup_failure_keeps_successful_copy_of_new_execution(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => true]);
        $healthy = Storage::disk('backups_s3');

        [$backup, $stub] = $this->simulateLateCleanupAfterSupersededRun();

        $afterA = $backup->fresh();
        $successKey = (string) $afterA->object_key;
        $successManifest = (string) $afterA->manifest_key;
        $lateKey = $stub->written[2];

        // Строка принадлежит успешному исполнению B, остаток A лежит в бакете.
        $this->assertSame(BackupStatus::Success, $afterA->status);
        $this->assertNotSame($lateKey, $successKey);
        $this->assertTrue($healthy->exists($lateKey));
        $this->assertTrue($healthy->exists($successKey));
        $this->assertNotNull($afterA->cleanup_pending_at);

        // S3 восстановился — backups:recover повторяет отложенную очистку.
        Storage::set('backups_s3', $healthy);
        $this->service->recoverActiveBackups();

        $recovered = $backup->fresh();
        $this->assertSame(BackupStatus::Success, $recovered->status);
        $this->assertSame($afterA->sha256, $recovered->sha256);

        // Копия и manifest B пережили recovery, SHA сходится с содержимым.
        $this->assertTrue($healthy->exists($successKey), 'успешная копия B должна пережить recovery');
        $this->assertTrue($healthy->exists($successManifest), 'manifest B должен пережить recovery');
        $this->assertSame($recovered->sha256, hash('sha256', (string) $healthy->get($successKey)));

        // Остаток A убран — и только по принадлежавшим A ключам.
        $this->assertFalse($healthy->exists($lateKey), 'остаток A должен быть убран recovery');

        $this->assertNull($recovered->cleanup_pending_at);
        $this->assertNull($recovered->prune_error_message);
        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_late_cleanup_failure_records_owned_keys_and_token_for_recovery(): void
    {
        [$backup, $stub] = $this->simulateLateCleanupAfterSupersededRun();

        $fresh = $backup->fresh();
        $lateKey = $stub->written[2];

        // Отложенная очистка фиксирует КОНКРЕТНЫЕ ключи и исполняющий токен,
        // а не текущие object_key/manifest_key строки (там уже ключи B).
        $this->assertSame(BackupStatus::Success, $fresh->status);
        $this->assertSame([$lateKey], $fresh->cleanup_keys);

        $segments = explode('/', $lateKey);
        $lateToken = (string) $segments[count($segments) - 2];
        $this->assertSame($lateToken, $fresh->cleanup_execution_token);
        $this->assertNotSame($fresh->execution_token, $lateToken);
    }

    public function test_read_error_after_successful_update_keeps_success_copy_and_status(): void
    {
        $backup = $this->makeQueued();

        // Первое чтение строки в статусе success — это refresh() в
        // markSucceeded сразу после UPDATE успеха: читаемая ошибка модели
        // падает именно там, между записью успеха и дальнейшими действиями.
        $state = (object) ['armed' => true];

        DatabaseBackup::retrieved(function (DatabaseBackup $model) use ($state) {
            if ($state->armed && $model->status === BackupStatus::Success) {
                $state->armed = false;

                throw new RuntimeException('connection reset while reading row');
            }
        });

        try {
            $this->service->run($backup);
        } catch (BackupException) {
            // после подтверждённого успеха копия не должна считаться сбоем;
            // главное — статус и объекты (проверяются ниже)
        }

        $state->armed = false;

        $fresh = $backup->fresh();
        $this->assertSame(BackupStatus::Success, $fresh->status);
        $this->assertNotNull($fresh->sha256);
        $this->assertSame(hash('sha256', $this->runner->dumpContent), $fresh->sha256);

        // Dump и manifest подтверждённого успеха не удалены cleanup'ом.
        $disk = Storage::disk('backups_s3');
        $this->assertNotNull($fresh->object_key);
        $disk->assertExists((string) $fresh->object_key);
        $disk->assertExists((string) $fresh->manifest_key);
        $this->assertSame($fresh->sha256, hash('sha256', (string) $disk->get((string) $fresh->object_key)));

        // Ошибка после успеха пишется отдельно, а не в error_message
        // (BackupError сводит исходное исключение к безопасному описанию).
        $this->assertNull($fresh->error_message);
        $this->assertStringContainsString('После записи результата', (string) $fresh->prune_error_message);
        $this->assertStringContainsString('RuntimeException', (string) $fresh->prune_error_message);
    }

    public function test_recovery_does_not_interrupt_execution_reclaimed_during_scan(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => true]);

        $stale = DatabaseBackup::create([
            'trigger_type' => BackupTrigger::Manual->value,
            'status' => BackupStatus::Running,
            'requested_at' => now()->subHours(2),
            'started_at' => now()->subHours(2),
            'execution_token' => (string) Str::uuid(),
        ]);

        // Гонка: recover прочитал stale-снимок, а до UPDATE другой процесс
        // перехватил копию (новый токен, свежий started_at).
        $state = (object) ['fired' => false];
        $reclaimedToken = (string) Str::uuid();

        DatabaseBackup::retrieved(function (DatabaseBackup $model) use ($state, $stale, $reclaimedToken) {
            if ($state->fired || $model->getKey() !== $stale->getKey()) {
                return;
            }

            $state->fired = true;

            DatabaseBackup::query()
                ->whereKey($stale->getKey())
                ->update([
                    'started_at' => now(),
                    'execution_token' => $reclaimedToken,
                ]);
        });

        Storage::disk('backups_s3')->put('irsi-app/testing/manual/leftover.dump', 'keep');

        $this->service->recoverActiveBackups();

        $fresh = $stale->fresh();
        $this->assertSame(BackupStatus::Running, $fresh->status);
        $this->assertSame($reclaimedToken, $fresh->execution_token);
        $this->assertNull($fresh->error_message);
        $this->assertSame(0, $admin->notifications()->count());
        $this->assertTrue(Storage::disk('backups_s3')->exists('irsi-app/testing/manual/leftover.dump'));
    }

    public function test_failed_cleanup_is_retried_by_recovery_and_scoped_to_unfinished_objects(): void
    {
        $healthyDisk = Storage::disk('backups_s3');

        $stub = new BackupDiskStub($healthyDisk);
        $stub->tamperReads = true;
        $stub->failDeletes = true;
        Storage::set('backups_s3', $stub);

        $backup = $this->makeQueued();

        try {
            $this->service->run($backup);
        } catch (BackupException) {
            // ожидаемо
        }

        $failed = $backup->fresh();
        $this->assertSame(BackupStatus::Failed, $failed->status);
        $this->assertNotNull($failed->object_key);
        $this->assertNotNull($failed->cleanup_pending_at);
        $this->assertNotEmpty($failed->prune_error_message);
        $this->assertTrue($healthyDisk->exists((string) $failed->object_key));

        // S3 восстановился; рядом — успешная копия и чужой файл.
        Storage::set('backups_s3', $healthyDisk);

        $successCopy = $this->makeSuccess(BackupTrigger::Scheduled, 60);
        $healthyDisk->put('other-app/production/manual/x.dump', 'foreign');

        $this->service->recoverActiveBackups();

        $cleaned = $backup->fresh();
        $this->assertNull($cleaned->cleanup_pending_at);
        $this->assertSame(1, (int) $cleaned->cleanup_attempts);
        $this->assertFalse($healthyDisk->exists((string) $failed->object_key));

        // Success-копия и чужой файл не тронуты; бакет не сканируется.
        $this->assertSame(BackupStatus::Success, $successCopy->fresh()->status);
        $this->assertTrue($healthyDisk->exists((string) $successCopy->object_key));
        $this->assertTrue($healthyDisk->exists('other-app/production/manual/x.dump'));
    }

    public function test_recovery_stops_cleanup_retries_after_limit(): void
    {
        config(['backups.cleanup_retry_max' => 2]);

        $backup = DatabaseBackup::create([
            'trigger_type' => BackupTrigger::Manual->value,
            'status' => BackupStatus::Failed,
            'requested_at' => now()->subHours(3),
            'finished_at' => now()->subHours(3),
            'error_message' => 'старый сбой',
        ]);

        $objectKey = sprintf(
            'irsi-app/testing/manual/2026/01/01/%s/%s.dump',
            (string) Str::uuid(),
            $backup->getKey(),
        );

        DatabaseBackup::query()->whereKey($backup->getKey())->update([
            'object_key' => $objectKey,
            'cleanup_pending_at' => now()->subHour(),
            'cleanup_attempts' => 2,
        ]);

        Storage::disk('backups_s3')->put($objectKey, 'leftover');

        $stub = new BackupDiskStub(Storage::disk('backups_s3'));
        Storage::set('backups_s3', $stub);

        $this->service->recoverActiveBackups();

        $this->assertSame([], $stub->deleted);
        $this->assertTrue(Storage::disk('backups_s3')->exists($objectKey));
        $this->assertNotNull($backup->fresh()->cleanup_pending_at);
    }

    public function test_cleanup_errors_are_sanitized_before_persisting(): void
    {
        $secret = 'S3Secret-MARKER-cleanup';
        config(['filesystems.disks.backups_s3.secret' => $secret]);

        $stub = new class(Storage::disk('backups_s3')) extends BackupDiskStub
        {
            public function delete($paths)
            {
                foreach ((array) $paths as $path) {
                    $this->deleted[] = (string) $path;
                }

                throw new BackupException('S3 delete failed secret=S3Secret-MARKER-cleanup');
            }
        };
        $stub->tamperReads = true;
        Storage::set('backups_s3', $stub);

        $backup = $this->makeQueued();

        try {
            $this->service->run($backup);
        } catch (BackupException) {
            // ожидаемо
        }

        $fresh = $backup->fresh();
        $this->assertNotNull($fresh->cleanup_pending_at);
        $this->assertStringNotContainsString($secret, (string) $fresh->prune_error_message);
        $this->assertStringContainsString('***', (string) $fresh->prune_error_message);
    }

    public function test_parallel_request_is_blocked_by_running_backup(): void
    {
        DatabaseBackup::create([
            'trigger_type' => BackupTrigger::Manual->value,
            'status' => BackupStatus::Running,
            'requested_at' => now()->subMinutes(5),
            'started_at' => now()->subMinutes(5),
        ]);

        $this->expectException(BackupAlreadyRunning::class);

        $this->service->request(BackupTrigger::Manual);
    }

    public function test_stale_running_backup_is_marked_interrupted_and_new_ones_allowed(): void
    {
        $stale = DatabaseBackup::create([
            'trigger_type' => BackupTrigger::Manual->value,
            'status' => BackupStatus::Running,
            'requested_at' => now()->subHours(2),
            'started_at' => now()->subHours(2),
        ]);

        Queue::fake();

        $new = $this->service->request(BackupTrigger::Manual);

        $stale->refresh();
        $this->assertSame(BackupStatus::Failed, $stale->status);
        $this->assertStringContainsString('прерван', (string) $stale->error_message);

        $this->assertSame(BackupStatus::Queued, $new->status);
        $this->assertNotSame($stale->getKey(), $new->getKey());
    }

    public function test_atomic_request_rolls_back_backup_when_dispatch_fails(): void
    {
        $service = new class(app(BackupProcessRunner::class)) extends DatabaseBackupService
        {
            protected function pushJob(DatabaseBackup $backup): void
            {
                throw new RuntimeException('queue connection refused');
            }
        };

        try {
            $service->request(BackupTrigger::Manual);
            $this->fail('Ожидалось исключение BackupException');
        } catch (BackupException $e) {
            $this->assertStringContainsString('очередь', $e->getMessage());
        }

        // Атомарный путь: строка откатилась вместе с job — вечного queued нет.
        $this->assertSame(0, DatabaseBackup::query()->where('status', BackupStatus::Queued->value)->count());
        $this->assertSame(0, DatabaseBackup::query()->count());
    }

    public function test_non_atomic_dispatch_failure_marks_failed_not_eternal_queed(): void
    {
        config(['queue.connections.backups.after_commit' => true]);

        $service = new class(app(BackupProcessRunner::class)) extends DatabaseBackupService
        {
            protected function pushJob(DatabaseBackup $backup): void
            {
                throw new RuntimeException('queue connection refused');
            }
        };

        try {
            $service->request(BackupTrigger::Manual);
            $this->fail('Ожидалось исключение BackupException');
        } catch (BackupException) {
            // ожидаемо
        }

        $backup = DatabaseBackup::sole();
        $this->assertSame(BackupStatus::Failed, $backup->status);
        $this->assertStringContainsString('очередь', (string) $backup->error_message);
    }

    public function test_recover_redispatches_lost_queued_without_job(): void
    {
        Queue::fake();

        $lost = DatabaseBackup::create([
            'trigger_type' => BackupTrigger::Manual->value,
            'status' => BackupStatus::Queued,
            'requested_at' => now()->subHours(2),
        ]);

        $this->service->recoverActiveBackups();

        Queue::assertPushed(RunDatabaseBackupJob::class, 1);
        $this->assertSame(BackupStatus::Queued, $lost->fresh()->status);
    }

    public function test_recover_leaves_queued_with_job_in_queue(): void
    {
        Queue::fake();

        $waiting = DatabaseBackup::create([
            'trigger_type' => BackupTrigger::Manual->value,
            'status' => BackupStatus::Queued,
            'requested_at' => now()->subHours(2),
        ]);

        // job уже в очереди — recover не должен дублировать постановку.
        DB::table(config('queue.connections.backups.table', 'jobs'))->insert([
            'queue' => 'backups',
            'payload' => json_encode(['data' => ['command' => serialize(new RunDatabaseBackupJob($waiting->getKey()))]]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ]);

        $this->service->recoverActiveBackups();

        Queue::assertNothingPushed();
        $this->assertSame(BackupStatus::Queued, $waiting->fresh()->status);
    }

    public function test_recover_marks_stale_running_interrupted_and_notifies(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => true]);

        $stale = DatabaseBackup::create([
            'trigger_type' => BackupTrigger::Manual->value,
            'status' => BackupStatus::Running,
            'requested_at' => now()->subHours(2),
            'started_at' => now()->subHours(2),
        ]);

        $this->service->recoverActiveBackups();

        $stale->refresh();
        $this->assertSame(BackupStatus::Failed, $stale->status);
        $this->assertGreaterThan(0, $admin->notifications()->count());

        // Повторный recover без нового stale — без повторного спама.
        $count = $admin->notifications()->count();
        $this->service->recoverActiveBackups();
        $this->assertSame($count, $admin->notifications()->count());
    }

    public function test_concurrent_begin_only_one_token_wins(): void
    {
        $backup = $this->makeQueued();

        $tokenA = $this->invokePrivate('begin', $backup->fresh());

        // Второй процесс: queued уже нет, running свежий — захват отменён.
        try {
            $this->invokePrivate('begin', $backup->fresh());
            $this->fail('Ожидалось исключение BackupCaptureLost');
        } catch (BackupCaptureLost $e) {
            $this->assertStringContainsString('уже обрабатывается', $e->getMessage());
        }

        $this->assertSame($tokenA, $backup->fresh()->execution_token);
        $this->assertSame(BackupStatus::Running, $backup->fresh()->status);
    }

    public function test_stale_running_can_be_reclaimed_with_new_token(): void
    {
        $backup = $this->makeQueued();
        $tokenA = $this->invokePrivate('begin', $backup->fresh());

        DatabaseBackup::query()
            ->whereKey($backup->getKey())
            ->update(['started_at' => now()->subHours(2)]);

        $tokenB = $this->invokePrivate('begin', $backup->fresh());

        $this->assertNotSame($tokenA, $tokenB);
        $this->assertSame($tokenB, $backup->fresh()->execution_token);
    }

    public function test_late_mark_succeeded_with_old_token_is_rejected(): void
    {
        $backup = $this->makeQueued();
        $tokenA = $this->invokePrivate('begin', $backup->fresh());

        DatabaseBackup::query()
            ->whereKey($backup->getKey())
            ->update(['started_at' => now()->subHours(2)]);

        $tokenB = $this->invokePrivate('begin', $backup->fresh());

        try {
            $this->invokePrivate(
                'markSucceeded',
                $backup->fresh(),
                $tokenA,
                'irsi-app/testing/manual/x.dump',
                'irsi-app/testing/manual/x.dump.manifest.json',
                1,
                str_repeat('a', 64),
                'PostgreSQL 16',
                'test',
            );
            $this->fail('Ожидалось исключение BackupException');
        } catch (BackupException $e) {
            $this->assertStringContainsString('исполнение', $e->getMessage());
        }

        $fresh = $backup->fresh();
        $this->assertSame(BackupStatus::Running, $fresh->status);
        $this->assertSame($tokenB, $fresh->execution_token);
        $this->assertNull($fresh->size_bytes);
    }

    public function test_late_safe_record_failure_with_old_token_does_not_fail_new_run(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => true]);

        $backup = $this->makeQueued();
        $tokenA = $this->invokePrivate('begin', $backup->fresh());

        DatabaseBackup::query()
            ->whereKey($backup->getKey())
            ->update(['started_at' => now()->subHours(2)]);

        $tokenB = $this->invokePrivate('begin', $backup->fresh());

        $this->invokePrivate('safeRecordFailure', $backup->fresh(), $tokenA, new RuntimeException('late boom'));

        $fresh = $backup->fresh();
        $this->assertSame(BackupStatus::Running, $fresh->status);
        $this->assertSame($tokenB, $fresh->execution_token);
        $this->assertNull($fresh->error_message);

        // Проигрыш CAS не должен слать ложное уведомление о сбое чужого запуска.
        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_late_owner_does_not_trigger_rotation(): void
    {
        config(['backups.keep.manual' => 1]);

        $old = $this->makeSuccess(BackupTrigger::Manual, 100);

        $backup = $this->makeQueued();
        $tokenA = $this->invokePrivate('begin', $backup->fresh());

        DatabaseBackup::query()
            ->whereKey($backup->getKey())
            ->update(['started_at' => now()->subHours(2)]);

        $tokenB = $this->invokePrivate('begin', $backup->fresh());

        try {
            $this->invokePrivate(
                'markSucceeded',
                $backup->fresh(),
                $tokenA,
                'irsi-app/testing/manual/y.dump',
                'irsi-app/testing/manual/y.dump.manifest.json',
                1,
                str_repeat('b', 64),
                'PostgreSQL 16',
                'test',
            );
        } catch (BackupException) {
            // ожидаемо
        }

        // Старая успешная копия не удалена ротацией запоздавшего process A.
        $this->assertDatabaseHas('database_backups', ['id' => $old->getKey()]);
        $this->assertTrue(Storage::disk('backups_s3')->exists((string) $old->object_key));
        $this->assertSame($tokenB, $backup->fresh()->execution_token);
    }

    public function test_error_and_rethrow_do_not_leak_db_password_or_s3_secret(): void
    {
        $dbSecret = 'DbPass-MARKER-xyz789';
        $s3Secret = 'S3Secret-MARKER-abc012';

        config([
            'database.connections.pgsql.password' => $dbSecret,
            'filesystems.disks.backups_s3.secret' => $s3Secret,
        ]);

        $backup = $this->makeQueued();
        $this->runner->dumpExitCode = 1;
        $this->runner->dumpStderr = "FATAL: password authentication failed password={$dbSecret} secret={$s3Secret}";

        try {
            $this->service->run($backup);
            $this->fail('Ожидалось исключение BackupException');
        } catch (BackupException $e) {
            $this->assertStringNotContainsString($dbSecret, $e->getMessage());
            $this->assertStringNotContainsString($s3Secret, $e->getMessage());
            $this->assertNull($e->getPrevious(), 'оригинальное исключение не должно быть previous');
        }

        $backup->refresh();
        $this->assertStringNotContainsString($dbSecret, (string) $backup->error_message);
        $this->assertStringNotContainsString($s3Secret, (string) $backup->error_message);
    }

    public function test_non_backup_exception_is_wrapped_without_secrets_or_previous(): void
    {
        $s3Secret = 'S3Secret-MARKER-xyz';

        config(['filesystems.disks.backups_s3.secret' => $s3Secret]);

        $backup = $this->makeQueued();

        $stub = new class(Storage::disk('backups_s3'), $s3Secret) extends BackupDiskStub
        {
            public function __construct(Filesystem $inner, private readonly string $secret)
            {
                parent::__construct($inner);
            }

            public function writeStream($path, $resource, array $options = [])
            {
                throw new RuntimeException('AWS error exposing secret='.$this->secret);
            }
        };
        Storage::set('backups_s3', $stub);

        try {
            $this->service->run($backup);
            $this->fail('Ожидалось исключение BackupException');
        } catch (BackupException $e) {
            $this->assertStringNotContainsString($s3Secret, $e->getMessage());
            $this->assertNull($e->getPrevious());
        }

        $backup->refresh();
        $this->assertStringNotContainsString($s3Secret, (string) $backup->error_message);
    }

    public function test_rotation_respects_keep_limit_and_touches_only_same_trigger(): void
    {
        config(['backups.keep.manual' => 2, 'backups.keep.scheduled' => 7]);

        $oldManual1 = $this->makeSuccess(BackupTrigger::Manual, 100);
        $oldManual2 = $this->makeSuccess(BackupTrigger::Manual, 90);
        $oldManual3 = $this->makeSuccess(BackupTrigger::Manual, 80);
        $scheduled = $this->makeSuccess(BackupTrigger::Scheduled, 70);

        $backup = $this->makeQueued(BackupTrigger::Manual);
        $this->service->run($backup);

        $this->assertDatabaseMissing('database_backups', ['id' => $oldManual1->getKey()]);
        $this->assertDatabaseMissing('database_backups', ['id' => $oldManual2->getKey()]);
        $this->assertDatabaseHas('database_backups', ['id' => $oldManual3->getKey()]);
        $this->assertDatabaseHas('database_backups', ['id' => $scheduled->getKey()]);

        $this->assertFalse(Storage::disk('backups_s3')->exists((string) $oldManual1->object_key));
        $this->assertFalse(Storage::disk('backups_s3')->exists((string) $oldManual1->manifest_key));
        $this->assertTrue(Storage::disk('backups_s3')->exists((string) $scheduled->object_key));

        $fresh = $backup->fresh();
        $this->assertSame(BackupStatus::Success, $fresh->status);
        $this->assertNull($fresh->prune_error_message);
    }

    public function test_rotation_uses_scheduled_keep_independently(): void
    {
        config(['backups.keep.manual' => 7, 'backups.keep.scheduled' => 2]);

        $oldScheduled1 = $this->makeSuccess(BackupTrigger::Scheduled, 100);
        $this->makeSuccess(BackupTrigger::Scheduled, 90);
        $this->makeSuccess(BackupTrigger::Scheduled, 80);
        $manual = $this->makeSuccess(BackupTrigger::Manual, 70);

        $backup = $this->makeQueued(BackupTrigger::Scheduled);
        $this->service->run($backup);

        // keep=2: новая + одна предыдущая scheduled остаются; две старые удалены.
        $this->assertDatabaseMissing('database_backups', ['id' => $oldScheduled1->getKey()]);
        $this->assertDatabaseHas('database_backups', ['id' => $manual->getKey()]);
        $this->assertSame(2, DatabaseBackup::where('trigger_type', 'scheduled')->where('status', 'success')->count());
    }

    public function test_rotation_rejects_keys_outside_namespace(): void
    {
        config(['backups.keep.manual' => 1]);

        // Старая «успешная» с ключом вне namespace — ротация не удалит чужой объект.
        $malicious = DatabaseBackup::create([
            'trigger_type' => BackupTrigger::Manual->value,
            'status' => BackupStatus::Success,
            'requested_at' => now()->subHours(5),
            'finished_at' => now()->subHours(5),
            'object_key' => 'other-app/production/manual/steal.dump',
            'manifest_key' => null,
            'size_bytes' => 1,
            'sha256' => str_repeat('c', 64),
        ]);
        Storage::disk('backups_s3')->put('other-app/production/manual/steal.dump', 'keep-me');

        $backup = $this->makeQueued(BackupTrigger::Manual);
        $this->service->run($backup);

        $this->assertTrue(Storage::disk('backups_s3')->exists('other-app/production/manual/steal.dump'));
        $this->assertNotNull($backup->fresh()->prune_error_message);
        $this->assertDatabaseHas('database_backups', ['id' => $malicious->getKey()]);
    }

    public function test_failed_new_backup_does_not_prune_old_ones(): void
    {
        config(['backups.keep.manual' => 1]);

        $old = $this->makeSuccess(BackupTrigger::Manual, 100);

        $backup = $this->makeQueued(BackupTrigger::Manual);
        $this->runner->dumpExitCode = 1;

        try {
            $this->service->run($backup);
        } catch (BackupException) {
            // ожидаемо
        }

        $this->assertDatabaseHas('database_backups', ['id' => $old->getKey()]);
        $this->assertTrue(Storage::disk('backups_s3')->exists((string) $old->object_key));
    }

    public function test_prune_error_is_recorded_separately_and_backup_stays_success(): void
    {
        config(['backups.keep.manual' => 1]);

        $this->makeSuccess(BackupTrigger::Manual, 100);

        $backup = $this->makeQueued(BackupTrigger::Manual);

        $stub = new BackupDiskStub(Storage::disk('backups_s3'));
        $stub->failDeletes = true;
        Storage::set('backups_s3', $stub);

        $this->service->run($backup);

        $backup->refresh();
        $this->assertSame(BackupStatus::Success, $backup->status);
        $this->assertNotNull($backup->object_key);
        $this->assertStringContainsString('Очистка старых копий', (string) $backup->prune_error_message);
    }

    public function test_error_messages_do_not_contain_db_password(): void
    {
        config(['database.connections.pgsql.password' => 'TopSecret-99!xyz']);

        $backup = $this->makeQueued();
        $this->runner->dumpExitCode = 1;
        $this->runner->dumpStderr = 'FATAL: password authentication failed for user "app"';

        try {
            $this->service->run($backup);
        } catch (BackupException) {
            // ожидаемо
        }

        $backup->refresh();
        $this->assertStringNotContainsString('TopSecret-99!xyz', (string) $backup->error_message);
        $this->assertStringNotContainsString('TopSecret-99!xyz', (string) $backup->prune_error_message);
    }
}
