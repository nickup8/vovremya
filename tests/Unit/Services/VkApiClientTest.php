<?php

namespace Tests\Unit\Services;

use App\Services\VkApiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class VkApiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.vk.bot_token' => 'test_vk_token_abc123']);
        config(['services.vk.api_version' => '5.199']);
    }

    public function test_successful_response_returns_message_id(): void
    {
        Http::fake([
            'api.vk.com/*' => Http::response(['response' => 456], 200),
        ]);

        $client = new VkApiClient();
        $result = $client->sendMessage('12345', 'Hello');

        $this->assertSame('456', $result);
    }

    public function test_request_url_is_messages_send(): void
{
        Http::fake([
            'api.vk.com/*' => Http::response(['response' => 1], 200),
        ]);

        $client = new VkApiClient();
        $client->sendMessage('12345', 'test');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.vk.com/method/messages.send';
        });
    }

    public function test_request_payload_contains_required_fields(): void
{
        Http::fake([
            'api.vk.com/*' => Http::response(['response' => 1], 200),
        ]);

        $client = new VkApiClient();
        $client->sendMessage('67890', 'Test message');

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $data['peer_id'] === '67890'
                && $data['message'] === 'Test message'
                && $data['access_token'] === 'test_vk_token_abc123'
                && $data['v'] === '5.199'
                && isset($data['random_id'])
                && is_int($data['random_id'])
                && $data['random_id'] > 0;
        });
    }

    public function test_random_id_is_positive_integer(): void
{
        Http::fake([
            'api.vk.com/*' => Http::response(['response' => 1], 200),
        ]);

        $client = new VkApiClient();
        $client->sendMessage('1', 'hi');

        Http::assertSent(function ($request) {
            $rid = $request->data()['random_id'];

            return is_int($rid) && $rid > 0 && $rid <= 2147483647;
        });
    }

    public function test_vk_api_error_returns_null(): void
{
        Http::fake([
            'api.vk.com/*' => Http::response([
                'error' => [
                    'error_code' => 5,
                    'error_msg' => 'User authorization failed',
                ],
            ], 200),
        ]);

        Log::shouldReceive('error')
            ->once()
            ->with('[VK] messages.send failed', \Mockery::on(fn ($ctx) =>
                $ctx['error_code'] === 5
                && $ctx['error_msg'] === 'User authorization failed'
                && $ctx['peer_id'] === '12345'
            ));

        $client = new VkApiClient();
        $result = $client->sendMessage('12345', 'test');

        $this->assertNull($result);
    }

    public function test_http_500_returns_null(): void
    {
        Http::fake([
            'api.vk.com/*' => Http::response('Internal Server Error', 500),
        ]);

        Log::shouldReceive('error')->once();

        $client = new VkApiClient();
        $result = $client->sendMessage('12345', 'test');

        $this->assertNull($result);
    }

    public function test_connection_exception_returns_null(): void
    {
        Http::fake([
            'api.vk.com/*' => function () {
                throw new \RuntimeException('Connection refused');
            },
        ]);

        Log::shouldReceive('error')->once();

        $client = new VkApiClient();
        $result = $client->sendMessage('12345', 'test');

        $this->assertNull($result);
    }

    public function test_missing_token_returns_null_without_http_request(): void
    {
        config(['services.vk.bot_token' => null]);

        Http::fake([
            'api.vk.com/*' => Http::response(['response' => 1], 200),
        ]);

        Log::shouldReceive('warning')->once();

        $client = new VkApiClient();
        $result = $client->sendMessage('12345', 'test');

        $this->assertNull($result);
        Http::assertNothingSent();
    }
}
