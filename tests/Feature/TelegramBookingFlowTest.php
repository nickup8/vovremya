<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TelegramBookingFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'test_secret_123';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.telegram.secret_token', $this->secret);
        Config::set('services.telegram.bot_token', 'fake-token');
    }

    private function postTelegram(array $payload): TestResponse
    {
        return $this->postJson('/webhooks/telegram', $payload, [
            'X-Telegram-Bot-Api-Secret-Token' => $this->secret,
        ]);
    }

    public function test_happy_path_deep_link_then_contact(): void
    {
        $master = User::factory()->master()->create([
            'settings' => [
                'timezone' => 'Europe/Moscow',
                'timezone_confirmed' => true,
                'booking_flow_type' => 'free_verification',
            ],
        ]);

        $service = Service::factory()->create([
            'user_id' => $master->id,
            'duration_minutes' => 60,
            'price' => 2000,
        ]);

        $appointment = Appointment::create([
            'master_id' => $master->id,
            'service_id' => $service->id,
            'start_time' => now()->addDay()->setTime(10, 0),
            'status' => 'booked',
            'provider' => 'telegram',
        ]);

        $chatId = 99999;

        $response1 = $this->postTelegram([
            'message' => [
                'chat' => ['id' => $chatId],
                'text' => "/start book_{$appointment->id}",
            ],
        ]);

        $response1->assertOk();
        $this->assertEquals($appointment->id, Cache::get("bot_pending:telegram:{$chatId}"));

        $response2 = $this->postTelegram([
            'message' => [
                'chat' => ['id' => $chatId],
                'contact' => [
                    'phone_number' => '79001112233',
                    'user_id' => $chatId,
                    'first_name' => 'Иван',
                ],
            ],
        ]);

        $response2->assertOk();

        $appointment->refresh();
        $this->assertNotNull($appointment->client_id);
        $this->assertContains($appointment->status->value, ['booked']);
        $this->assertNull(Cache::get("bot_pending:telegram:{$chatId}"));

        $client = $appointment->client;
        $this->assertStringContainsString('79001112233', $client->phone);
    }

    public function test_contact_without_pending_state_returns_200(): void
    {
        Cache::flush();

        $response = $this->postTelegram([
            'message' => [
                'chat' => ['id' => 77777],
                'contact' => [
                    'phone_number' => '79009998877',
                    'user_id' => 77777,
                    'first_name' => 'Одинокий',
                ],
            ],
        ]);

        $response->assertOk();
    }

    public function test_provider_isolation_via_cache(): void
    {
        Cache::put('bot_pending:telegram:55555', 123, now()->addMinutes(15));

        $this->assertEquals(123, Cache::get('bot_pending:telegram:55555'));
        $this->assertNull(Cache::get('bot_pending:max:55555'));
    }
}
