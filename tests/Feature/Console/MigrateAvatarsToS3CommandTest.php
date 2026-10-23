<?php

namespace Tests\Feature\Console;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InterceptingFilesystem;
use Tests\TestCase;

class MigrateAvatarsToS3CommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('media');
    }

    public function test_dry_run_lists_candidates_without_writing_or_deleting(): void
    {
        Storage::disk('public')->put('avatars/legacy.jpg', 'legacy-bytes');

        $user = User::factory()->create([
            'avatar_url' => '/storage/avatars/legacy.jpg',
            'avatar_disk' => null,
            'avatar_path' => null,
        ]);

        $this->artisan('avatars:migrate-to-s3 --dry-run')
            ->expectsOutputToContain('would migrate: 1')
            ->assertExitCode(0);

        $user->refresh();
        $this->assertSame('/storage/avatars/legacy.jpg', $user->avatar_url);
        $this->assertNull($user->avatar_disk);
        $this->assertNull($user->avatar_path);

        $this->assertSame([], Storage::disk('media')->allFiles('avatars'));
        $this->assertTrue(Storage::disk('public')->exists('avatars/legacy.jpg'));
    }

    public function test_successful_migration_verifies_content_and_keeps_local_original(): void
    {
        Storage::disk('public')->put('avatars/legacy.jpg', 'legacy-bytes');

        $user = User::factory()->create([
            'avatar_url' => '/storage/avatars/legacy.jpg',
            'avatar_disk' => null,
            'avatar_path' => null,
        ]);

        $this->artisan('avatars:migrate-to-s3')
            ->expectsOutputToContain('migrated: 1')
            ->assertExitCode(0);

        $user->refresh();
        $this->assertSame('media', $user->avatar_disk);
        $this->assertMatchesRegularExpression('#^avatars/[A-Za-z0-9]{40}\.jpg$#', (string) $user->avatar_path);
        $this->assertSame('/storage/'.$user->avatar_path, $user->avatar_url);

        $this->assertTrue(Storage::disk('media')->exists((string) $user->avatar_path));
        $this->assertSame('legacy-bytes', Storage::disk('media')->get((string) $user->avatar_path));

        // Локальный оригинал сохранён.
        $this->assertTrue(Storage::disk('public')->exists('avatars/legacy.jpg'));
        $this->assertSame('legacy-bytes', Storage::disk('public')->get('avatars/legacy.jpg'));
    }

    public function test_client_avatar_migrates_into_clients_directory(): void
    {
        Storage::disk('public')->put('avatars/clients/legacy.jpg', 'client-bytes');

        $client = Client::factory()->create([
            'avatar_url' => '/storage/avatars/clients/legacy.jpg',
        ]);

        $this->artisan('avatars:migrate-to-s3')->assertExitCode(0);

        $client->refresh();
        $this->assertMatchesRegularExpression('#^avatars/clients/[A-Za-z0-9]{40}\.jpg$#', (string) $client->avatar_path);
        $this->assertTrue(Storage::disk('media')->exists((string) $client->avatar_path));
        $this->assertTrue(Storage::disk('public')->exists('avatars/clients/legacy.jpg'));
    }

    public function test_rerun_is_idempotent_and_foreign_objects_are_not_overwritten(): void
    {
        Storage::disk('public')->put('avatars/legacy.jpg', 'legacy-bytes');
        Storage::disk('media')->put('avatars/foreign-object.jpg', 'foreign-bytes');

        $user = User::factory()->create([
            'avatar_url' => '/storage/avatars/legacy.jpg',
            'avatar_disk' => null,
            'avatar_path' => null,
        ]);

        $this->artisan('avatars:migrate-to-s3')->assertExitCode(0);

        $user->refresh();
        $firstUrl = $user->avatar_url;
        $firstPath = $user->avatar_path;

        $this->artisan('avatars:migrate-to-s3')
            ->expectsOutputToContain('already migrated: 1')
            ->assertExitCode(0);

        $user->refresh();
        $this->assertSame($firstUrl, $user->avatar_url);
        $this->assertSame($firstPath, $user->avatar_path);

        $this->assertSame('foreign-bytes', Storage::disk('media')->get('avatars/foreign-object.jpg'));
        $this->assertCount(2, Storage::disk('media')->allFiles('avatars'));
    }

    public function test_missing_local_file_is_reported_and_fails(): void
    {
        $user = User::factory()->create([
            'avatar_url' => '/storage/avatars/gone.jpg',
            'avatar_disk' => null,
            'avatar_path' => null,
        ]);

        $this->artisan('avatars:migrate-to-s3')
            ->expectsOutputToContain('missing local files: 1')
            ->assertExitCode(1);

        $user->refresh();
        $this->assertSame('/storage/avatars/gone.jpg', $user->avatar_url);
        $this->assertNull($user->avatar_disk);
        $this->assertSame([], Storage::disk('media')->allFiles('avatars'));
    }

    public function test_verification_mismatch_rolls_back_object_and_keeps_record(): void
    {
        Storage::disk('public')->put('avatars/legacy.jpg', 'legacy-bytes');

        $user = User::factory()->create([
            'avatar_url' => '/storage/avatars/legacy.jpg',
            'avatar_disk' => null,
            'avatar_path' => null,
        ]);

        $wrapped = new InterceptingFilesystem(Storage::disk('media'));
        $wrapped->tamperRead = fn (string $value): string => $value.'-tampered';
        Storage::set('media', $wrapped);

        $this->artisan('avatars:migrate-to-s3')
            ->expectsOutputToContain('verification mismatches: 1')
            ->assertExitCode(1);

        $user->refresh();
        $this->assertSame('/storage/avatars/legacy.jpg', $user->avatar_url);
        $this->assertNull($user->avatar_disk);

        $this->assertCount(0, $wrapped->allFiles('avatars'));
        $this->assertTrue(Storage::disk('public')->exists('avatars/legacy.jpg'));
    }

    public function test_concurrent_avatar_change_is_detected_and_not_clobbered(): void
    {
        Storage::disk('public')->put('avatars/legacy.jpg', 'legacy-bytes');

        $user = User::factory()->create([
            'avatar_url' => '/storage/avatars/legacy.jpg',
            'avatar_disk' => null,
            'avatar_path' => null,
        ]);

        $wrapped = new InterceptingFilesystem(Storage::disk('media'));
        $wrapped->beforePut = function () use ($user): void {
            User::query()->whereKey($user->getKey())->update([
                'avatar_url' => '/storage/avatars/winner.jpg',
            ]);
        };
        Storage::set('media', $wrapped);

        $this->artisan('avatars:migrate-to-s3')
            ->expectsOutputToContain('conflicts: 1')
            ->assertExitCode(1);

        $user->refresh();
        $this->assertSame('/storage/avatars/winner.jpg', $user->avatar_url);
        $this->assertNull($user->avatar_disk);

        // Скопированный объект удалён, локальный оригинал не тронут.
        $this->assertCount(0, $wrapped->allFiles('avatars'));
        $this->assertTrue(Storage::disk('public')->exists('avatars/legacy.jpg'));
    }

    public function test_external_urls_are_skipped_without_changes(): void
    {
        $user = User::factory()->create([
            'avatar_url' => 'https://cdn.example.com/avatar.jpg',
            'avatar_disk' => null,
            'avatar_path' => null,
        ]);

        $this->artisan('avatars:migrate-to-s3')
            ->expectsOutputToContain('skipped (foreign/external): 1')
            ->assertExitCode(0);

        $user->refresh();
        $this->assertSame('https://cdn.example.com/avatar.jpg', $user->avatar_url);
        $this->assertNull($user->avatar_disk);
        $this->assertSame([], Storage::disk('media')->allFiles('avatars'));
    }

    public function test_real_run_refuses_when_media_disk_shares_public_root(): void
    {
        // Целевой диск указывает на тот же root, что и public.
        Storage::set('media', Storage::disk('public'));

        $this->artisan('avatars:migrate-to-s3')
            ->assertExitCode(1);
    }

    public function test_real_run_refuses_without_media_public_url_on_s3(): void
    {
        config([
            'filesystems.disks.media.driver' => 's3',
            'filesystems.disks.media.url' => null,
        ]);

        $this->artisan('avatars:migrate-to-s3')
            ->expectsOutputToContain('MEDIA_PUBLIC_URL')
            ->assertExitCode(1);
    }
}
