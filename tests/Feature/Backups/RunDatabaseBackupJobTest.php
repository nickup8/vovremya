<?php

namespace Tests\Feature\Backups;

use App\Enums\BackupStatus;
use App\Enums\BackupTrigger;
use App\Exceptions\Backups\BackupCaptureLost;
use App\Jobs\RunDatabaseBackupJob;
use App\Models\DatabaseBackup;
use App\Models\User;
use App\Services\Backups\BackupProcessRunner;
use App\Services\Backups\DatabaseBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ReflectionMethod;
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

        $this->tmpDir = storage_path('framework/testing/backups-job-'.uniqid('', true));

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

    public function test_capture_lost_race_is_noop_and_does_not_fail_backup(): void
    {
        $backup = $this->makeBackup(BackupStatus::Queued);

        // Между проверкой статуса в handle() и begin() другой воркер
        // захватил копию — run() бросает BackupCaptureLost.
        $service = new class(app(BackupProcessRunner::class)) extends DatabaseBackupService
        {
            public function run(DatabaseBackup $backup, ?string $executionToken = null): void
            {
                throw new BackupCaptureLost;
            }
        };

        (new RunDatabaseBackupJob($backup->getKey()))->handle($service);

        $fresh = $backup->fresh();
        $this->assertSame(BackupStatus::Queued, $fresh->status);
        $this->assertNull($fresh->error_message);
        $this->assertNull($fresh->finished_at);
    }

    public function test_failed_hook_marks_active_backup_interrupted_and_sanitizes(): void
    {
        config(['filesystems.disks.backups_s3.secret' => 'S3Secret-MARKER-hook']);

        $token = (string) Str::uuid();
        $backup = $this->makeBackup(BackupStatus::Running, [
            'started_at' => now()->subMinutes(10),
            'execution_token' => $token,
        ]);

        (new RunDatabaseBackupJob($backup->getKey(), $token))->failed(
            new \RuntimeException('worker died secret=S3Secret-MARKER-hook'),
        );

        $fresh = $backup->fresh();
        $this->assertSame(BackupStatus::Failed, $fresh->status);
        $this->assertStringContainsString('прерван', (string) $fresh->error_message);
        $this->assertStringNotContainsString('S3Secret-MARKER-hook', (string) $fresh->error_message);
    }

    public function test_failed_hook_does_not_interrupt_foreign_execution(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => true]);

        $foreignToken = (string) Str::uuid();
        $backup = $this->makeBackup(BackupStatus::Running, [
            'started_at' => now()->subMinutes(10),
            'execution_token' => $foreignToken,
        ]);

        // Запоздавший failed()-хук другой доставки: чужой живой запуск
        // не прерывается и не получает ложное уведомление.
        (new RunDatabaseBackupJob($backup->getKey(), (string) Str::uuid()))->failed(
            new \RuntimeException('late foreign failure'),
        );

        $fresh = $backup->fresh();
        $this->assertSame(BackupStatus::Running, $fresh->status);
        $this->assertSame($foreignToken, $fresh->execution_token);
        $this->assertNull($fresh->error_message);
        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_failed_hook_does_not_resurrect_terminal_backup(): void
    {
        $backup = $this->makeBackup(BackupStatus::Success);

        (new RunDatabaseBackupJob($backup->getKey()))->failed(new \RuntimeException('late failure'));

        $this->assertSame(BackupStatus::Success, $backup->fresh()->status);
        $this->assertNull($backup->fresh()->error_message);
    }

    /**
     * Сериализованный command из payload'а ПОСЛЕ обычной постановки и ДО
     * handle() — именно такой экземпляр Laravel воссоздаёт в failed().
     */
    private function originalJobCommand(): RunDatabaseBackupJob
    {
        return $this->commandFromPayload($this->originalJobPayload());
    }

    private function originalJobPayload(): string
    {
        $row = DB::table('jobs')->where('queue', 'backups')->orderByDesc('id')->first();

        $this->assertNotNull($row, 'job должен быть поставлен в очередь обычным способом');

        $payload = json_decode((string) $row->payload, true);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('command', $payload['data'] ?? []);

        return (string) $payload['data']['command'];
    }

    private function commandFromPayload(string $serialized): RunDatabaseBackupJob
    {
        $command = unserialize($serialized);

        $this->assertInstanceOf(RunDatabaseBackupJob::class, $command);

        return $command;
    }

    private function invokePrivate(string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod(DatabaseBackupService::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke($this->app->make(DatabaseBackupService::class), ...$args);
    }

    public function test_handle_uses_execution_token_from_original_payload(): void
    {
        $service = $this->app->make(DatabaseBackupService::class);
        $backup = $service->request(BackupTrigger::Manual);

        $original = $this->originalJobCommand();
        $token = $original->executionToken;

        $this->assertNotNull($token, 'токен доставки должен лежать в исходном payload ДО handle()');

        // handle() должен работать именно токеном из payload'а — иначе
        // failed() (десериализация исходного payload'а) свое исполнение
        // не найдёт, а fencing между доставками сломается.
        $probe = new class(app(BackupProcessRunner::class)) extends DatabaseBackupService
        {
            public ?string $observedToken = 'not-called';

            public function run(DatabaseBackup $backup, ?string $executionToken = null): void
            {
                $this->observedToken = $executionToken;
            }
        };

        $original->handle($probe);

        $this->assertSame($token, $probe->observedToken);
        $this->assertSame(BackupStatus::Queued, $backup->fresh()->status, 'probe не должен менять статус');
    }

    public function test_failed_hook_restored_from_original_payload_interrupts_its_own_running_execution(): void
    {
        config(['filesystems.disks.backups_s3.secret' => 'S3Secret-MARKER-hook']);

        $admin = User::factory()->master()->create(['is_super_admin' => true]);
        $service = $this->app->make(DatabaseBackupService::class);

        // Обычная постановка: payload сериализуется ДО handle().
        $backup = $service->request(BackupTrigger::Manual);
        $payload = $this->originalJobPayload();

        $original = $this->commandFromPayload($payload);
        $token = $original->executionToken;
        $this->assertNotNull($token, 'токен доставки должен лежать в исходном payload ДО handle()');

        // Воркер начал исполнение этим токеном (begin в handle()) и был
        // убит до завершения: строка осталась running, остаток загружен.
        $this->invokePrivate('begin', $backup->fresh(), $token);

        $leftoverKey = sprintf(
            'irsi-app/testing/manual/2026/01/01/%s/%s.dump',
            $token,
            $backup->getKey(),
        );
        Storage::disk('backups_s3')->put($leftoverKey, 'partial-upload');
        DatabaseBackup::query()->whereKey($backup->getKey())->update(['object_key' => $leftoverKey]);

        // Laravel вызывает failed() на новом экземпляре из ИСХОДНОГО
        // payload'а — как здесь, с сохранённой до handle() сериализации.
        $restored = $this->commandFromPayload($payload);
        $restored->failed(new \RuntimeException('worker died secret=S3Secret-MARKER-hook'));

        $fresh = $backup->fresh();
        $this->assertSame(BackupStatus::Failed, $fresh->status, 'свое running-исполнение должно завершиться');
        $this->assertStringContainsString('прерван', (string) $fresh->error_message);
        $this->assertStringNotContainsString('S3Secret-MARKER-hook', (string) $fresh->error_message);
        $this->assertNull($fresh->execution_token);
        $this->assertFalse(
            Storage::disk('backups_s3')->exists($leftoverKey),
            'остатки своего исполнения должны убираться',
        );
        $this->assertGreaterThan(0, $admin->notifications()->count());
    }

    public function test_failed_hook_restored_from_original_payload_does_not_interrupt_foreign_execution(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => true]);
        $service = $this->app->make(DatabaseBackupService::class);

        $backup = $service->request(BackupTrigger::Manual);
        $payload = $this->originalJobPayload();

        $original = $this->commandFromPayload($payload);
        $token = $original->executionToken;
        $this->assertNotNull($token, 'токен доставки должен лежать в исходном payload ДО handle()');

        // Своя доставка начала исполнение, затем строку перехватило чужое
        // исполнение (stale-захват с новым токеном); запоздавший failed()
        // этой доставки не должен трогать чужой живой запуск.
        $this->invokePrivate('begin', $backup->fresh(), $token);
        DatabaseBackup::query()->whereKey($backup->getKey())->update(['started_at' => now()->subHours(2)]);
        $foreignToken = (string) $this->invokePrivate('begin', $backup->fresh());

        $foreignKey = sprintf(
            'irsi-app/testing/manual/2026/01/01/%s/%s.dump',
            $foreignToken,
            $backup->getKey(),
        );
        Storage::disk('backups_s3')->put($foreignKey, 'foreign-partial');
        DatabaseBackup::query()->whereKey($backup->getKey())->update(['object_key' => $foreignKey]);

        $restored = $this->commandFromPayload($payload);
        $restored->failed(new \RuntimeException('late foreign failure'));

        $fresh = $backup->fresh();
        $this->assertSame(BackupStatus::Running, $fresh->status, 'чужой запуск должен остаться нетронутым');
        $this->assertSame($foreignToken, $fresh->execution_token);
        $this->assertNull($fresh->error_message);
        $this->assertTrue(Storage::disk('backups_s3')->exists($foreignKey));
        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_each_dispatched_delivery_carries_a_distinct_execution_token(): void
    {
        $backup = $this->makeBackup(BackupStatus::Queued);
        $service = $this->app->make(DatabaseBackupService::class);

        // Реальная постановка (protected pushJob) дважды: два delivery —
        // два исполнения — должны получать РАЗНЫЕ токены, иначе поздний
        // failed() одной доставки совпал бы с токеном другой (нет fencing).
        $push = new ReflectionMethod(DatabaseBackupService::class, 'pushJob');
        $push->setAccessible(true);
        $push->invoke($service, $backup);
        $push->invoke($service, $backup);

        $rows = DB::table('jobs')->where('queue', 'backups')->orderBy('id')->get();

        $this->assertCount(2, $rows);

        $tokens = $rows->map(function ($row): ?string {
            $command = $this->commandFromPayload((string) json_decode((string) $row->payload, true)['data']['command']);

            return $command->executionToken;
        })->all();

        $this->assertNotNull($tokens[0], 'каждая доставка несёт токен в payload');
        $this->assertNotNull($tokens[1], 'каждая доставка несёт токен в payload');
        $this->assertNotSame($tokens[0], $tokens[1], 'токены разных доставок не должны совпадать');
    }
}
