<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\InterceptingFilesystem;
use Tests\TestCase;

/**
 * N03 — резервная копия avatars/ из media_s3 в приватное хранилище.
 * Тесты не трогают БД: только Storage::fake, config и artisan.
 */
class BackupAvatarsDailyCommandTest extends TestCase
{
    /** @var array<string, string> */
    private const SOURCE_FILES = [
        'avatars/aa/bb/one.jpg' => 'first-avatar-bytes',
        'avatars/three.jpg' => 'third-avatar-bytes',
        'avatars/two.png' => 'second-avatar-bytes',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media_s3');
        Storage::fake('backups_s3');

        // Окружение теста — testing (phpunit.xml APP_ENV=testing).
        config([
            'backups.disk' => 'backups_s3',
            'backups.prefix' => 'irsi-app',
            'backups.avatars.source_disk' => 'media_s3',
            'backups.avatars.prefix' => 'irsi-media-backup',
            'filesystems.disks.media_s3.bucket' => 'irsi-media',
            'filesystems.disks.backups_s3.bucket' => 'irsi-db-backups',
        ]);
    }

    public function test_copies_only_avatars_prefix_preserving_keys_and_bytes(): void
    {
        $this->enableBackup();

        $source = Storage::disk('media_s3');

        foreach (self::SOURCE_FILES as $key => $bytes) {
            $source->put($key, $bytes);
        }

        // Объекты вне avatars/ не должны копироваться.
        $source->put('foreign/object.txt', 'foreign-bytes');
        $source->put('avatars-bak/nested.jpg', 'not-an-avatar');

        // Рабочий диск строго на чтение: любая запись — исключение.
        $guard = new InterceptingFilesystem($source);
        $guard->beforePut = function (): void {
            throw new RuntimeException('запись в рабочий диск запрещена');
        };
        Storage::set('media_s3', $guard);

        $this->artisan('backups:avatars-daily')
            ->expectsOutputToContain('Объектов в источнике: 3')
            ->expectsOutputToContain('Скопировано: 3')
            ->expectsOutputToContain('Уже в резерве: 0')
            ->expectsOutputToContain('Ошибок нет')
            ->assertExitCode(0);

        $target = Storage::disk('backups_s3');

        foreach (self::SOURCE_FILES as $key => $bytes) {
            $targetKey = $this->namespace().$key;

            $this->assertTrue($target->exists($targetKey), "нет копии [{$targetKey}]");
            $this->assertSame($bytes, $target->get($targetKey), "байты копии [{$targetKey}] не совпадают");
        }

        $this->assertCount(3, $target->allFiles(), 'в резерве только объекты avatars/');
        $this->assertFalse($target->exists($this->namespace().'foreign/object.txt'));
        $this->assertFalse($target->exists($this->namespace().'avatars-bak/nested.jpg'));

        // Источник не изменён (включая объекты вне avatars/).
        $this->assertCount(5, $guard->allFiles());

        foreach (array_merge(self::SOURCE_FILES, [
            'foreign/object.txt' => 'foreign-bytes',
            'avatars-bak/nested.jpg' => 'not-an-avatar',
        ]) as $key => $bytes) {
            $this->assertTrue($guard->exists($key), "источник потерял [{$key}]");
            $this->assertSame($bytes, $guard->get($key), "байты источника [{$key}] изменились");
        }
    }

