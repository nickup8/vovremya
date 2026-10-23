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

        $day = $backup->requested_at;
        $expectedKey = sprintf(
            'irsi-app/testing/manual/%s/%s/%s/%s.dump',
            $day->format('Y'),
            $day->format('m'),
            $day->format('d'),
            $backup->getKey(),
        );

        Storage::disk('backups_s3')->put($expectedKey, 'pre-existing');

        try {
            $this->service->run($backup);
            $this->fail('Ожидалось исключение BackupException');
        } catch (BackupException $e) {
            $this->assertStringContainsString('перезапись отменена', $e->getMessage());
        }

        $backup->refresh();
        $this->assertSame(BackupStatus::Failed, $backup->status);

        // Намерение было записано до HTTP: cleanup удаляет ключ нашего запуска
        // (уникален по id, ownership разрешает). Перезаписи не было, копия
        // не помечена success.
        $this->assertFalse(Storage::disk('backups_s3')->exists($expectedKey));
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
