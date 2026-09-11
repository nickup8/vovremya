<?php

namespace Tests\Unit\Services\Auth;

use App\Services\Auth\VkPhoneNumberVerifier;
use App\Services\Auth\VkPhoneNumberResult;
use Tests\TestCase;

class VkPhoneNumberVerifierTest extends TestCase
{
    private string $testAppId = '6736218';
    private string $testAppSecret = 'wvl68m4dR1UpLrVRli';

    private VkPhoneNumberVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.vk.app_id' => $this->testAppId]);
        config(['services.vk.app_secret' => $this->testAppSecret]);
        $this->verifier = new VkPhoneNumberVerifier();
    }

    private function sign(string $userId, string $phone): string
    {
        return hash('sha256', $this->testAppId . $this->testAppSecret . $userId . 'phone_number' . $phone);
    }

    public function test_valid_signature_returns_result(): void
    {
        $userId = '12345';
        $phone = '+79001234567';
        $sign = $this->sign($userId, $phone);

        $result = $this->verifier->verify($userId, $phone, $sign);

        $this->assertInstanceOf(VkPhoneNumberResult::class, $result);
        $this->assertSame($userId, $result->userId);
        $this->assertSame($phone, $result->phone);
    }

    public function test_wrong_sign_returns_null(): void
    {
        $result = $this->verifier->verify('12345', '+79001234567', 'invalid_signature');

        $this->assertNull($result);
    }

    public function test_wrong_user_id_returns_null(): void
    {
        $phone = '+79001234567';
        $sign = $this->sign('12345', $phone);

        $result = $this->verifier->verify('99999', $phone, $sign);

        $this->assertNull($result);
    }

    public function test_changed_phone_returns_null(): void
    {
        $userId = '12345';
        $sign = $this->sign($userId, '+79001234567');

        $result = $this->verifier->verify($userId, '+79009999999', $sign);

        $this->assertNull($result);
    }

    public function test_missing_config_returns_null(): void
    {
        config(['services.vk.app_id' => null]);
        config(['services.vk.app_secret' => null]);

        $result = $this->verifier->verify('12345', '+79001234567', 'whatever');

        $this->assertNull($result);
    }

    public function test_empty_user_id_returns_null(): void
    {
        $sign = $this->sign('12345', '+79001234567');

        $result = $this->verifier->verify('', '+79001234567', $sign);

        $this->assertNull($result);
    }

    public function test_empty_phone_returns_null(): void
    {
        $sign = $this->sign('12345', '+79001234567');

        $result = $this->verifier->verify('12345', '', $sign);

        $this->assertNull($result);
    }

    public function test_empty_sign_returns_null(): void
    {
        $result = $this->verifier->verify('12345', '+79001234567', '');

        $this->assertNull($result);
    }

    public function test_phone_is_not_normalized_before_verification(): void
    {
        $userId = '12345';
        $rawPhone = '89001234567';
        $sign = $this->sign($userId, $rawPhone);

        $result = $this->verifier->verify($userId, $rawPhone, $sign);

        $this->assertInstanceOf(VkPhoneNumberResult::class, $result);
        $this->assertSame($rawPhone, $result->phone);
    }
}
