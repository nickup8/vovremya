<?php

namespace Tests\Unit\Http\Middleware;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class VerifyMiniAppAuthTest extends TestCase
{
    use RefreshDatabase;

    private string $testBotToken = 'test-bot-token-123';
    private string $testAppSecret = 'wvl68m4dR1UpLrVRli';
    private string $testAppId = '6736218';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.max.bot_token' => $this->testBotToken]);
        config(['booking.initdata_ttl' => 3600]);
        config(['services.vk.app_secret' => $this->testAppSecret]);
        config(['services.vk.app_id' => $this->testAppId]);

        Route::middleware('miniapp.auth')->get('/_test/miniapp-auth', fn () => response()->json([
            'maxUserId' => request()->attributes->get('max_init')?->userId,
            'vkUserId' => request()->attributes->get('vk_launch')?->userId,
            'hasMax' => request()->attributes->has('max_init'),
            'hasVk' => request()->attributes->has('vk_launch'),
        ]));
    }

    // ═══════════════════════════════════════════
    // MAX HELPERS
    // ═══════════════════════════════════════════

    private function generateMaxInitData(string $userId = '8039166'): string
    {
        $params = [
            'auth_date' => (string) time(),
            'user' => json_encode(['id' => (int) $userId, 'first_name' => 'Test']),
        ];
        ksort($params);

        $pairsForSign = [];
        foreach ($params as $key => $value) {
            $pairsForSign[] = $key . '=' . $value;
        }
        $launchParams = implode("\n", $pairsForSign);
        $secretKey = hash_hmac('sha256', $this->testBotToken, 'WebAppData', true);
        $hash = hash_hmac('sha256', $launchParams, $secretKey, false);

        $pairsFinal = [];
        foreach ($params as $key => $value) {
            $pairsFinal[] = $key . '=' . urlencode($value);
        }
        $pairsFinal[] = 'hash=' . $hash;

        return implode('&', $pairsFinal);
    }

    // ═══════════════════════════════════════════
    // VK HELPERS
    // ═══════════════════════════════════════════

    private function signVk(array $vkParams): string
    {
        ksort($vkParams);
        $parts = [];
        foreach ($vkParams as $key => $value) {
            $parts[] = $key . '=' . $value;
        }
        $canonical = implode('&', $parts);
        $hmac = hash_hmac('sha256', $canonical, $this->testAppSecret, true);

        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($hmac));
    }

    private function buildVkBearer(array $vkParams): string
    {
        $sign = $this->signVk($vkParams);
        $all = array_merge($vkParams, ['sign' => $sign]);

        return 'Bearer ' . http_build_query($all);
    }

    // ═══════════════════════════════════════════
    // 1. VALID MAX HEADER
    // ═══════════════════════════════════════════

    public function test_valid_max_header_sets_max_init(): void
    {
        $response = $this->withHeaders([
            'X-Max-Init-Data' => $this->generateMaxInitData('12345'),
        ])->getJson('/_test/miniapp-auth');

        $response->assertOk();
        $response->assertJson(['maxUserId' => '12345', 'hasMax' => true, 'hasVk' => false]);
    }

    // ═══════════════════════════════════════════
    // 2. VALID MAX QUERY FALLBACK
    // ═══════════════════════════════════════════

    public function test_valid_max_query_fallback_sets_max_init(): void
    {
        $response = $this->getJson('/_test/miniapp-auth?init_data=' . urlencode($this->generateMaxInitData('67890')));

        $response->assertOk();
        $response->assertJson(['maxUserId' => '67890', 'hasMax' => true, 'hasVk' => false]);
    }

    // ═══════════════════════════════════════════
    // 3. VALID VK BEARER
    // ═══════════════════════════════════════════

    public function test_valid_vk_bearer_sets_vk_launch(): void
    {
        $vkParams = [
            'vk_user_id' => '494075',
            'vk_app_id' => $this->testAppId,
            'vk_platform' => 'android',
        ];

        $response = $this->withHeaders([
            'Authorization' => $this->buildVkBearer($vkParams),
        ])->getJson('/_test/miniapp-auth');

        $response->assertOk();
        $response->assertJson(['vkUserId' => '494075', 'hasMax' => false, 'hasVk' => true]);
    }

    // ═══════════════════════════════════════════
    // 4. NO CREDENTIALS
    // ═══════════════════════════════════════════

    public function test_no_credentials_returns_401(): void
    {
        $response = $this->getJson('/_test/miniapp-auth');

        $response->assertStatus(401);
    }

    // ═══════════════════════════════════════════
    // 5. INVALID MAX — NO VK FALLBACK
    // ═══════════════════════════════════════════

    public function test_invalid_max_returns_401_no_vk_fallback(): void
    {
        $response = $this->withHeaders([
            'X-Max-Init-Data' => 'invalid_garbage_data',
        ])->getJson('/_test/miniapp-auth');

        $response->assertStatus(401);
    }

    // ═══════════════════════════════════════════
    // 6. INVALID VK SIGNATURE — NO MAX FALLBACK
    // ═══════════════════════════════════════════

    public function test_invalid_vk_returns_401_no_max_fallback(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer vk_user_id=12345&vk_app_id=' . $this->testAppId . '&sign=bad',
        ])->getJson('/_test/miniapp-auth');

        $response->assertStatus(401);
    }

    // ═══════════════════════════════════════════
    // 7. BASIC SCHEME → VK MALFORMED
    // ═══════════════════════════════════════════

    public function test_basic_scheme_returns_401(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Basic dXNlcjpwYXNz',
        ])->getJson('/_test/miniapp-auth');

        $response->assertStatus(401);
    }

    // ═══════════════════════════════════════════
    // 8. BEARER WITHOUT TOKEN
    // ═══════════════════════════════════════════

    public function test_bearer_without_token_returns_401(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ',
        ])->getJson('/_test/miniapp-auth');

        $response->assertStatus(401);
    }

    // ═══════════════════════════════════════════
    // 9. EMPTY AUTHORIZATION — VK MALFORMED, NOT MISSING
    // ═══════════════════════════════════════════

    public function test_empty_authorization_is_vk_malformed_not_missing(): void
    {
        // Empty Authorization header present → VK channel detected → malformed → 401
        $response = $this->withHeaders([
            'Authorization' => '',
        ])->getJson('/_test/miniapp-auth');

        $response->assertStatus(401);
    }

    // ═══════════════════════════════════════════
    // 10. BOTH VALID → AMBIGUOUS
    // ═══════════════════════════════════════════

    public function test_both_valid_credentials_returns_401_ambiguous(): void
    {
        $vkParams = [
            'vk_user_id' => '494075',
            'vk_app_id' => $this->testAppId,
        ];

        $response = $this->withHeaders([
            'X-Max-Init-Data' => $this->generateMaxInitData('12345'),
            'Authorization' => $this->buildVkBearer($vkParams),
        ])->getJson('/_test/miniapp-auth');

        $response->assertStatus(401);
    }

    // ═══════════════════════════════════════════
    // 11. VALID MAX + MALFORMED VK → AMBIGUOUS
    // ═══════════════════════════════════════════

    public function test_valid_max_plus_malformed_auth_returns_401_ambiguous(): void
    {
        $response = $this->withHeaders([
            'X-Max-Init-Data' => $this->generateMaxInitData('12345'),
            'Authorization' => 'Basic garbage',
        ])->getJson('/_test/miniapp-auth');

        $response->assertStatus(401);
    }

    // ═══════════════════════════════════════════
    // 12. INVALID MAX + VALID VK → AMBIGUOUS
    // ═══════════════════════════════════════════

    public function test_invalid_max_plus_valid_vk_returns_401_ambiguous(): void
    {
        $vkParams = [
            'vk_user_id' => '494075',
            'vk_app_id' => $this->testAppId,
        ];

        $response = $this->withHeaders([
            'X-Max-Init-Data' => 'invalid_max_data',
            'Authorization' => $this->buildVkBearer($vkParams),
        ])->getJson('/_test/miniapp-auth');

        $response->assertStatus(401);
    }
}