    public function test_repeat_run_fills_missing_after_partial_failure(): void
    {
        $this->enableBackup();

        foreach (self::SOURCE_FILES as $key => $bytes) {
            Storage::disk('media_s3')->put($key, $bytes);
        }

        $realTarget = Storage::disk('backups_s3');
        $broken = new InterceptingFilesystem($realTarget);
        $broken->beforePut = function (string $path): void {
            if (str_ends_with($path, 'two.png')) {
                throw new RuntimeException('сбой записи в резерв');
            }
        };
        Storage::set('backups_s3', $broken);

        $this->artisan('backups:avatars-daily')
            ->expectsOutputToContain('Скопировано: 2')
            ->expectsOutputToContain('Ошибок копирования: 1')
            ->assertExitCode(1);

        $this->assertTrue($realTarget->exists($this->namespace().'avatars/aa/bb/one.jpg'));
        $this->assertTrue($realTarget->exists($this->namespace().'avatars/three.jpg'));
        $this->assertFalse($realTarget->exists($this->namespace().'avatars/two.png'));

        // Повторный запуск дозаполняет только недостающее.
        $written = [];
        $recorder = new InterceptingFilesystem($realTarget);
        $recorder->beforePut = function (string $path) use (&$written): void {
            $written[] = $path;
        };
        Storage::set('backups_s3', $recorder);

        $this->artisan('backups:avatars-daily')
            ->expectsOutputToContain('Объектов в источнике: 3')
            ->expectsOutputToContain('Скопировано: 1')
            ->expectsOutputToContain('Уже в резерве: 2')
            ->expectsOutputToContain('Ошибок нет')
            ->assertExitCode(0);

        $this->assertSame([$this->namespace().'avatars/two.png'], $written, 'перезаписан только недостающий объект');

        foreach (self::SOURCE_FILES as $key => $bytes) {
            $this->assertSame($bytes, $realTarget->get($this->namespace().$key));
        }
    }

    public function test_backup_survives_source_deletion(): void
    {
        $this->enableBackup();

        $source = Storage::disk('media_s3');

        foreach (self::SOURCE_FILES as $key => $bytes) {
            $source->put($key, $bytes);
        }

        $this->artisan('backups:avatars-daily')->assertExitCode(0);

        foreach (array_keys(self::SOURCE_FILES) as $key) {
            $this->assertTrue($source->delete($key));
        }

        // Повторный запуск после удаления исходников не трогает резерв.
        $this->artisan('backups:avatars-daily')
            ->expectsOutputToContain('Объектов в источнике: 0')
            ->expectsOutputToContain('Скопировано: 0')
            ->expectsOutputToContain('Ошибок нет')
            ->assertExitCode(0);

        $target = Storage::disk('backups_s3');

        foreach (self::SOURCE_FILES as $key => $bytes) {
            $this->assertTrue($target->exists($this->namespace().$key), "резерв потерял [{$key}]");
            $this->assertSame($bytes, $target->get($this->namespace().$key));
        }
    }

    public function test_refuses_when_source_and_target_buckets_match(): void
    {
        $this->enableBackup();
        config(['filesystems.disks.media_s3.bucket' => 'irsi-db-backups']);

        Storage::disk('media_s3')->put('avatars/one.jpg', 'bytes');

        $this->artisan('backups:avatars-daily')
            ->expectsOutputToContain('Рабочий и резервный бакеты совпадают')
            ->assertExitCode(1);

        $this->assertSame([], Storage::disk('backups_s3')->allFiles(), 'назначение не тронуто');
        $this->assertTrue(Storage::disk('media_s3')->exists('avatars/one.jpg'), 'источник не тронут');
    }

    public function test_refuses_when_avatar_prefix_overlaps_dump_prefix(): void
    {
        $this->enableBackup();
        Storage::disk('media_s3')->put('avatars/one.jpg', 'bytes');

        // Тот же префикс, что и у дампов.
        config(['backups.prefix' => 'irsi-media-backup']);

        $this->artisan('backups:avatars-daily')
            ->expectsOutputToContain('пересекается с префиксом дампов')
            ->assertExitCode(1);

        // Дампы лежат внутри префикса резервной копии аватаров.
        config(['backups.prefix' => 'irsi-media-backup/production']);

        $this->artisan('backups:avatars-daily')
            ->expectsOutputToContain('пересекается с префиксом дампов')
            ->assertExitCode(1);

        // Префикс резервной копии лежит внутри дампового.
        config([
            'backups.prefix' => 'irsi-app',
            'backups.avatars.prefix' => 'irsi-app/irsi-media-backup',
        ]);

        $this->artisan('backups:avatars-daily')
            ->expectsOutputToContain('пересекается с префиксом дампов')
            ->assertExitCode(1);

        $this->assertSame([], Storage::disk('backups_s3')->allFiles(), 'назначение не тронуто');
        $this->assertTrue(Storage::disk('media_s3')->exists('avatars/one.jpg'), 'источник не тронут');
    }

