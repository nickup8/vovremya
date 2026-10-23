<?php

namespace Tests\Feature\Backups;

use App\Enums\BackupStatus;
use App\Enums\BackupTrigger;
use App\Jobs\RunDatabaseBackupJob;
use App\Models\DatabaseBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackupScheduleDailyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'backups.tmp_dir' => sys_get_temp_dir().'/irsi-backups-sched-'.uniqid(),
            'backups.scheduled.timezone' => 'Europe/Moscow',
        ]);
    }

    public function test_scheduled_run_is_disabled_by_default(): void
    {
        config(['backups.scheduled.enabled' => false]);

        Queue::fake();

        $this->artisan('backups:schedule-daily')
            ->expectsOutputToContain('выключены')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('database_backups', 0);
    }

    public function test_scheduled_run_queues_backup_when_enabled(): void
    {
        config(['backups.scheduled.enabled' => true]);

        Queue::fake();

        $this->artisan('backups:schedule-daily')->assertExitCode(0);

        Queue::assertPushed(RunDatabaseBackupJob::class, 1);

        $backup = DatabaseBackup::sole();
        $this->assertSame(BackupTrigger::Scheduled, $backup->trigger_type);
        $this->assertSame(BackupStatus::Queued, $backup->status);
        $this->assertSame(now('Europe/Moscow')->format('Y-m-d'), $backup->dedup_key);
    }

    public function test_scheduled_run_is_deduplicated_per_calendar_day(): void
    {
        config(['backups.scheduled.enabled' => true]);

        Queue::fake();

        $this->artisan('backups:schedule-daily')->assertExitCode(0);
        // Первая копия ещё в очереди — повторный запуск блокируется активностью.
        $this->artisan('backups:schedule-daily')
            ->expectsOutputToContain('выполняется')
            ->assertExitCode(0);

        Queue::assertPushed(RunDatabaseBackupJob::class, 1);
        $this->assertDatabaseCount('database_backups', 1);
    }

    public function test_scheduled_run_is_skipped_when_today_already_succeeded(): void
    {
        config(['backups.scheduled.enabled' => true]);

        DatabaseBackup::create([
            'trigger_type' => BackupTrigger::Scheduled->value,
            'status' => BackupStatus::Success,
            'requested_at' => now(),
            'finished_at' => now(),
            'dedup_key' => now('Europe/Moscow')->format('Y-m-d'),
        ]);

        Queue::fake();

        $this->artisan('backups:schedule-daily')
            ->expectsOutputToContain('уже создана')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('database_backups', 1);
    }

    public function test_failed_scheduled_backup_does_not_block_retry_same_day(): void
    {
        config(['backups.scheduled.enabled' => true]);

        DatabaseBackup::create([
            'trigger_type' => BackupTrigger::Scheduled->value,
            'status' => BackupStatus::Failed,
            'requested_at' => now(),
            'finished_at' => now(),
            'dedup_key' => now('Europe/Moscow')->format('Y-m-d'),
            'error_message' => 'previous failure',
        ]);

        Queue::fake();

        $this->artisan('backups:schedule-daily')->assertExitCode(0);

        Queue::assertPushed(RunDatabaseBackupJob::class, 1);
        $this->assertDatabaseCount('database_backups', 2);
    }

    public function test_manual_backup_does_not_block_scheduled_dedup_key(): void
    {
        config(['backups.scheduled.enabled' => true]);

        DatabaseBackup::create([
            'trigger_type' => BackupTrigger::Manual->value,
            'status' => BackupStatus::Queued,
            'requested_at' => now(),
            'dedup_key' => null,
        ]);

        Queue::fake();

        // Ручная копия в очереди — общий запрет параллельности срабатывает.
        $this->artisan('backups:schedule-daily')
            ->expectsOutputToContain('выполняется')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('database_backups', 1);
    }
}
