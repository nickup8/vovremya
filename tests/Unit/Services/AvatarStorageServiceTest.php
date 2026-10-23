<?php

namespace Tests\Unit\Services;

use App\Exceptions\AvatarStorageException;
use App\Models\Client;
use App\Models\User;
use App\Services\Avatars\AvatarStorageService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Support\InterceptingFilesystem;
use Tests\TestCase;

class AvatarStorageServiceTest extends TestCase
{
    use RefreshDatabase;

    private AvatarStorageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media_local');
        Storage::fake('media_s3');
        Storage::fake('public');

        $this->service = app(AvatarStorageService::class);
    }

    public function test_replace_writes_random_named_file_and_updates_reference(): void
    {
        $user = User::factory()->create([
            'telegram_id' => '777000111',
            'phone' => '+79001234567',
            'avatar_url' => null,
        ]);

        $url = $this->service->replace($user, 'avatar-bytes', 'jpg');

        $this->assertMatchesRegularExpression('#^/storage/avatars/[A-Za-z0-9]{40}\.jpg$#', $url);
        $this->assertStringNotContainsString('777000111', $url);
        $this->assertStringNotContainsString('9001234567', $url);

        $user->refresh();
        $this->assertSame($url, $user->avatar_url);
        $this->assertSame('media_local', $user->avatar_disk);
        $this->assertSame('avatars/'.substr($url, strlen('/storage/avatars/')), $user->avatar_path);

        $this->assertTrue(Storage::disk('media_local')->exists($user->avatar_path));
        $this->assertSame('avatar-bytes', Storage::disk('media_local')->get($user->avatar_path));
    }

    public function test_replace_uses_local_disk_by_default_without_fake(): void
    {
        Storage::set('media_local', Storage::createLocalDriver([
            'root' => storage_path('app/public'),
            'throw' => true,
        ]));

        $user = User::factory()->create(['avatar_url' => null]);
        $storedPath = null;

        try {
            $url = $this->service->replace($user, 'local-bytes', 'png');

            $this->assertMatchesRegularExpression('#^/storage/avatars/[A-Za-z0-9]{40}\.png$#', $url);

            $user->refresh();
            $storedPath = $user->avatar_path;

            $this->assertFileExists(storage_path('app/public/'.$storedPath));
            $this->assertSame('local-bytes', file_get_contents(storage_path('app/public/'.$storedPath)));
        } finally {
            if ($storedPath !== null && file_exists(storage_path('app/public/'.$storedPath))) {
                unlink(storage_path('app/public/'.$storedPath));
            }
        }
    }

    public function test_replace_removes_old_legacy_file_after_successful_update(): void
    {
        Storage::disk('public')->put('avatars/old.jpg', 'old-bytes');

        $user = User::factory()->create([
            'avatar_url' => '/storage/avatars/old.jpg',
            'avatar_disk' => null,
            'avatar_path' => null,
        ]);

        $url = $this->service->replace($user, 'new-bytes', 'jpg');

        $this->assertNotSame('/storage/avatars/old.jpg', $url);
        $this->assertFalse(Storage::disk('public')->exists('avatars/old.jpg'));
        $this->assertTrue(Storage::disk('media_local')->exists('avatars/'.substr($url, strlen('/storage/avatars/'))));
    }

    public function test_failed_write_keeps_old_file_and_url(): void
    {
        Storage::disk('public')->put('avatars/old.jpg', 'old-bytes');

        $user = User::factory()->create([
            'avatar_url' => '/storage/avatars/old.jpg',
            'avatar_disk' => null,
            'avatar_path' => null,
        ]);

        $failing = Mockery::mock(Filesystem::class);
        $failing->shouldReceive('put')->andThrow(new \RuntimeException('S3 unavailable'));
        $failing->shouldReceive('delete')->andReturn(false);
        Storage::set('media_local', $failing);

        try {
            $this->service->replace($user, 'new-bytes', 'jpg');
            $this->fail('Expected AvatarStorageException was not thrown.');
        } catch (AvatarStorageException) {
            // ожидаемо
        }

        $user->refresh();
        $this->assertSame('/storage/avatars/old.jpg', $user->avatar_url);
        $this->assertNull($user->avatar_disk);
        $this->assertTrue(Storage::disk('public')->exists('avatars/old.jpg'));
    }

    public function test_db_failure_after_write_cleans_new_file_and_keeps_old_avatar(): void
    {
        Storage::disk('public')->put('avatars/old.jpg', 'old-bytes');

        $user = User::factory()->create([
            'avatar_url' => '/storage/avatars/old.jpg',
            'avatar_disk' => null,
            'avatar_path' => null,
        ]);

        // Модель, у которой запрос к БД падает — симуляция отказа после записи файла.
        $failingDb = new class extends User
        {
            public function newQuery()
            {
                throw new \RuntimeException('db down');
            }
        };
        $failingDb->forceFill([
            'avatar_url' => '/storage/avatars/old.jpg',
            'avatar_disk' => null,
            'avatar_path' => null,
        ]);

        try {
            $this->service->replace($failingDb, 'new-bytes', 'jpg');
            $this->fail('Expected AvatarStorageException was not thrown.');
        } catch (AvatarStorageException $e) {
            $this->assertStringContainsString('DB update failed', $e->getMessage());
        }

        $user->refresh();
        $this->assertSame('/storage/avatars/old.jpg', $user->avatar_url);
        $this->assertNull($user->avatar_disk);
        $this->assertTrue(Storage::disk('public')->exists('avatars/old.jpg'));

        // Новый файл удалён после ошибки БД.
        $this->assertSame([], Storage::disk('media_local')->allFiles('avatars'));
    }

    public function test_concurrent_change_is_not_overwritten_and_stale_file_removed(): void
    {
        Storage::disk('public')->put('avatars/old.jpg', 'old-bytes');

        $user = User::factory()->create([
            'avatar_url' => '/storage/avatars/old.jpg',
            'avatar_disk' => null,
            'avatar_path' => null,
        ]);

        $wrapped = new InterceptingFilesystem(Storage::disk('media_local'));
        $wrapped->beforePut = function () use ($user): void {
            $user->newQuery()
                ->whereKey($user->getKey())
                ->update(['avatar_url' => '/storage/avatars/concurrent.jpg']);
        };
        Storage::set('media_local', $wrapped);

        try {
            $this->service->replace($user, 'new-bytes', 'jpg');
            $this->fail('Expected AvatarStorageException was not thrown.');
        } catch (AvatarStorageException) {
            // ожидаемо
        }

        $user->refresh();
        $this->assertSame('/storage/avatars/concurrent.jpg', $user->avatar_url);
        $this->assertNull($user->avatar_disk);

        $this->assertSame([], $wrapped->allFiles('avatars'));
        $this->assertTrue(Storage::disk('public')->exists('avatars/old.jpg'));
    }

    public function test_remove_deletes_owned_legacy_file(): void
    {
        Storage::disk('public')->put('avatars/old.jpg', 'old-bytes');

        $user = User::factory()->create([
            'avatar_url' => '/storage/avatars/old.jpg',
            'avatar_disk' => null,
            'avatar_path' => null,
        ]);

        $this->assertTrue($this->service->remove($user));

        $user->refresh();
        $this->assertNull($user->avatar_url);
        $this->assertNull($user->avatar_disk);
        $this->assertNull($user->avatar_path);
        $this->assertFalse(Storage::disk('public')->exists('avatars/old.jpg'));
    }

    public function test_remove_never_touches_external_urls(): void
    {
        Storage::disk('public')->put('avatars/keep.jpg', 'keep-bytes');

        $user = User::factory()->create([
            'avatar_url' => 'https://cdn.example.com/avatars/keep.jpg',
            'avatar_disk' => null,
            'avatar_path' => null,
        ]);

        $this->assertTrue($this->service->remove($user));

        $user->refresh();
        $this->assertNull($user->avatar_url);
        $this->assertTrue(Storage::disk('public')->exists('avatars/keep.jpg'));
    }

    public function test_stale_remove_does_not_delete_newer_avatar(): void
    {
        Storage::disk('public')->put('avatars/old.jpg', 'old-bytes');

        $user = User::factory()->create([
            'avatar_url' => '/storage/avatars/old.jpg',
            'avatar_disk' => null,
            'avatar_path' => null,
        ]);

        // Снимок записи до конкурентной смены — «устаревшая» операция.
        $stale = User::query()->findOrFail($user->getKey());

        User::query()->whereKey($user->getKey())->update([
            'avatar_url' => '/storage/avatars/newer.jpg',
        ]);

        $this->assertFalse($this->service->remove($stale));
        $this->assertSame('/storage/avatars/newer.jpg', $user->fresh()?->avatar_url);
        $this->assertTrue(Storage::disk('public')->exists('avatars/old.jpg'));
    }

    public function test_reference_resolution_for_legacy_external_and_unowned_paths(): void
    {
        $legacy = (new User)->forceFill(['avatar_url' => '/storage/avatars/clients/a.jpg']);
        $this->assertSame(
            ['owned' => true, 'disk' => 'public', 'path' => 'avatars/clients/a.jpg'],
            $legacy->avatarReference()
        );

        $outside = (new User)->forceFill(['avatar_url' => '/storage/other/a.jpg']);
        $this->assertFalse($outside->avatarReference()['owned']);

        $external = (new User)->forceFill(['avatar_url' => 'https://evil.example.com/storage/avatars/a.jpg']);
        $this->assertSame(
            ['owned' => false, 'disk' => null, 'path' => null],
            $external->avatarReference()
        );

        $explicit = (new User)->forceFill([
            'avatar_url' => 'https://cdn.example.com/x.jpg',
            'avatar_disk' => 'media_local',
            'avatar_path' => 'avatars/x.jpg',
        ]);
        $this->assertSame(
            ['owned' => true, 'disk' => 'media_local', 'path' => 'avatars/x.jpg'],
            $explicit->avatarReference()
        );

        $foreignDisk = (new User)->forceFill([
            'avatar_url' => 'https://cdn.example.com/x.jpg',
            'avatar_disk' => 's3',
            'avatar_path' => 'avatars/x.jpg',
        ]);
        $this->assertFalse($foreignDisk->avatarReference()['owned']);

        $traversal = (new User)->forceFill([
            'avatar_url' => null,
            'avatar_disk' => 'media_local',
            'avatar_path' => 'avatars/../../secrets.env',
        ]);
        $this->assertFalse($traversal->avatarReference()['owned']);
    }

    public function test_url_builds_absolute_s3_url_from_configured_public_url(): void
    {
        config([
            'avatars.disk' => 'media_s3',
            'filesystems.disks.media_s3.url' => 'https://cdn.example.com/irsi-media',
        ]);

        $user = User::factory()->create(['avatar_url' => null]);

        $url = $this->service->replace($user, 's3-bytes', 'jpg');

        $this->assertMatchesRegularExpression('#^https://cdn\.example\.com/irsi-media/avatars/[A-Za-z0-9]{40}\.jpg$#', $url);
        $user->refresh();
        $this->assertSame($url, $user->avatar_url);
        $this->assertSame('media_s3', $user->avatar_disk);
        $this->assertTrue(Storage::disk('media_s3')->exists($user->avatar_path));
    }

    public function test_s3_without_public_url_fails_without_saving(): void
    {
        config([
            'avatars.disk' => 'media_s3',
            'filesystems.disks.media_s3.url' => null,
        ]);

        $user = User::factory()->create(['avatar_url' => null]);

        try {
            $this->service->replace($user, 's3-bytes', 'jpg');
            $this->fail('Expected AvatarStorageException was not thrown.');
        } catch (AvatarStorageException $e) {
            $this->assertStringContainsString('MEDIA_PUBLIC_URL', $e->getMessage());
        }

        $user->refresh();
        $this->assertNull($user->avatar_url);
        $this->assertSame([], Storage::disk('media_s3')->allFiles('avatars'));
    }

    public function test_client_avatar_goes_to_clients_directory(): void
    {
        $client = Client::factory()->create(['avatar_url' => null]);

        $url = $this->service->replace($client, 'client-bytes', 'jpg');

        $this->assertMatchesRegularExpression('#^/storage/avatars/clients/[A-Za-z0-9]{40}\.jpg$#', $url);

        $client->refresh();
        $this->assertSame('avatars/clients/'.substr($url, strlen('/storage/avatars/clients/')), $client->avatar_path);
        $this->assertTrue(Storage::disk('media_local')->exists($client->avatar_path));
    }

    public function test_replace_after_switch_to_s3_deletes_previous_local_avatar_from_local_storage(): void
    {
        config(['avatars.disk' => 'media_local']);

        $user = User::factory()->create(['avatar_url' => null]);
        $this->service->replace($user, 'old-local-bytes', 'jpg');
        $user->refresh();
        $oldPath = (string) $user->avatar_path;
        $this->assertTrue(Storage::disk('media_local')->exists($oldPath));

        // Переключение режима новых загрузок на S3.
        config([
            'avatars.disk' => 'media_s3',
            'filesystems.disks.media_s3.url' => 'https://cdn.example.com/irsi-media',
        ]);

        $url = $this->service->replace($user, 'new-s3-bytes', 'jpg');

        $this->assertMatchesRegularExpression('#^https://cdn\.example\.com/irsi-media/avatars/[A-Za-z0-9]{40}\.jpg$#', $url);

        $user->refresh();
        $this->assertSame('media_s3', $user->avatar_disk);
        $this->assertSame($url, $user->avatar_url);
        $this->assertSame('new-s3-bytes', Storage::disk('media_s3')->get((string) $user->avatar_path));

        // Старый аватар удалён именно из локального хранилища.
        $this->assertFalse(Storage::disk('media_local')->exists($oldPath));
    }

    public function test_remove_after_switch_to_s3_deletes_local_avatar_from_local_storage(): void
    {
        config(['avatars.disk' => 'media_local']);

        $user = User::factory()->create(['avatar_url' => null]);
        $this->service->replace($user, 'local-bytes', 'jpg');
        $user->refresh();
        $oldPath = (string) $user->avatar_path;
        $this->assertTrue(Storage::disk('media_local')->exists($oldPath));

        config(['avatars.disk' => 'media_s3']);

        $this->assertTrue($this->service->remove($user));

        $user->refresh();
        $this->assertNull($user->avatar_url);
        // Удаление обращается к локальному хранилищу, а не к S3.
        $this->assertFalse(Storage::disk('media_local')->exists($oldPath));
        $this->assertSame([], Storage::disk('media_s3')->allFiles('avatars'));
    }

    public function test_replace_after_return_to_local_deletes_previous_s3_avatar_from_s3_storage(): void
    {
        config([
            'avatars.disk' => 'media_s3',
            'filesystems.disks.media_s3.url' => 'https://cdn.example.com/irsi-media',
        ]);

        $user = User::factory()->create(['avatar_url' => null]);
        $this->service->replace($user, 'old-s3-bytes', 'jpg');
        $user->refresh();
        $oldPath = (string) $user->avatar_path;
        $this->assertTrue(Storage::disk('media_s3')->exists($oldPath));

        // Возврат к локальным загрузкам.
        config(['avatars.disk' => 'media_local']);

        $url = $this->service->replace($user, 'new-local-bytes', 'jpg');

        $this->assertMatchesRegularExpression('#^/storage/avatars/[A-Za-z0-9]{40}\.jpg$#', $url);

        $user->refresh();
        $this->assertSame('media_local', $user->avatar_disk);
        $this->assertSame($url, $user->avatar_url);
        $this->assertSame('new-local-bytes', Storage::disk('media_local')->get((string) $user->avatar_path));

        // Старый аватар удалён именно из S3, а не из локального хранилища.
        $this->assertFalse(Storage::disk('media_s3')->exists($oldPath));
    }

    public function test_remove_after_return_to_local_deletes_s3_avatar_from_s3_storage(): void
    {
        config([
            'avatars.disk' => 'media_s3',
            'filesystems.disks.media_s3.url' => 'https://cdn.example.com/irsi-media',
        ]);

        $user = User::factory()->create(['avatar_url' => null]);
        $this->service->replace($user, 's3-bytes', 'jpg');
        $user->refresh();
        $oldPath = (string) $user->avatar_path;
        $this->assertTrue(Storage::disk('media_s3')->exists($oldPath));

        config(['avatars.disk' => 'media_local']);

        $this->assertTrue($this->service->remove($user));

        $user->refresh();
        $this->assertNull($user->avatar_url);
        // Удаление обращается к S3, а не к локальному хранилищу.
        $this->assertFalse(Storage::disk('media_s3')->exists($oldPath));
        $this->assertSame([], Storage::disk('media_local')->allFiles('avatars'));
    }
}
