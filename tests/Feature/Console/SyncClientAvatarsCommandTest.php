<?php

namespace Tests\Feature\Console;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SyncClientAvatarsCommandTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_command_downloads_missing_client_avatar_with_random_name(): void
    {
        $client = Client::factory()->withTelegram()->create(['avatar_url' => null]);
        $telegramId = (string) $client->telegram_id;

        $this->artisan('clients:sync-avatars')
            ->expectsOutputToContain('Done:')
            ->assertExitCode(0);

        $client->refresh();
        $this->assertMatchesRegularExpression('#^/storage/avatars/clients/[A-Za-z0-9]{40}\.jpg$#', (string) $client->avatar_url);
        $this->assertStringNotContainsString($telegramId, (string) $client->avatar_url);
        $this->assertTrue(Storage::disk('media')->exists((string) $client->avatar_path));
        $this->assertSame('tg-image-bytes', Storage::disk('media')->get((string) $client->avatar_path));
    }

    public function test_command_skips_clients_that_already_have_avatar(): void
    {
        Client::factory()->withTelegram()->create([
            'avatar_url' => '/storage/avatars/clients/existing.jpg',
        ]);

        $this->artisan('clients:sync-avatars')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(
            '/storage/avatars/clients/existing.jpg',
            Client::query()->first()?->avatar_url
        );
    }

    public function test_command_fails_without_telegram_token(): void
    {
        config(['services.telegram.bot_token' => null]);

        $this->artisan('clients:sync-avatars')->assertExitCode(1);
    }
}
