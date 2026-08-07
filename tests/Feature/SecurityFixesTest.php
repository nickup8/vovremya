<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
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
        // Настоящий мастер-владелец записи
        $ownerMaster = User::factory()->master()->create([
            'role' => UserRole::Master,
        ]);
        // Чужой мастер
        $strangerMaster = User::factory()->master()->create([
            'role' => UserRole::Master,
        ]);

        $workspace = Workspace::create(['name' => 'WS Owner', 'owner_id' => $ownerMaster->id]);
        $catalog = ServiceCatalog::create(['workspace_id' => $workspace->id, 'title' => 'Стрижка', 'base_price' => 1000, 'base_duration' => 60]);
        $ms = MasterService::create(['master_id' => $ownerMaster->id, 'catalog_id' => $catalog->id, 'is_active' => true]);
        $appointment = Appointment::factory()->create([
            'master_id' => $ownerMaster->id,
            'master_service_id' => $ms->id,
            'status' => 'booked',
        ]);

        $response = $this->actingAs($strangerMaster)
            ->patchJson("/admin/appointments/{$appointment->id}/status", [
                'status' => 'paid',
            ]);

        // Доступ должен быть запрещён
        $response->assertForbidden(); // 403

        // ГЛАВНОЕ: статус в БД НЕ должен измениться
        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => 'booked',
        ]);
    }

    public function test_master_can_update_own_appointment(): void
    {
        $workspace = Workspace::create(['name' => 'WS Master', 'owner_id' => $this->master->id]);
        $catalog = ServiceCatalog::create(['workspace_id' => $workspace->id, 'title' => 'Маникюр', 'base_price' => 500, 'base_duration' => 30]);
        $ms = MasterService::create(['master_id' => $this->master->id, 'catalog_id' => $catalog->id, 'is_active' => true]);
        $appointment = Appointment::factory()->create([
            'master_id' => $this->master->id,
            'master_service_id' => $ms->id,
            'status' => 'booked',
        ]);

        $response = $this->actingAs($this->master)
            ->patchJson("/admin/appointments/{$appointment->id}/status", [
                'status' => 'paid',
            ]);

        $response->assertRedirect();
    }

    public function test_master_cannot_update_other_masters_client(): void
    {
        $ownerMaster = User::factory()->master()->create([
            'role' => UserRole::Master,
        ]);
        $strangerMaster = User::factory()->master()->create([
            'role' => UserRole::Master,
        ]);

        $client = Client::factory()->create([
            'user_id' => $ownerMaster->id,
            'name' => 'Original Name',
        ]);

        $response = $this->actingAs($strangerMaster)
            ->putJson("/admin/clients/{$client->id}", [
                'name' => 'Hacked Name',
                'phone' => $client->phone,
            ]);

        $response->assertForbidden(); // 403

        // Имя клиента НЕ должно измениться
        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'name' => 'Original Name',
        ]);
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
            'data' => ['body' => 'random', 'chat' => ['id' => 123]],
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
            'X-Max-Bot-Api-Secret' => 'wrong-sig',
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
            'X-Max-Bot-Api-Secret' => 'max-secret',
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
        $workspace = Workspace::create(['name' => 'WS Rate', 'owner_id' => $master->id]);
        $catalog = ServiceCatalog::create(['workspace_id' => $workspace->id, 'title' => 'Стрижка', 'base_price' => 1000, 'base_duration' => 60]);
        $ms = MasterService::create(['master_id' => $master->id, 'catalog_id' => $catalog->id, 'is_active' => true]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/book/{$master->master_slug}", [
                'service_id' => $ms->id,
                'date' => now()->addDays(3)->toDateString(),
                'time' => '10:00',
                'provider' => 'telegram',
            ]);
        }

        $response = $this->postJson("/book/{$master->master_slug}", [
            'service_id' => $ms->id,
            'date' => now()->addDays(3)->toDateString(),
            'time' => '11:00',
            'provider' => 'telegram',
        ]);

        $response->assertStatus(429);
    }
}
