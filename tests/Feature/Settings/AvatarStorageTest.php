<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class AvatarStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media_local');
        Storage::fake('public');
    }

    public function test_avatar_upload_stores_file_on_media_disk_and_returns_frontend_url(): void
    {
        $user = User::factory()->create(['avatar_url' => null]);

        $response = $this
            ->actingAs($user)
            ->post(route('admin.settings.avatar'), [
                'avatar' => UploadedFile::fake()->image('me.jpg'),
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $url = $response->json('avatar_url');
        $this->assertIsString($url);
        $this->assertMatchesRegularExpression('#^/storage/avatars/[A-Za-z0-9]{40}\.jpg$#', $url);

        $user->refresh();
        $this->assertSame($url, $user->avatar_url);
        $this->assertSame('media_local', $user->avatar_disk);
        $this->assertTrue(Storage::disk('media_local')->exists($user->avatar_path));
        $this->assertFalse(Storage::disk('public')->exists($user->avatar_path));
    }

    public function test_avatar_upload_replaces_legacy_avatar_only_after_new_file_is_written(): void
    {
        Storage::disk('public')->put('avatars/legacy-old.jpg', 'old-bytes');

        $user = User::factory()->create([
            'avatar_url' => '/storage/avatars/legacy-old.jpg',
            'avatar_disk' => null,
            'avatar_path' => null,
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('admin.settings.avatar'), [
                'avatar' => UploadedFile::fake()->image('me.jpg'),
            ]);

        $response->assertOk();
        $url = $response->json('avatar_url');

        $user->refresh();
        $this->assertSame($url, $user->avatar_url);
        $this->assertNotSame('/storage/avatars/legacy-old.jpg', $url);

        // Старый файл удалён только после успешной записи и обновления БД.
        $this->assertFalse(Storage::disk('public')->exists('avatars/legacy-old.jpg'));
        $this->assertTrue(Storage::disk('media_local')->exists($user->avatar_path));
    }

    public function test_avatar_upload_rejects_invalid_file(): void
    {
        $user = User::factory()->create([
            'avatar_url' => '/storage/avatars/keep.jpg',
            'avatar_disk' => null,
            'avatar_path' => null,
        ]);
        Storage::disk('public')->put('avatars/keep.jpg', 'keep-bytes');

        $response = $this
            ->actingAs($user)
            ->post(route('admin.settings.avatar'), [
                'avatar' => UploadedFile::fake()->create('evil.txt', 10, 'text/plain'),
            ]);

        $response->assertSessionHasErrors('avatar');

        $user->refresh();
        $this->assertSame('/storage/avatars/keep.jpg', $user->avatar_url);
        $this->assertTrue(Storage::disk('public')->exists('avatars/keep.jpg'));
        $this->assertSame([], Storage::disk('media_local')->allFiles('avatars'));
    }

    public function test_failed_write_keeps_previous_avatar_and_url(): void
    {
        Storage::disk('public')->put('avatars/keep.jpg', 'keep-bytes');

        $user = User::factory()->create([
            'avatar_url' => '/storage/avatars/keep.jpg',
            'avatar_disk' => null,
            'avatar_path' => null,
        ]);

        $failing = Mockery::mock(Filesystem::class);
        $failing->shouldReceive('put')->andThrow(new \RuntimeException('storage down'));
        $failing->shouldReceive('delete')->andReturn(false);
        Storage::set('media_local', $failing);

        $response = $this
            ->actingAs($user)
            ->post(route('admin.settings.avatar'), [
                'avatar' => UploadedFile::fake()->image('me.jpg'),
            ]);

        $response->assertStatus(500);

        $user->refresh();
        $this->assertSame('/storage/avatars/keep.jpg', $user->avatar_url);
        $this->assertNull($user->avatar_disk);
        $this->assertTrue(Storage::disk('public')->exists('avatars/keep.jpg'));
    }

    public function test_destroy_avatar_removes_owned_legacy_file(): void
    {
        Storage::disk('public')->put('avatars/old.jpg', 'old-bytes');

        $user = User::factory()->create([
            'avatar_url' => '/storage/avatars/old.jpg',
            'avatar_disk' => null,
            'avatar_path' => null,
        ]);

        $this->actingAs($user)
            ->delete(route('admin.settings.avatar.destroy'))
            ->assertRedirect();

        $user->refresh();
        $this->assertNull($user->avatar_url);
        $this->assertFalse(Storage::disk('public')->exists('avatars/old.jpg'));
    }

    public function test_destroy_avatar_with_external_url_never_deletes_app_files(): void
    {
        Storage::disk('public')->put('avatars/keep.jpg', 'keep-bytes');

        $user = User::factory()->create([
            'avatar_url' => 'https://cdn.example.com/somewhere/keep.jpg',
            'avatar_disk' => null,
            'avatar_path' => null,
        ]);

        $this->actingAs($user)
            ->delete(route('admin.settings.avatar.destroy'))
            ->assertRedirect();

        $user->refresh();
        $this->assertNull($user->avatar_url);
        $this->assertTrue(Storage::disk('public')->exists('avatars/keep.jpg'));
    }

    public function test_jpeg_with_spoofed_file_name_is_stored_with_jpg_extension(): void
    {
        $user = User::factory()->create(['avatar_url' => null]);

        // JPEG-содержимое, но имя файла подменено на .png. Настоящий
        // UploadedFile (не Testing\File): MIME берётся из содержимого,
        // как в реальном запросе.
        $jpeg = UploadedFile::fake()->image('real.jpg');
        $jpegBytes = file_get_contents($jpeg->getPathname());
        $this->assertIsString($jpegBytes);

        $tempPath = tempnam(sys_get_temp_dir(), 'avatar');
        $this->assertIsString($tempPath);
        file_put_contents($tempPath, $jpegBytes);
        $spoofed = new UploadedFile($tempPath, 'spoofed.png', null, null, true);

        try {
            $response = $this
                ->actingAs($user)
                ->post(route('admin.settings.avatar'), [
                    'avatar' => $spoofed,
                ]);
        } finally {
            @unlink($tempPath);
        }

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $url = (string) $response->json('avatar_url');
        $this->assertMatchesRegularExpression('#^/storage/avatars/[A-Za-z0-9]{40}\.jpg$#', $url);

        $user->refresh();
        $this->assertMatchesRegularExpression('#^avatars/[A-Za-z0-9]{40}\.jpg$#', (string) $user->avatar_path);
        $this->assertStringNotContainsString('.png', (string) $user->avatar_path);
        $this->assertSame('media_local', $user->avatar_disk);
        $this->assertTrue(Storage::disk('media_local')->exists((string) $user->avatar_path));
    }
}
