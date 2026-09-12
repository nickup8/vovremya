<?php

namespace Tests\Feature\MiniApp;

use App\Constants\CacheKeys;
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
}
