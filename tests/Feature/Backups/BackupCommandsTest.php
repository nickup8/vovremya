<?php

namespace Tests\Feature\Backups;

use App\Enums\BackupStatus;
use App\Enums\BackupTrigger;
use App\Jobs\RunDatabaseBackupJob;
use App\Models\DatabaseBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackupCommandsTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = storage_path('framework/testing/backups-cmd-'.uniqid('', true));

        config([
            'backups.tmp_dir' => $this->tmpDir,
            'backups.tmp_max_age_hours' => 24,
            'queue.connections.backups.driver' => 'database',
            'queue.connections.backups.connection' => null,
            'queue.connections.backups.after_commit' => false,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir.'/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    public function test_backups_run_queues_manual_backup(): void
    {
        Queue::fake();

        $this->artisan('backups:run')->assertExitCode(0);

        Queue::assertPushed(RunDatabaseBackupJob::class, 1);

        $backup = DatabaseBackup::sole();
        $this->assertSame(BackupTrigger::Manual, $backup->trigger_type);
        $this->assertSame(BackupStatus::Queued, $backup->status);
        $this->assertNull($backup->requested_by);
    }

    public function test_backups_run_fails_when_backup_already_active(): void
    {
        Queue::fake();

        DatabaseBackup::create([
            'trigger_type' => BackupTrigger::Scheduled->value,
            'status' => BackupStatus::Running,
            'requested_at' => now()->subMinutes(2),
            'started_at' => now()->subMinutes(2),
        ]);

        $this->artisan('backups:run')->assertExitCode(1);

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('database_backups', 1);
    }

    public function test_backups_recover_runs_even_when_schedule_disabled(): void
    {
        config(['backups.scheduled.enabled' => false]);

        Queue::fake();

        DatabaseBackup::create([
            'trigger_type' => BackupTrigger::Manual->value,
            'status' => BackupStatus::Running,
            'requested_at' => now()->subHours(3),
            'started_at' => now()->subHours(3),
        ]);

        $this->artisan('backups:recover')->assertExitCode(0);

        $this->assertSame(BackupStatus::Failed, DatabaseBackup::sole()->status);
    }

    public function test_cleanup_tmp_removes_stale_files_and_keeps_fresh_ones(): void
    {
        mkdir($this->tmpDir, 0700, true);

        $staleDir = $this->tmpDir.'/stale-backup-id';
        mkdir($staleDir, 0700);
        file_put_contents($staleDir.'/database.dump', 'old');
        touch($staleDir.'/database.dump', now()->subDays(2)->getTimestamp());
        touch($staleDir, now()->subDays(2)->getTimestamp());

        $freshDir = $this->tmpDir.'/fresh-backup-id';
        mkdir($freshDir, 0700);
        file_put_contents($freshDir.'/database.dump', 'new');

        $this->artisan('backups:cleanup-tmp')->assertExitCode(0);

        $this->assertDirectoryDoesNotExist($staleDir);
        $this->assertDirectoryExists($freshDir);
        $this->assertFileExists($freshDir.'/database.dump');
    }

    public function test_cleanup_tmp_is_noop_without_directory(): void
    {
        config(['backups.tmp_dir' => $this->tmpDir.'/missing']);

        $this->artisan('backups:cleanup-tmp')->assertExitCode(0);
    }
}
