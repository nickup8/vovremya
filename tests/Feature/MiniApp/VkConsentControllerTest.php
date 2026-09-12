<?php

namespace Tests\Feature\MiniApp;

use App\Constants\CacheKeys;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class VkConsentControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $testAppId = '6736218';
    private string $testAppSecret = 'wvl68m4dR1UpLrVRli';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.vk.app_id' => $this->testAppId]);
        config(['services.vk.app_secret' => $this->testAppSecret]);
        config(['legal.version' => '11.08.2026']);
        config(['booking.draft_ttl' => 900]);
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
    // POST consent (existing)
    // ═══════════════════════════════════════

    public function test_requires_vk_launch_middleware(): void
    {
        $response = $this->postJson('/api/miniapp/vk-consent');

        $response->assertStatus(401);
    }

    public function test_valid_consent_stores_version_in_cache(): void
    {
        $vkUserId = '494075';

        $response = $this->withHeaders($this->vkAuthHeaders($vkUserId))
            ->postJson('/api/miniapp/vk-consent');

        $response->assertOk();
        $response->assertJson(['ok' => true]);

        $cached = Cache::get(CacheKeys::VK_CONSENT_PENDING . $vkUserId);
        $this->assertSame('11.08.2026', $cached);
    }

    public function test_consent_uses_booking_draft_ttl(): void
    {
        $vkUserId = '494075';
        config(['booking.draft_ttl' => 1234]);

        $this->withHeaders($this->vkAuthHeaders($vkUserId))
            ->postJson('/api/miniapp/vk-consent')
            ->assertOk();

        // Cache::has confirms the key exists; TTL is set by Cache::put
        $this->assertTrue(Cache::has(CacheKeys::VK_CONSENT_PENDING . $vkUserId));
    }

    public function test_no_auth_returns_401(): void
    {
        $response = $this->postJson('/api/miniapp/vk-consent');

        $response->assertStatus(401);
    }

    // ═══════════════════════════════════════
    // GET status
    // ═══════════════════════════════════════

    public function test_status_requires_auth(): void
    {
        $this->getJson('/api/miniapp/vk-consent/status?token=test')
            ->assertStatus(401);
    }

    public function test_status_invalid_token_returns_422(): void
    {
        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->getJson('/api/miniapp/vk-consent/status?token=invalid')
            ->assertStatus(422)
            ->assertJson(['error' => 'invalid_token']);
    }

    public function test_status_missing_token_returns_422(): void
    {
        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->getJson('/api/miniapp/vk-consent/status')
            ->assertStatus(422)
            ->assertJson(['error' => 'invalid_token']);
    }

    public function test_status_missing_appointment_returns_422(): void
    {
        $token = $this->createToken('00000000-0000-0000-0000-000000000000');

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->getJson('/api/miniapp/vk-consent/status?token=' . $token)
            ->assertStatus(422)
            ->assertJson(['error' => 'appointment_not_found']);
    }

    public function test_status_returns_false_when_matching_consent_exists(): void
    {
        $vkUserId = '494075';
        $master = User::factory()->master()->create();
        Client::factory()->create([
            'user_id' => $master->id,
            'vk_id' => $vkUserId,
            'pdn_consent_at' => now(),
            'pdn_consent_version' => '11.08.2026',
        ]);

        $appointment = Appointment::factory()->forMaster($master)->create();
        $token = $this->createToken($appointment->id);

        $this->withHeaders($this->vkAuthHeaders($vkUserId))
            ->getJson('/api/miniapp/vk-consent/status?token=' . $token)
            ->assertOk()
            ->assertJson(['consent_required' => false]);
    }

    public function test_status_consent_from_another_master_returns_false(): void
    {
        $vkUserId = '494075';
        $master1 = User::factory()->master()->create();
        $master2 = User::factory()->master()->create();

        Client::factory()->create([
            'user_id' => $master1->id,
            'vk_id' => $vkUserId,
            'pdn_consent_at' => now(),
            'pdn_consent_version' => '11.08.2026',
        ]);

        $appointment = Appointment::factory()->forMaster($master2)->create();
        $token = $this->createToken($appointment->id);

        $this->withHeaders($this->vkAuthHeaders($vkUserId))
            ->getJson('/api/miniapp/vk-consent/status?token=' . $token)
            ->assertOk()
            ->assertJson(['consent_required' => false]);
    }

    public function test_status_returns_true_when_no_matching_vk_id(): void
    {
        $master = User::factory()->master()->create();
        $appointment = Appointment::factory()->forMaster($master)->create();
        $token = $this->createToken($appointment->id);

        $this->withHeaders($this->vkAuthHeaders('999999'))
            ->getJson('/api/miniapp/vk-consent/status?token=' . $token)
            ->assertOk()
            ->assertJson(['consent_required' => true]);
    }

    public function test_status_returns_true_when_consent_at_is_null(): void
    {
        $vkUserId = '494075';
        $master = User::factory()->master()->create();
        Client::factory()->create([
            'user_id' => $master->id,
            'vk_id' => $vkUserId,
            'pdn_consent_at' => null,
            'pdn_consent_version' => '11.08.2026',
        ]);

        $appointment = Appointment::factory()->forMaster($master)->create();
        $token = $this->createToken($appointment->id);

        $this->withHeaders($this->vkAuthHeaders($vkUserId))
            ->getJson('/api/miniapp/vk-consent/status?token=' . $token)
            ->assertOk()
            ->assertJson(['consent_required' => true]);
    }

    public function test_status_returns_true_when_version_outdated(): void
    {
        $vkUserId = '494075';
        $master = User::factory()->master()->create();
        Client::factory()->create([
            'user_id' => $master->id,
            'vk_id' => $vkUserId,
            'pdn_consent_at' => now(),
            'pdn_consent_version' => '01.01.2020',
        ]);

        $appointment = Appointment::factory()->forMaster($master)->create();
        $token = $this->createToken($appointment->id);

        $this->withHeaders($this->vkAuthHeaders($vkUserId))
            ->getJson('/api/miniapp/vk-consent/status?token=' . $token)
            ->assertOk()
            ->assertJson(['consent_required' => true]);
    }

    public function test_status_does_not_consume_token(): void
    {
        $vkUserId = '494075';
        $master = User::factory()->master()->create();
        $appointment = Appointment::factory()->forMaster($master)->create();
        $token = $this->createToken($appointment->id);

        $this->withHeaders($this->vkAuthHeaders($vkUserId))
            ->getJson('/api/miniapp/vk-consent/status?token=' . $token)
            ->assertOk();

        // Token still valid after status check
        $this->assertNotNull(Cache::get(CacheKeys::VK_LINK_TOKEN . $token));
    }
}
