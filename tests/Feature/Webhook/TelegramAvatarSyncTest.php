<?php

namespace Tests\Feature\Webhook;

use App\Models\Client;
use App\Models\User;
use App\Webhooks\TelegramWebhookHandler;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

class TelegramAvatarSyncTest extends TestCase
{
    use RefreshDatabase;

    private const TELEGRAM_ID = '777000111';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.bot_token' => 'test-bot-token']);
        Storage::fake('media');
        Storage::fake('public');

        Http::fake([
            '*getUserProfilePhotos*' => Http::response([
                'ok' => true,
                'result' => [
                    'total_count' => 1,
                    'photos' => [['0' => ['file_id' => 'photo-file-id']]],
                ],
            ]),
            '*getFile*' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'photos/file_123.jpg'],
            ]),
            '*api.telegram.org/file/bot*' => Http::response('tg-image-bytes'),
        ]);
    }

    public function test_master_avatar_is_stored_with_random_name_without_telegram_id(): void
    {
        $user = User::factory()->create([
            'telegram_id' => self::TELEGRAM_ID,
            'avatar_url' => null,
        ]);

        $this->invokePrivate('syncTelegramAvatar', [$user, self::TELEGRAM_ID]);

        $user->refresh();
        $this->assertMatchesRegularExpression('#^/storage/avatars/[A-Za-z0-9]{40}\.jpg$#', $user->avatar_url);
        $this->assertStringNotContainsString(self::TELEGRAM_ID, (string) $user->avatar_url);
        $this->assertTrue(Storage::disk('media')->exists((string) $user->avatar_path));
        $this->assertSame('tg-image-bytes', Storage::disk('media')->get((string) $user->avatar_path));
    }

    public function test_master_avatar_sync_is_skipped_when_avatar_exists(): void
    {
        $user = User::factory()->create([
            'telegram_id' => self::TELEGRAM_ID,
            'avatar_url' => '/storage/avatars/existing.jpg',
        ]);

        $this->invokePrivate('syncTelegramAvatar', [$user, self::TELEGRAM_ID]);

        Http::assertNothingSent();
        $this->assertSame('/storage/avatars/existing.jpg', $user->fresh()?->avatar_url);
    }

    public function test_client_avatar_is_stored_under_clients_directory(): void
    {
        $client = Client::factory()->withTelegram()->create(['avatar_url' => null]);
        $telegramId = (string) $client->telegram_id;

        $this->invokePrivate('syncClientTelegramAvatar', [$client, $telegramId]);

        $client->refresh();
        $this->assertMatchesRegularExpression('#^/storage/avatars/clients/[A-Za-z0-9]{40}\.jpg$#', (string) $client->avatar_url);
        $this->assertStringNotContainsString($telegramId, (string) $client->avatar_url);
        $this->assertTrue(Storage::disk('media')->exists((string) $client->avatar_path));
    }

    public function test_client_avatar_sync_write_failure_leaves_avatar_unset_without_throwing(): void
    {
        $client = Client::factory()->withTelegram()->create(['avatar_url' => null]);

        $failing = \Mockery::mock(Filesystem::class);
        $failing->shouldReceive('put')->andThrow(new \RuntimeException('storage down'));
        $failing->shouldReceive('delete')->andReturn(false);
        Storage::set('media', $failing);

        $this->invokePrivate('syncClientTelegramAvatar', [$client, (string) $client->telegram_id]);

        $client->refresh();
        $this->assertNull($client->avatar_url);
    }

    /**
     * @param  array<int, mixed>  $args
     */
    private function invokePrivate(string $method, array $args): void
    {
        $handler = app(TelegramWebhookHandler::class);
        $reflection = new ReflectionMethod($handler, $method);
        $reflection->setAccessible(true);
        $reflection->invoke($handler, ...$args);
    }
}
