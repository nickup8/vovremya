<?php

namespace Tests\Feature\Backups;

use App\Enums\BackupStatus;
use App\Enums\BackupTrigger;
use App\Exceptions\Backups\BackupAlreadyRunning;
use App\Exceptions\Backups\BackupException;
use App\Jobs\RunDatabaseBackupJob;
use App\Models\DatabaseBackup;
use App\Models\User;
use App\Services\Backups\BackupProcessRunner;
use App\Services\Backups\DatabaseBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

        $this->tmpDir = sys_get_temp_dir().'/irsi-backups-svc-'.uniqid();

        config([
            'backups.disk' => 'backups_s3',
            'backups.prefix' => 'irsi-app',
            'backups.tmp_dir' => $this->tmpDir,
            'backups.keep.manual' => 7,
            'backups.keep.scheduled' => 7,
            'backups.process_timeout' => 600,
            'backups.job_timeout' => 900,
            'backups.stale_after' => 1800,
            'backups.scheduled.enabled' => false,
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
        $id = (string) Str::uuid();
        $objectKey = sprintf('irsi-app/testing/%s/2026/01/01/%s%s.dump', $trigger->value, $id, $keySuffix);

        $backup = DatabaseBackup::create([
            'id' => $id,
            'trigger_type' => $trigger->value,
            'status' => BackupStatus::Success,
            'requested_at' => now()->subMinutes($minutesAgo + 1),
            'finished_at' => now()->subMinutes($minutesAgo),
            'object_key' => $objectKey,
            'manifest_key' => $objectKey.'.manifest.json',
            'size_bytes' => 123,
            'sha256' => str_repeat('a', 64),
        ]);

        Storage::disk('backups_s3')->put($objectKey, 'old-dump');
        Storage::disk('backups_s3')->put($objectKey.'.manifest.json', '{}');

        return $backup;
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

    public function test_pg_dump_timeout_marks_failed(): void
    {
        $backup = $this->makeQueued();

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
            $this->fail('Ожидалось исключение');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('S3 write failed', $e->getMessage());
        }

        $backup->refresh();
        $this->assertSame(BackupStatus::Failed, $backup->status);
    }

    public function test_s3_size_or_sha_mismatch_marks_failed(): void
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
        $this->assertNull($backup->object_key);
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

        $this->assertSame('pre-existing', Storage::disk('backups_s3')->get($expectedKey));

        $backup->refresh();
        $this->assertSame(BackupStatus::Failed, $backup->status);
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

        $new = $this->service->request(BackupTrigger::Manual);

        $stale->refresh();
        $this->assertSame(BackupStatus::Failed, $stale->status);
        $this->assertStringContainsString('прерван', (string) $stale->error_message);

        $this->assertSame(BackupStatus::Queued, $new->status);
        $this->assertNotSame($stale->getKey(), $new->getKey());
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
