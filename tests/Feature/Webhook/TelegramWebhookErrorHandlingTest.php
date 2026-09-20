<?php

namespace Tests\Feature\Webhook;

use App\Webhooks\TelegramWebhookHandler;
use DefStudio\Telegraph\Models\TelegraphBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramWebhookErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.telegram.secret_token' => 'test_tg_secret',
        ]);
    }

    public function test_bypass_returns_200_when_handler_throws(): void
    {
        TelegraphBot::create([
            'token' => 'fake-bot-token',
            'name' => 'test-bot',
        ]);

        $mock = $this->mock(TelegramWebhookHandler::class);
        $mock->shouldReceive('handle')->once()->andThrow(new \RuntimeException('handler exploded'));

        $response = $this->postJson('/webhooks/telegram/bypass', [
            'message' => ['chat' => ['id' => 123], 'text' => '/start'],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'test_tg_secret',
        ]);

        $response->assertOk();
    }

    public function test_telegraph_returns_204_when_handler_throws(): void
    {
        $bot = TelegraphBot::create([
            'token' => 'valid-token-abc',
            'name' => 'test-bot',
        ]);

        $mock = $this->mock(TelegramWebhookHandler::class);
        $mock->shouldReceive('handle')->once()->andThrow(new \RuntimeException('handler exploded'));

        $response = $this->postJson("/telegraph/valid-token-abc/webhook", [
            'message' => ['chat' => ['id' => 123], 'text' => '/start'],
        ]);

        $response->assertStatus(204);
    }
}
