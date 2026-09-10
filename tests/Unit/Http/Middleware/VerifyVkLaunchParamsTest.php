<?php

namespace Tests\Unit\Http\Middleware;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class VerifyVkLaunchParamsTest extends TestCase
{
    use RefreshDatabase;

    private string $testAppSecret = 'wvl68m4dR1UpLrVRli';
    private string $testAppId = '6736218';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.vk.app_secret' => $this->testAppSecret]);
        config(['services.vk.app_id' => $this->testAppId]);

        Route::middleware('vk.launch')->get('/_test/vk-launch', fn () => response()->json([
            'userId' => request()->attributes->get('vk_launch')?->userId,
            'appId' => request()->attributes->get('vk_launch')?->appId,
        ]));
    }

    private function sign(array $vkParams): string
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

    private function buildBearerHeader(array $vkParams): string
    {
        $sign = $this->sign($vkParams);
        $all = array_merge($vkParams, ['sign' => $sign]);

        return 'Bearer ' . http_build_query($all);
    }

    // ═══════════════════════════════════════════
    // VALID REQUEST
    // ═══════════════════════════════════════════

    public function test_valid_bearer_sets_vk_launch_attribute(): void
    {
        $vkParams = [
            'vk_user_id' => '494075',
            'vk_app_id' => $this->testAppId,
            'vk_platform' => 'android',
        ];

        $response = $this->withHeaders([
            'Authorization' => $this->buildBearerHeader($vkParams),
        ])->getJson('/_test/vk-launch');

        $response->assertOk();
        $response->assertJson(['userId' => '494075', 'appId' => $this->testAppId]);
    }

    public function test_official_vkcom_vector_passes_through_middleware(): void
    {
        $query = 'vk_user_id=494075&vk_app_id=6736218&vk_is_app_user=1&vk_are_notifications_enabled=1&vk_language=ru&vk_access_token_settings=&vk_platform=android&sign=htQFduJpLxz7ribXRZpDFUH-XEUhC9rBPTJkjUFEkRA';

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $query,
        ])->getJson('/_test/vk-launch');

        $response->assertOk();
        $response->assertJson(['userId' => '494075']);
    }

    // ═══════════════════════════════════════════
    // INVALID SIGNATURE
    // ═══════════════════════════════════════════

    public function test_invalid_signature_returns_401(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer vk_user_id=12345&vk_app_id=' . $this->testAppId . '&sign=invalid_signature_here',
        ])->getJson('/_test/vk-launch');

        $response->assertStatus(401);
    }

    // ═══════════════════════════════════════════
    // MISSING / EMPTY AUTHORIZATION
    // ═══════════════════════════════════════════

    public function test_missing_authorization_returns_401(): void
    {
        $response = $this->getJson('/_test/vk-launch');

        $response->assertStatus(401);
    }

    public function test_empty_authorization_returns_401(): void
    {
        $response = $this->withHeaders([
            'Authorization' => '',
        ])->getJson('/_test/vk-launch');

        $response->assertStatus(401);
    }

    // ═══════════════════════════════════════════
    // WRONG SCHEME
    // ═══════════════════════════════════════════

    public function test_basic_scheme_returns_401(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Basic dXNlcjpwYXNz',
        ])->getJson('/_test/vk-launch');

        $response->assertStatus(401);
    }

    public function test_bearer_without_token_returns_401(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ',
        ])->getJson('/_test/vk-launch');

        $response->assertStatus(401);
    }

    // ═══════════════════════════════════════════
    // APP_ID MISMATCH
    // ═══════════════════════════════════════════

    public function test_app_id_mismatch_returns_401(): void
    {
        $vkParams = [
            'vk_user_id' => '12345',
            'vk_app_id' => '9999999',
        ];

        $response = $this->withHeaders([
            'Authorization' => $this->buildBearerHeader($vkParams),
        ])->getJson('/_test/vk-launch');

        $response->assertStatus(401);
    }

    // ═══════════════════════════════════════════
    // NO QUERY PARAM FALLBACK
    // ═══════════════════════════════════════════

    public function test_does_not_accept_query_parameter_fallback(): void
    {
        $vkParams = [
            'vk_user_id' => '12345',
            'vk_app_id' => $this->testAppId,
        ];

        $sign = $this->sign($vkParams);
        $query = http_build_query(array_merge($vkParams, ['sign' => $sign]));

        $response = $this->getJson('/_test/vk-launch?' . $query);

        $response->assertStatus(401);
    }
}
