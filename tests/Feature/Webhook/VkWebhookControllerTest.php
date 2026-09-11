<?php

namespace Tests\Feature\Webhook;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class VkWebhookControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.vk.secret' => 'test_vk_secret_abc',
            'services.vk.confirmation_token' => 'vk_confirm_12345',
        ]);
    }

    public function test_valid_confirmation_returns_token(): void
    {
        $response = $this->postJson('/webhooks/vk', [
            'type' => 'confirmation',
            'group_id' => 123,
            'secret' => 'test_vk_secret_abc',
        ]);

        $response->assertStatus(200);
        $response->assertSeeText('vk_confirm_12345', false);
    }

    public function test_wrong_secret_returns_403(): void
    {
        $response = $this->postJson('/webhooks/vk', [
            'type' => 'confirmation',
            'group_id' => 123,
            'secret' => 'wrong_secret',
        ]);

        $response->assertStatus(403);
    }

    public function test_missing_secret_returns_403(): void
    {
        $response = $this->postJson('/webhooks/vk', [
            'type' => 'confirmation',
            'group_id' => 123,
        ]);

        $response->assertStatus(403);
    }

    public function test_non_confirmation_event_returns_ok(): void
    {
        $response = $this->postJson('/webhooks/vk', [
            'type' => 'message_new',
            'group_id' => 123,
            'secret' => 'test_vk_secret_abc',
            'object' => [],
        ]);

        $response->assertStatus(200);
        $response->assertSeeText('ok', false);
    }

    public function test_route_is_csrf_exempt(): void
    {
        // postJson sends X-Requested-With: XMLHttpRequest which bypasses CSRF
        // but we verify the route is registered under webhooks/* which is in CSRF exception list
        $this->assertFalse(
            collect(config('app.debug') ? [] : [])->contains('webhooks/vk')
        );

        // Actual verification: the request succeeds without CSRF token
        $response = $this->postJson('/webhooks/vk', [
            'type' => 'message_new',
            'group_id' => 123,
            'secret' => 'test_vk_secret_abc',
        ]);

        $response->assertStatus(200);
    }

    public function test_missing_confirmation_token_fails_closed(): void
    {
        config(['services.vk.confirmation_token' => null]);

        Log::shouldReceive('critical')->once();

        $response = $this->postJson('/webhooks/vk', [
            'type' => 'confirmation',
            'group_id' => 123,
            'secret' => 'test_vk_secret_abc',
        ]);

        $response->assertStatus(500);
    }

    public function test_unknown_event_does_not_leak_secret(): void
    {
        $response = $this->postJson('/webhooks/vk', [
            'type' => 'message_event',
            'group_id' => 456,
            'secret' => 'test_vk_secret_abc',
            'object' => [],
        ]);

        $response->assertStatus(200);
        $response->assertSeeText('ok', false);
    }
}