    public function test_disabled_by_default_skips_without_touching_disks(): void
    {
        // Отдельный флаг, по умолчанию выключен.
        $this->assertFalse(config('backups.avatars.enabled'));

        Storage::disk('media_s3')->put('avatars/one.jpg', 'bytes');

        $this->artisan('backups:avatars-daily')
            ->expectsOutputToContain('выключено (AVATAR_BACKUP_ENABLED=false)')
            ->assertExitCode(0);

        $this->assertSame([], Storage::disk('backups_s3')->allFiles(), 'копирование не выполнялось');
        $this->assertTrue(Storage::disk('media_s3')->exists('avatars/one.jpg'));
    }

    public function test_copy_errors_exit_nonzero_without_secrets_or_avatar_urls(): void
    {
        $this->enableBackup();

        config([
            'filesystems.disks.backups_s3.secret' => 'SuperSecretValue123',
            'filesystems.disks.media_s3.url' => 'https://cdn.example.com/irsi-media',
        ]);

        Storage::disk('media_s3')->put('avatars/aa/one.jpg', 'bytes');

        $broken = new InterceptingFilesystem(Storage::disk('backups_s3'));
        $broken->beforePut = function (): void {
            throw new RuntimeException(
                'PutObject https://s3.twcstorage.ru/irsi-db-backups/irsi-media-backup/testing/avatars/aa/one.jpg'
                .'?X-Amz-Signature=LeakedSignature999 failed; secret=SuperSecretValue123; '
                .'avatar https://cdn.example.com/irsi-media/avatars/aa/one.jpg'
            );
        };
        Storage::set('backups_s3', $broken);

        // Полный вывод одним буфером: в одной строке ошибки сразу несколько
        // проверок, а artisan()-expectations отдают строку только одному ожиданию.
        $output = new BufferedOutput;
        $exitCode = $this->app->make(Kernel::class)->call('backups:avatars-daily', [], $output);
        $text = (string) $output->fetch();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Ошибок копирования: 1', $text);
        $this->assertStringContainsString('avatars/aa/one.jpg: ', $text);
        $this->assertStringContainsString('<URL>', $text);
        $this->assertStringContainsString('***', $text);
        $this->assertStringNotContainsString('https://', $text);
        $this->assertStringNotContainsString('cdn.example.com', $text);
        $this->assertStringNotContainsString('LeakedSignature999', $text);
        $this->assertStringNotContainsString('SuperSecretValue123', $text);

        $this->assertTrue(Storage::disk('media_s3')->exists('avatars/aa/one.jpg'), 'источник не тронут');
    }

    public function test_scheduler_registers_daily_run_at_0315_moscow_with_overlap_guard(): void
    {
        // routes/console.php загружается при bootstrap console kernel.
        $this->app->make(Kernel::class)->bootstrap();

        $events = collect($this->app->make(Schedule::class)->events());

        $avatarEvents = $events->filter(
            fn (Event $event): bool => str_contains((string) $event->command, 'backups:avatars-daily'),
        );

        $this->assertCount(1, $avatarEvents);

        $event = $avatarEvents->first();
        $this->assertSame('15 3 * * *', $event->expression);
        $this->assertSame('Europe/Moscow', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);

        // Существующее расписание дампов не тронуто.
        $dumpEvent = $events->first(
            fn (Event $event): bool => str_contains((string) $event->command, 'backups:schedule-daily'),
        );

        $this->assertNotNull($dumpEvent);
        $this->assertSame('30 3 * * *', $dumpEvent->expression);
        $this->assertSame('Europe/Moscow', $dumpEvent->timezone);
    }

    private function enableBackup(): void
    {
        config(['backups.avatars.enabled' => true]);
    }

    private function namespace(): string
    {
        return 'irsi-media-backup/'.config('app.env').'/';
    }
}
