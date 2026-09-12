<?php

namespace Tests\Feature\MiniApp;

use App\Constants\CacheKeys;
use App\Enums\AppointmentSource;
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

    private function setConsent(string $vkUserId): void
    {
        Cache::put(CacheKeys::VK_CONSENT_PENDING . $vkUserId, config('legal.version'), 900);
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
        $this->setConsent($vkUserId);

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
        $this->assertSame(AppointmentSource::Vk, $appointment->source);
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
        $this->setConsent($vkUserId);

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

        // Source not changed
        $appointment->refresh();
        $this->assertNull($appointment->source);
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
        $this->setConsent($vkUserId);

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

        // Source not changed
        $appointment->refresh();
        $this->assertNull($appointment->source);
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
        $this->setConsent($vkUserId);

        // First request succeeds
        $this->withHeaders($this->vkAuthHeaders($vkUserId))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $phone,
                'sign' => $this->signPhone($vkUserId, $phone),
            ])->assertOk();

        // Second request fails
        $this->setConsent($vkUserId);

        $this->withHeaders($this->vkAuthHeaders($vkUserId))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $phone,
                'sign' => $this->signPhone($vkUserId, $phone),
            ])->assertStatus(422)->assertJson(['error' => 'invalid_token']);

        // Source set on first request, unchanged on second
        $appointment->refresh();
        $this->assertSame(AppointmentSource::Vk, $appointment->source);
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
        $this->setConsent($vkUserId);

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

        // Source not changed
        $appointment->refresh();
        $this->assertNull($appointment->source);
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
        $this->setConsent($vkUserId);

        $response = $this->withHeaders($this->vkAuthHeaders($vkUserId))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $phone,
                'sign' => $this->signPhone($vkUserId, $phone),
            ]);

        $response->assertOk();
        $appointment->refresh();
        $this->assertSame($client->id, $appointment->client_id);
        $this->assertSame(AppointmentSource::Vk, $appointment->source);
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
        $this->setConsent($newVkId);

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

        // Source not changed
        $appointment->refresh();
        $this->assertNull($appointment->source);
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
        $this->setConsent($vkUserId);

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

        $appointment->refresh();
        $this->assertSame(AppointmentSource::Vk, $appointment->source);
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
        $this->setConsent($vkUserId);

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
        $this->assertSame(AppointmentSource::Vk, $appointment->source);
    }

    // ═══════════════════════════════════════
    // AppointmentSource::Vk enum
    // ═══════════════════════════════════════

    public function test_vk_source_enum_value(): void
    {
        $this->assertSame('vk', AppointmentSource::Vk->value);
    }

    public function test_vk_source_label(): void
    {
        $this->assertSame('VK', AppointmentSource::Vk->label());
    }

    // ═══════════════════════════════════════
    // consent enforcement
    // ═══════════════════════════════════════

    public function test_no_consent_returns_403(): void
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
                'sign' => $this->signPhone($vkUserId, $phone),
            ]);

        $response->assertStatus(403);
        $response->assertJson(['error' => 'pdn_consent_required']);

        // Token NOT consumed
        $this->assertNotNull(Cache::get(CacheKeys::VK_LINK_TOKEN . $token));

        // Appointment/client unchanged
        $appointment->refresh();
        $this->assertNull($appointment->source);
        $this->assertNull($appointment->client_id);
    }

    public function test_no_consent_does_not_consume_token(): void
    {
        $vkUserId = '494075';
        $phone = '79001234567';
        $master = User::factory()->master()->create();
        $appointment = Appointment::factory()
            ->forMaster($master)
            ->create(['status' => AppointmentStatus::Booked, 'client_id' => null]);
        $token = $this->createToken($appointment->id);

        $this->withHeaders($this->vkAuthHeaders($vkUserId))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $phone,
                'sign' => $this->signPhone($vkUserId, $phone),
            ])->assertStatus(403);

        // Token still available for retry
        $this->assertNotNull(Cache::get(CacheKeys::VK_LINK_TOKEN . $token));
    }

    public function test_no_consent_client_and_appointment_unchanged(): void
    {
        $vkUserId = '494075';
        $phone = '79001234567';
        $master = User::factory()->master()->create();
        $appointment = Appointment::factory()
            ->forMaster($master)
            ->create(['status' => AppointmentStatus::Booked, 'client_id' => null]);
        $token = $this->createToken($appointment->id);

        $this->withHeaders($this->vkAuthHeaders($vkUserId))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $phone,
                'sign' => $this->signPhone($vkUserId, $phone),
            ])->assertStatus(403);

        $appointment->refresh();
        $this->assertNull($appointment->client_id);
        $this->assertNull($appointment->source);

        $this->assertDatabaseCount('clients', 0);
    }

    public function test_valid_consent_writes_pdn_fields_on_new_client(): void
    {
        $vkUserId = '494075';
        $phone = '79001234567';
        $master = User::factory()->master()->create();
        $appointment = Appointment::factory()
            ->forMaster($master)
            ->create(['status' => AppointmentStatus::Booked, 'client_id' => null]);
        $token = $this->createToken($appointment->id);
        $this->setConsent($vkUserId);

        $this->withHeaders($this->vkAuthHeaders($vkUserId))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $phone,
                'sign' => $this->signPhone($vkUserId, $phone),
            ])->assertOk();

        $client = $appointment->fresh()->client;
        $this->assertNotNull($client->pdn_consent_at);
        $this->assertSame('11.08.2026', $client->pdn_consent_version);
    }

    public function test_existing_client_consent_updated_when_version_differs(): void
    {
        $vkUserId = '494075';
        $phone = '79001234567';
        $master = User::factory()->master()->create();
        $client = Client::factory()->create([
            'user_id' => $master->id,
            'phone' => $phone,
            'vk_id' => null,
            'pdn_consent_at' => now()->subDay(),
            'pdn_consent_version' => '01.01.2020',
        ]);
        $appointment = Appointment::factory()
            ->forMaster($master)
            ->create(['status' => AppointmentStatus::Booked, 'client_id' => null]);
        $token = $this->createToken($appointment->id);
        $this->setConsent($vkUserId);

        $this->withHeaders($this->vkAuthHeaders($vkUserId))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $phone,
                'sign' => $this->signPhone($vkUserId, $phone),
            ])->assertOk();

        $client->refresh();
        $this->assertSame('11.08.2026', $client->pdn_consent_version);
    }

    public function test_existing_client_consent_not_overwritten_when_current(): void
    {
        $vkUserId = '494075';
        $phone = '79001234567';
        $master = User::factory()->master()->create();
        $originalConsentAt = now()->subHour();
        $client = Client::factory()->create([
            'user_id' => $master->id,
            'phone' => $phone,
            'vk_id' => null,
            'pdn_consent_at' => $originalConsentAt,
            'pdn_consent_version' => '11.08.2026',
        ]);
        $appointment = Appointment::factory()
            ->forMaster($master)
            ->create(['status' => AppointmentStatus::Booked, 'client_id' => null]);
        $token = $this->createToken($appointment->id);
        $this->setConsent($vkUserId);

        $this->withHeaders($this->vkAuthHeaders($vkUserId))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $phone,
                'sign' => $this->signPhone($vkUserId, $phone),
            ])->assertOk();

        $client->refresh();
        $this->assertSame($originalConsentAt->timestamp, $client->pdn_consent_at->timestamp);
        $this->assertSame('11.08.2026', $client->pdn_consent_version);
    }

    public function test_successful_link_removes_pending_consent_cache(): void
    {
        $vkUserId = '494075';
        $phone = '79001234567';
        $master = User::factory()->master()->create();
        $appointment = Appointment::factory()
            ->forMaster($master)
            ->create(['status' => AppointmentStatus::Booked, 'client_id' => null]);
        $token = $this->createToken($appointment->id);
        $this->setConsent($vkUserId);

        $this->assertNotNull(Cache::get(CacheKeys::VK_CONSENT_PENDING . $vkUserId));

        $this->withHeaders($this->vkAuthHeaders($vkUserId))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $phone,
                'sign' => $this->signPhone($vkUserId, $phone),
            ])->assertOk();

        $this->assertNull(Cache::get(CacheKeys::VK_CONSENT_PENDING . $vkUserId));
    }

    public function test_failed_phone_proof_does_not_remove_consent_cache(): void
    {
        $vkUserId = '494075';
        $phone = '79001234567';
        $master = User::factory()->master()->create();
        $appointment = Appointment::factory()
            ->forMaster($master)
            ->create(['status' => AppointmentStatus::Booked, 'client_id' => null]);
        $token = $this->createToken($appointment->id);
        $this->setConsent($vkUserId);

        $this->withHeaders($this->vkAuthHeaders($vkUserId))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $phone,
                'sign' => 'invalid_sign',
            ])->assertStatus(422);

        // Consent cache still present for retry
        $this->assertNotNull(Cache::get(CacheKeys::VK_CONSENT_PENDING . $vkUserId));
    }

    public function test_failed_token_does_not_remove_consent_cache(): void
    {
        $vkUserId = '494075';
        $phone = '79001234567';
        $master = User::factory()->master()->create();
        $appointment = Appointment::factory()
            ->forMaster($master)
            ->create(['status' => AppointmentStatus::Booked, 'client_id' => null]);
        $token = $this->createToken($appointment->id);
        $this->setConsent($vkUserId);

        // Expire the token
        Cache::forget(CacheKeys::VK_LINK_TOKEN . $token);

        $this->withHeaders($this->vkAuthHeaders($vkUserId))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $phone,
                'sign' => $this->signPhone($vkUserId, $phone),
            ])->assertStatus(422);

        // Consent cache still present for retry
        $this->assertNotNull(Cache::get(CacheKeys::VK_CONSENT_PENDING . $vkUserId));
    }
}
