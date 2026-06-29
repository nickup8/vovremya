<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $master;
    private User $otherMaster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->master = User::factory()->master()->create();
        $this->otherMaster = User::factory()->master()->create();
    }

    // ═══════════════════════════════════════════
    // POLICIES
    // ═══════════════════════════════════════════

    public function test_master_cannot_update_other_masters_appointment(): void
    {
        $service = Service::factory()->create(['user_id' => $this->master->id]);
        $appointment = Appointment::factory()->create([
            'master_id' => $this->otherMaster->id,
            'service_id' => $service->id,
        ]);

        $response = $this->actingAs($this->master)
            ->patchJson("/admin/appointments/{$appointment->id}/status", [
                'status' => 'completed',
            ]);

        $response->assertStatus(403);
    }

    public function test_master_can_update_own_appointment(): void
    {
        $service = Service::factory()->create(['user_id' => $this->master->id]);
        $appointment = Appointment::factory()->create([
            'master_id' => $this->master->id,
            'service_id' => $service->id,
            'status' => 'pending_client',
        ]);

        $response = $this->actingAs($this->master)
            ->patchJson("/admin/appointments/{$appointment->id}/status", [
                'status' => 'confirmed',
            ]);

        $response->assertRedirect();
    }

    public function test_master_cannot_update_other_masters_client(): void
    {
        $client = Client::factory()->create(['user_id' => $this->otherMaster->id]);

        $response = $this->actingAs($this->master)
            ->putJson("/admin/clients/{$client->id}", [
                'name' => 'Hacked',
                'phone' => '+79990001122',
            ]);

        $response->assertStatus(403);
    }

    public function test_master_can_update_own_client(): void
    {
        $client = Client::factory()->create(['user_id' => $this->master->id]);

        $response = $this->actingAs($this->master)
            ->putJson("/admin/clients/{$client->id}", [
                'name' => 'Updated Name',
                'phone' => $client->phone,
            ]);

        $response->assertRedirect();
    }

    // ═══════════════════════════════════════════
    // WEBHOOK HMAC FAIL-CLOSED
    // ═══════════════════════════════════════════

    public function test_telegram_webhook_without_secret_config_returns_500(): void
    {
        config()->offsetUnset('services.telegram.secret_token');

        $response = $this->postJson('/webhooks/telegram', [
            'message' => ['chat' => ['id' => 123], 'text' => '/start book_1'],
        ]);

        $response->assertStatus(500);
    }

    public function test_telegram_webhook_without_signature_header_returns_403(): void
    {
        config()->offsetSet('services.telegram.secret_token', 'my-secret-token');

        $response = $this->postJson('/webhooks/telegram', [
            'message' => ['chat' => ['id' => 123], 'text' => 'random text'],
        ]);

        $response->assertStatus(403);
    }

    public function test_telegram_webhook_with_invalid_signature_returns_403(): void
    {
        config()->offsetSet('services.telegram.secret_token', 'my-secret-token');

        $response = $this->postJson('/webhooks/telegram', [
            'message' => ['chat' => ['id' => 123], 'text' => 'random text'],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong-token',
        ]);

        $response->assertStatus(403);
    }

    public function test_telegram_webhook_with_valid_signature_is_processed(): void
    {
        config()->offsetSet('services.telegram.secret_token', 'my-secret-token');

        $response = $this->postJson('/webhooks/telegram', [
            'message' => ['chat' => ['id' => 123], 'text' => 'random text'],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'my-secret-token',
        ]);

        $response->assertOk();
    }

    public function test_max_webhook_without_secret_config_returns_500(): void
    {
        config()->offsetUnset('services.max.secret_token');

        $response = $this->postJson('/webhooks/max', [
            'event' => 'message_created',
            'data' => ['body' => '/start book_1', 'chat' => ['id' => 123]],
        ]);

        $response->assertStatus(500);
    }

    public function test_max_webhook_without_signature_header_returns_403(): void
    {
        config()->offsetSet('services.max.secret_token', 'max-secret');

        $response = $this->postJson('/webhooks/max', [
            'event' => 'message_created',
            'data' => ['body' => 'random', 'chat' => ['id' => 123]],
        ]);

        $response->assertStatus(403);
    }

    public function test_max_webhook_with_invalid_signature_returns_403(): void
    {
        config()->offsetSet('services.max.secret_token', 'max-secret');

        $response = $this->postJson('/webhooks/max', [
            'event' => 'message_created',
            'data' => ['body' => 'hello', 'chat' => ['id' => 123]],
        ], [
            'X-Max-Signature' => 'wrong-sig',
        ]);

        $response->assertStatus(403);
    }

    public function test_max_webhook_with_valid_signature_is_processed(): void
    {
        config()->offsetSet('services.max.secret_token', 'max-secret');

        $response = $this->postJson('/webhooks/max', [
            'event' => 'message_created',
            'data' => ['body' => 'random', 'chat' => ['id' => 123]],
        ], [
            'X-Max-Signature' => 'max-secret',
        ]);

        $response->assertOk();
    }

    // ═══════════════════════════════════════════
    // RATE LIMITING
    // ═══════════════════════════════════════════

    public function test_booking_rate_limit_blocks_after_5_requests(): void
    {
        $master = User::factory()->master()->create([
            'master_slug' => 'rate-test-master',
        ]);
        Service::factory()->create(['user_id' => $master->id]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/book/{$master->master_slug}", [
                'service_id' => $master->services->first()->id,
                'date' => now()->addDays(3)->toDateString(),
                'time' => '10:00',
                'provider' => 'telegram',
            ]);
        }

        $response = $this->postJson("/book/{$master->master_slug}", [
            'service_id' => $master->services->first()->id,
            'date' => now()->addDays(3)->toDateString(),
            'time' => '11:00',
            'provider' => 'telegram',
        ]);

        $response->assertStatus(429);
    }
}
