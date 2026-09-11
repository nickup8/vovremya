<?php

namespace Tests\Feature\MiniApp;

use App\Constants\CacheKeys;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class VkLinkControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $testAppId = '6736218';
    private string $testAppSecret = 'wvl68m4dR1UpLrVRli';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.vk.app_id' => $this->testAppId]);
        config(['services.vk.app_secret' => $this->testAppSecret]);
    }

    private function signPhone(string $userId, string $phone): string
    {
        $raw = hash('sha256', $this->testAppId . $this->testAppSecret . $userId . 'phone_number' . $phone, true);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function vkAuthHeaders(string $vkUserId): array
    {
        $vkParams = [
            'vk_user_id' => $vkUserId,
            'vk_app_id' => $this->testAppId,
            'vk_platform' => 'android',
        ];
        ksort($vkParams);
        $parts = [];
        foreach ($vkParams as $key => $value) {
            $parts[] = $key . '=' . $value;
        }
        $canonical = implode('&', $parts);
        $hmac = hash_hmac('sha256', $canonical, $this->testAppSecret, true);
        $sign = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($hmac));
        $all = array_merge($vkParams, ['sign' => $sign]);

        return ['Authorization' => 'Bearer ' . http_build_query($all)];
    }

    private function createToken(string $appointmentId): string
    {
        $token = 'link_vk_test_' . uniqid();
        Cache::put(CacheKeys::VK_LINK_TOKEN . $token, $appointmentId, 900);

        return $token;
    }

    // ═══════════════════════════════════════
    // valid flow
    // ═══════════════════════════════════════

    public function test_valid_flow_links_vk_id_and_appointment(): void
    {
        $vkUserId = '494075';
        $phone = '79001234567';
        $master = User::factory()->master()->create();
        $appointment = Appointment::factory()
            ->forMaster($master)
            ->create([
                'status' => AppointmentStatus::Booked,
                'client_id' => null,
            ]);
        $token = $this->createToken($appointment->id);

        $response = $this->withHeaders($this->vkAuthHeaders($vkUserId))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $phone,
                'sign' => $this->signPhone($vkUserId, $phone),
            ]);

        $response->assertOk();
        $response->assertJson(['ok' => true]);

        $appointment->refresh();
        $this->assertNotNull($appointment->client_id);
        $this->assertSame($vkUserId, $appointment->client->vk_id);
    }

    // ═══════════════════════════════════════
    // invalid phone sign
    // ═══════════════════════════════════════

    public function test_invalid_phone_sign_does_not_consume_token(): void
    {
        $vkUserId = '494075';
        $phone = '79001234567';
        $master = User::factory()->master()->create();
        $appointment = Appointment::factory()
            ->forMaster($master)
            ->create(['status' => AppointmentStatus::Booked, 'client_id' => null]);
        $token = $this->createToken($appointment->id);

        $response = $this->withHeaders($this->vkAuthHeaders($vkUserId))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $phone,
                'sign' => 'invalid_sign',
            ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'invalid_phone_sign']);

        // Token still usable
        $this->assertNotNull(Cache::get(CacheKeys::VK_LINK_TOKEN . $token));
    }

    // ═══════════════════════════════════════
    // invalid/expired token
    // ═══════════════════════════════════════

    public function test_expired_token_returns_422(): void
    {
        $vkUserId = '494075';
        $phone = '79001234567';
        $master = User::factory()->master()->create();
        $appointment = Appointment::factory()
            ->forMaster($master)
            ->create(['status' => AppointmentStatus::Booked, 'client_id' => null]);
        $token = $this->createToken($appointment->id);

        // Simulate expiry
        Cache::forget(CacheKeys::VK_LINK_TOKEN . $token);

        $response = $this->withHeaders($this->vkAuthHeaders($vkUserId))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $phone,
                'sign' => $this->signPhone($vkUserId, $phone),
            ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'invalid_token']);
    }

    // ═══════════════════════════════════════
    // reused token
    // ═══════════════════════════════════════

    public function test_reused_token_returns_422(): void
    {
        $vkUserId = '494075';
        $phone = '79001234567';
        $master = User::factory()->master()->create();
        $appointment = Appointment::factory()
            ->forMaster($master)
            ->create(['status' => AppointmentStatus::Booked, 'client_id' => null]);
        $token = $this->createToken($appointment->id);

        // First request succeeds
        $this->withHeaders($this->vkAuthHeaders($vkUserId))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $phone,
                'sign' => $this->signPhone($vkUserId, $phone),
            ])->assertOk();

        // Second request fails
        $this->withHeaders($this->vkAuthHeaders($vkUserId))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $phone,
                'sign' => $this->signPhone($vkUserId, $phone),
            ])->assertStatus(422)->assertJson(['error' => 'invalid_token']);
    }

    // ═══════════════════════════════════════
    // race: consume between peek and final consume
    // ═══════════════════════════════════════

    public function test_race_consume_between_peek_and_final_consume_fails(): void
    {
        $vkUserId = '494075';
        $phone = '79001234567';
        $master = User::factory()->master()->create();
        $appointment = Appointment::factory()
            ->forMaster($master)
            ->create(['status' => AppointmentStatus::Booked, 'client_id' => null]);
        $token = 'link_vk_race_token';

        // Mock: peek succeeds but consume returns null (simulates race lost)
        $this->mock(\App\Services\VkLinkTokenService::class, function ($mock) use ($appointment, $token) {
            $mock->shouldReceive('peek')->with($token)->andReturn($appointment->id);
            $mock->shouldReceive('consume')->with($token)->andReturn(null);
        });

        $response = $this->withHeaders($this->vkAuthHeaders($vkUserId))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $phone,
                'sign' => $this->signPhone($vkUserId, $phone),
            ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'token_consumed']);
    }

    // ═══════════════════════════════════════
    // same vk_id idempotent
    // ═══════════════════════════════════════

    public function test_same_vk_id_is_idempotent(): void
    {
        $vkUserId = '494075';
        $phone = '79001234567';
        $master = User::factory()->master()->create();
        $client = Client::factory()->create([
            'user_id' => $master->id,
            'phone' => $phone,
            'vk_id' => $vkUserId,
        ]);
        $appointment = Appointment::factory()
            ->forMaster($master)
            ->create(['status' => AppointmentStatus::Booked, 'client_id' => null]);
        $token = $this->createToken($appointment->id);

        $response = $this->withHeaders($this->vkAuthHeaders($vkUserId))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $phone,
                'sign' => $this->signPhone($vkUserId, $phone),
            ]);

        $response->assertOk();
        $appointment->refresh();
        $this->assertSame($client->id, $appointment->client_id);
    }

    // ═══════════════════════════════════════
    // different vk_id → 409, token not consumed
    // ═══════════════════════════════════════

    public function test_different_vk_id_returns_409_token_not_consumed(): void
    {
        $existingVkId = '111111';
        $newVkId = '222222';
        $phone = '79001234567';
        $master = User::factory()->master()->create();
        Client::factory()->create([
            'user_id' => $master->id,
            'phone' => $phone,
            'vk_id' => $existingVkId,
        ]);
        $appointment = Appointment::factory()
            ->forMaster($master)
            ->create(['status' => AppointmentStatus::Booked, 'client_id' => null]);
        $token = $this->createToken($appointment->id);

        $response = $this->withHeaders($this->vkAuthHeaders($newVkId))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $phone,
                'sign' => $this->signPhone($newVkId, $phone),
            ]);

        $response->assertStatus(409);
        $response->assertJson(['error' => 'client_already_linked']);

        // Token is NOT consumed (available for retry with correct vk_id)
        $this->assertNotNull(Cache::get(CacheKeys::VK_LINK_TOKEN . $token));
    }

    // ═══════════════════════════════════════
    // preserves max_id / telegram_id
    // ═══════════════════════════════════════

    public function test_preserves_existing_max_id_and_telegram_id(): void
    {
        $vkUserId = '494075';
        $phone = '79001234567';
        $master = User::factory()->master()->create();
        $client = Client::factory()->create([
            'user_id' => $master->id,
            'phone' => $phone,
            'telegram_id' => 'tg_123456',
            'max_id' => 'max_789',
            'vk_id' => null,
        ]);
        $appointment = Appointment::factory()
            ->forMaster($master)
            ->create(['status' => AppointmentStatus::Booked, 'client_id' => null]);
        $token = $this->createToken($appointment->id);

        $this->withHeaders($this->vkAuthHeaders($vkUserId))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $phone,
                'sign' => $this->signPhone($vkUserId, $phone),
            ])->assertOk();

        $client->refresh();
        $this->assertSame($vkUserId, $client->vk_id);
        $this->assertSame('tg_123456', $client->telegram_id);
        $this->assertSame('max_789', $client->max_id);
    }

    // ═══════════════════════════════════════
    // MAX auth → 401
    // ═══════════════════════════════════════

    public function test_max_auth_returns_401(): void
    {
        $response = $this->withHeaders(['X-Max-Init-Data' => 'test_init_data'])
            ->postJson('/api/miniapp/link', [
                'token' => 'link_vk_test_token',
                'phone_number' => '79001234567',
                'sign' => 'whatever',
            ]);

        $response->assertStatus(401);
    }

    // ═══════════════════════════════════════
    // no auth → 401
    // ═══════════════════════════════════════

    public function test_no_auth_returns_401(): void
    {
        $response = $this->postJson('/api/miniapp/link', [
            'token' => 'link_vk_test_token',
            'phone_number' => '79001234567',
            'sign' => 'whatever',
        ]);

        $response->assertStatus(401);
    }

    // ═══════════════════════════════════════
    // multi-master isolation
    // ═══════════════════════════════════════

    public function test_multi_master_isolation(): void
    {
        $vkUserId = '494075';
        $phone = '79001234567';
        $master1 = User::factory()->master()->create();
        $master2 = User::factory()->master()->create();

        $client1 = Client::factory()->create([
            'user_id' => $master1->id,
            'phone' => $phone,
            'vk_id' => null,
        ]);

        $appointment = Appointment::factory()
            ->forMaster($master2)
            ->create(['status' => AppointmentStatus::Booked, 'client_id' => null]);
        $token = $this->createToken($appointment->id);

        $this->withHeaders($this->vkAuthHeaders($vkUserId))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $phone,
                'sign' => $this->signPhone($vkUserId, $phone),
            ])->assertOk();

        // Client under master1 untouched
        $client1->refresh();
        $this->assertNull($client1->vk_id);

        // New client created under master2
        $appointment->refresh();
        $this->assertNotNull($appointment->client_id);
        $this->assertNotSame($client1->id, $appointment->client_id);
        $this->assertSame($vkUserId, $appointment->client->vk_id);
    }
}
