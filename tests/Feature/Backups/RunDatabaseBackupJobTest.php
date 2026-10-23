<?php

namespace Tests\Feature\Backups;

use App\Enums\BackupStatus;
use App\Enums\BackupTrigger;
use App\Jobs\RunDatabaseBackupJob;
use App\Models\DatabaseBackup;
use App\Services\Backups\BackupProcessRunner;
use App\Services\Backups\DatabaseBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Backups\FakeBackupProcessRunner;
use Tests\TestCase;

class RunDatabaseBackupJobTest extends TestCase
{
    use RefreshDatabase;

    private FakeBackupProcessRunner $runner;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/irsi-backups-job-'.uniqid();

        config([
            'backups.disk' => 'backups_s3',
            'backups.prefix' => 'irsi-app',
            'backups.tmp_dir' => $this->tmpDir,
            'backups.stale_after' => 1800,
        ]);

        Storage::fake('backups_s3');

        $this->runner = new FakeBackupProcessRunner;
        $this->app->instance(BackupProcessRunner::class, $this->runner);
    }

    protected function tearDown(): void
    {
        DatabaseBackupService::deleteTree($this->tmpDir);

        parent::tearDown();
    }

    private function makeBackup(BackupStatus $status, array $overrides = []): DatabaseBackup
    {
        return DatabaseBackup::create(array_merge([
            'trigger_type' => BackupTrigger::Manual->value,
            'status' => $status->value,
            'requested_at' => now(),
            'started_at' => $status === BackupStatus::Running ? now() : null,
        ], $overrides));
    }

    public function test_redelivery_of_successful_backup_is_noop(): void
    {
        $backup = $this->makeBackup(BackupStatus::Success);

        (new RunDatabaseBackupJob($backup->getKey()))->handle($this->app->make(DatabaseBackupService::class));

        $this->assertSame([], $this->runner->calls);
        $this->assertSame(BackupStatus::Success, $backup->fresh()->status);
    }

    public function test_redelivery_of_failed_backup_is_noop(): void
    {
        $backup = $this->makeBackup(BackupStatus::Failed, [
            'error_message' => 'old error',
            'finished_at' => now(),
        ]);

        (new RunDatabaseBackupJob($backup->getKey()))->handle($this->app->make(DatabaseBackupService::class));

        $this->assertSame([], $this->runner->calls);
        $this->assertSame('old error', $backup->fresh()->error_message);
    }

    public function test_running_fresh_backup_is_left_to_the_other_process(): void
    {
        $backup = $this->makeBackup(BackupStatus::Running, [
            'started_at' => now()->subMinutes(5),
        ]);

        (new RunDatabaseBackupJob($backup->getKey()))->handle($this->app->make(DatabaseBackupService::class));

        $this->assertSame([], $this->runner->calls);
        $this->assertSame(BackupStatus::Running, $backup->fresh()->status);
    }

    public function test_stale_running_backup_is_reclaimed_and_completed(): void
    {
        $backup = $this->makeBackup(BackupStatus::Running, [
            'started_at' => now()->subHours(2),
        ]);

        (new RunDatabaseBackupJob($backup->getKey()))->handle($this->app->make(DatabaseBackupService::class));

        $this->assertNotSame([], $this->runner->calls);
        $this->assertSame(BackupStatus::Success, $backup->fresh()->status);
    }

    public function test_queued_backup_is_executed(): void
    {
        $backup = $this->makeBackup(BackupStatus::Queued);

        (new RunDatabaseBackupJob($backup->getKey()))->handle($this->app->make(DatabaseBackupService::class));

        $fresh = $backup->fresh();
        $this->assertSame(BackupStatus::Success, $fresh->status);
        $this->assertNotNull($fresh->object_key);
    }

    public function test_missing_record_is_noop(): void
    {
        (new RunDatabaseBackupJob('00000000-0000-0000-0000-000000000000'))->handle(
            $this->app->make(DatabaseBackupService::class),
        );

        $this->assertSame([], $this->runner->calls);
    }

    public function test_failed_hook_marks_active_backup_interrupted(): void
    {
        $backup = $this->makeBackup(BackupStatus::Running, [
            'started_at' => now()->subMinutes(10),
        ]);

        (new RunDatabaseBackupJob($backup->getKey()))->failed(new \RuntimeException('worker died'));

        $fresh = $backup->fresh();
        $this->assertSame(BackupStatus::Failed, $fresh->status);
        $this->assertStringContainsString('прерван', (string) $fresh->error_message);
        $this->assertStringNotContainsString('secret', (string) $fresh->error_message);
    }

    public function test_failed_hook_does_not_resurrect_terminal_backup(): void
    {
        $backup = $this->makeBackup(BackupStatus::Success);

        (new RunDatabaseBackupJob($backup->getKey()))->failed(new \RuntimeException('late failure'));

        $this->assertSame(BackupStatus::Success, $backup->fresh()->status);
        $this->assertNull($backup->fresh()->error_message);
    }
}
