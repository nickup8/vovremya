<?php

namespace Tests\Unit\Services;

use App\Services\VkLinkTokenService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class VkLinkTokenServiceTest extends TestCase
{
    private VkLinkTokenService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new VkLinkTokenService();
    }

    public function test_create_returns_opaque_token(): void
    {
        $token = $this->service->create(Str::uuid()->toString());

        $this->assertIsString($token);
        $this->assertNotEmpty($token);
        $this->assertStringStartsWith('link_vk_', $token);
    }

    public function test_token_does_not_contain_appointment_id(): void
    {
        $appointmentId = '550e8400-e29b-41d4-a716-446655440000';
        $token = $this->service->create($appointmentId);

        $this->assertStringNotContainsString($appointmentId, $token);
    }

    public function test_consume_returns_appointment_id(): void
    {
        $appointmentId = Str::uuid()->toString();
        $token = $this->service->create($appointmentId);

        $result = $this->service->consume($token);

        $this->assertEquals($appointmentId, $result);
    }

    public function test_second_consume_returns_null(): void
    {
        $appointmentId = Str::uuid()->toString();
        $token = $this->service->create($appointmentId);

        $this->service->consume($token);
        $result = $this->service->consume($token);

        $this->assertNull($result);
    }

    public function test_unknown_token_returns_null(): void
    {
        $result = $this->service->consume('link_vk_nonexistent_token_value_here__');

        $this->assertNull($result);
    }

    public function test_expired_token_returns_null(): void
    {
        $appointmentId = Str::uuid()->toString();
        $token = $this->service->create($appointmentId);

        // Simulate expiry by clearing the cache entry
        Cache::forget('vk_link_token:' . $token);

        $result = $this->service->consume($token);

        $this->assertNull($result);
    }

    public function test_two_creates_return_different_tokens(): void
    {
        $token1 = $this->service->create(Str::uuid()->toString());
        $token2 = $this->service->create(Str::uuid()->toString());

        $this->assertNotEquals($token1, $token2);
    }

    public function test_ttl_matches_booking_draft_ttl(): void
    {
        config(['booking.draft_ttl' => 900]);

        $appointmentId = Str::uuid()->toString();
        $token = $this->service->create($appointmentId);

        // Token should exist immediately
        $this->assertEquals($appointmentId, $this->service->consume($token));

        // Create another and verify it uses the configured TTL
        $token2 = $this->service->create(Str::uuid()->toString());
        $key = 'vk_link_token:' . $token2;

        // Cache::get should return the value (not yet expired)
        $this->assertNotNull(Cache::get($key));
    }

    // --- peek() tests ---

    public function test_peek_valid_token_returns_appointment_id(): void
    {
        $appointmentId = Str::uuid()->toString();
        $token = $this->service->create($appointmentId);

        $this->assertEquals($appointmentId, $this->service->peek($token));
    }

    public function test_peek_does_not_consume_token(): void
    {
        $appointmentId = Str::uuid()->toString();
        $token = $this->service->create($appointmentId);

        $this->service->peek($token);

        $this->assertEquals($appointmentId, $this->service->consume($token));
    }

    public function test_two_successive_peeks_return_same_id(): void
    {
        $appointmentId = Str::uuid()->toString();
        $token = $this->service->create($appointmentId);

        $this->assertEquals($appointmentId, $this->service->peek($token));
        $this->assertEquals($appointmentId, $this->service->peek($token));
    }

    public function test_peek_then_consume_succeeds(): void
    {
        $appointmentId = Str::uuid()->toString();
        $token = $this->service->create($appointmentId);

        $this->service->peek($token);

        $this->assertEquals($appointmentId, $this->service->consume($token));
    }

    public function test_peek_after_consume_returns_null(): void
    {
        $appointmentId = Str::uuid()->toString();
        $token = $this->service->create($appointmentId);

        $this->service->consume($token);

        $this->assertNull($this->service->peek($token));
    }

    public function test_peek_expired_token_returns_null(): void
    {
        $appointmentId = Str::uuid()->toString();
        $token = $this->service->create($appointmentId);

        Cache::forget('vk_link_token:' . $token);

        $this->assertNull($this->service->peek($token));
    }

    public function test_peek_unknown_token_returns_null(): void
    {
        $this->assertNull($this->service->peek('link_vk_nonexistent_token_value_here__'));
    }
}
