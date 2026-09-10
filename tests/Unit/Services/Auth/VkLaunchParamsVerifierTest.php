<?php

namespace Tests\Unit\Services\Auth;

use App\Services\Auth\VkLaunchParamsVerifier;
use Tests\TestCase;

class VkLaunchParamsVerifierTest extends TestCase
{
    private string $testAppSecret = 'wvl68m4dR1UpLrVRli';
    private string $testAppId = '6736218';

    private VkLaunchParamsVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.vk.app_secret' => $this->testAppSecret]);
        config(['services.vk.app_id' => $this->testAppId]);
        $this->verifier = new VkLaunchParamsVerifier();
    }

    /**
     * Подписывает VK launch params (production signing algorithm).
     */
    private function sign(array $vkParams, ?string $secret = null): string
    {
        $secret = $secret ?? $this->testAppSecret;
        ksort($vkParams);

        $parts = [];
        foreach ($vkParams as $key => $value) {
            $parts[] = $key . '=' . $value;
        }

        $canonical = implode('&', $parts);
        $hmac = hash_hmac('sha256', $canonical, $secret, true);

        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($hmac));
    }

    private function buildQuery(array $vkParams, ?string $sign = null, array $extraParams = []): string
    {
        $sign = $sign ?? $this->sign($vkParams);
        $all = array_merge($vkParams, $extraParams, ['sign' => $sign]);

        return http_build_query($all);
    }

    // ═══════════════════════════════════════════
    // ОФИЦИАЛЬНЫЙ VKCOM REFERENCE VECTOR
    // ═══════════════════════════════════════════

    public function test_official_vkcom_reference_vector_passes(): void
    {
        // Публичный тест-вектор из документации VK
        $query = 'vk_user_id=494075&vk_app_id=6736218&vk_is_app_user=1&vk_are_notifications_enabled=1&vk_language=ru&vk_access_token_settings=&vk_platform=android&sign=htQFduJpLxz7ribXRZpDFUH-XEUhC9rBPTJkjUFEkRA';

        $result = $this->verifier->verify($query);

        $this->assertNotNull($result);
        $this->assertEquals('494075', $result->userId);
        $this->assertEquals('6736218', $result->appId);
    }

    // ═══════════════════════════════════════════
    // ОСНОВНЫЕ СЛУЧАИ
    // ═══════════════════════════════════════════

    public function test_valid_payload_returns_correct_user_id(): void
    {
        $vkParams = [
            'vk_user_id' => '12345',
            'vk_app_id' => $this->testAppId,
            'vk_platform' => 'web',
        ];

        $result = $this->verifier->verify($this->buildQuery($vkParams));

        $this->assertNotNull($result);
        $this->assertEquals('12345', $result->userId);
        $this->assertEquals($this->testAppId, $result->appId);
    }

    public function test_leading_question_mark_is_stripped(): void
    {
        $vkParams = [
            'vk_user_id' => '12345',
            'vk_app_id' => $this->testAppId,
        ];

        $result = $this->verifier->verify('?' . $this->buildQuery($vkParams));

        $this->assertNotNull($result);
        $this->assertEquals('12345', $result->userId);
    }

    public function test_vk_ts_parsed_when_present(): void
    {
        $vkParams = [
            'vk_user_id' => '12345',
            'vk_app_id' => $this->testAppId,
            'vk_ts' => '1700000000',
        ];

        $result = $this->verifier->verify($this->buildQuery($vkParams));

        $this->assertNotNull($result);
        $this->assertEquals(1700000000, $result->timestamp);
    }

    public function test_vk_ts_null_when_absent(): void
    {
        $vkParams = [
            'vk_user_id' => '12345',
            'vk_app_id' => $this->testAppId,
        ];

        $result = $this->verifier->verify($this->buildQuery($vkParams));

        $this->assertNotNull($result);
        $this->assertNull($result->timestamp);
    }

    // ═══════════════════════════════════════════
    // TAMPER DETECTION
    // ═══════════════════════════════════════════

    public function test_tampered_user_id_fails(): void
    {
        $vkParams = [
            'vk_user_id' => '12345',
            'vk_app_id' => $this->testAppId,
        ];

        $sign = $this->sign($vkParams);

        // Tamper user_id after signing
        $query = 'vk_user_id=99999&vk_app_id=' . $this->testAppId . '&sign=' . $sign;

        $this->assertNull($this->verifier->verify($query));
    }

    public function test_tampered_other_signed_param_fails(): void
    {
        $vkParams = [
            'vk_user_id' => '12345',
            'vk_app_id' => $this->testAppId,
            'vk_platform' => 'web',
        ];

        $sign = $this->sign($vkParams);

        // Tamper vk_platform after signing
        $query = 'vk_user_id=12345&vk_app_id=' . $this->testAppId . '&vk_platform=android&sign=' . $sign;

        $this->assertNull($this->verifier->verify($query));
    }

    public function test_wrong_app_secret_fails(): void
    {
        $vkParams = [
            'vk_user_id' => '12345',
            'vk_app_id' => $this->testAppId,
        ];

        $sign = $this->sign($vkParams, 'wrong_secret_key_here');

        $query = $this->buildQuery($vkParams, $sign);

        $this->assertNull($this->verifier->verify($query));
    }

    // ═══════════════════════════════════════════
    // MISSING PARAMETERS
    // ═══════════════════════════════════════════

    public function test_missing_sign_fails(): void
    {
        $query = 'vk_user_id=12345&vk_app_id=' . $this->testAppId;

        $this->assertNull($this->verifier->verify($query));
    }

    public function test_missing_user_id_fails(): void
    {
        $vkParams = [
            'vk_app_id' => $this->testAppId,
        ];

        $query = $this->buildQuery($vkParams);

        $this->assertNull($this->verifier->verify($query));
    }

    public function test_wrong_app_id_fails(): void
    {
        $vkParams = [
            'vk_user_id' => '12345',
            'vk_app_id' => '9999999',
        ];

        $query = $this->buildQuery($vkParams);

        $this->assertNull($this->verifier->verify($query));
    }

    public function test_empty_input_fails(): void
    {
        $this->assertNull($this->verifier->verify(''));
    }

    // ═══════════════════════════════════════════
    // NON-VK_* PARAMETERS
    // ═══════════════════════════════════════════

    public function test_non_vk_params_do_not_affect_signature(): void
    {
        $vkParams = [
            'vk_user_id' => '12345',
            'vk_app_id' => $this->testAppId,
        ];

        $sign = $this->sign($vkParams);

        // Add extra non-vk params — should not affect verification
        $query = 'vk_user_id=12345&vk_app_id=' . $this->testAppId . '&foo=bar&baz=qux&sign=' . $sign;

        $result = $this->verifier->verify($query);

        $this->assertNotNull($result);
        $this->assertEquals('12345', $result->userId);
    }

    // ═══════════════════════════════════════════
    // EDGE CASES
    // ═══════════════════════════════════════════

    public function test_empty_access_token_settings_handled_correctly(): void
    {
        // Используем reference vector с пустым vk_access_token_settings
        $query = 'vk_user_id=494075&vk_app_id=6736218&vk_is_app_user=1&vk_are_notifications_enabled=1&vk_language=ru&vk_access_token_settings=&vk_platform=android&sign=htQFduJpLxz7ribXRZpDFUH-XEUhC9rBPTJkjUFEkRA';

        // Должен пройти с настроенным app_secret
        config(['services.vk.app_secret' => 'wvl68m4dR1UpLrVRli']);
        config(['services.vk.app_id' => '6736218']);

        $result = $this->verifier->verify($query);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('vk_access_token_settings', $result->raw);
        $this->assertEquals('', $result->raw['vk_access_token_settings']);
    }

    public function test_order_of_query_params_does_not_matter(): void
    {
        // Same params in different order should produce same result
        $sign = $this->sign([
            'vk_user_id' => '12345',
            'vk_app_id' => $this->testAppId,
            'vk_platform' => 'web',
            'vk_language' => 'ru',
        ]);

        // Deliberately unsorted order in query
        $query = 'vk_platform=web&vk_user_id=12345&vk_language=ru&vk_app_id=' . $this->testAppId . '&sign=' . $sign;

        $result = $this->verifier->verify($query);

        $this->assertNotNull($result);
        $this->assertEquals('12345', $result->userId);
    }
}
