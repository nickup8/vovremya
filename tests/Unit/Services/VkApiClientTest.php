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

    // ═══════════════════════════════════════
    // sendMessageWithKeyboard
    // ═══════════════════════════════════════

    public function test_send_message_with_keyboard_returns_message_id(): void
    {
        Http::fake([
            'api.vk.com/*' => Http::response(['response' => 789], 200),
        ]);

        $keyboard = [
            'one_time' => false,
            'inline' => true,
            'buttons' => [[[
                'action' => ['type' => 'callback', 'label' => 'Accept', 'payload' => '{}'],
                'color' => 'positive',
            ]]],
        ];

        $client = new VkApiClient();
        $result = $client->sendMessageWithKeyboard('999', 'Choose', $keyboard);

        $this->assertSame('789', $result);
    }

    public function test_send_message_with_keyboard_uses_messages_send(): void
    {
        Http::fake([
            'api.vk.com/*' => Http::response(['response' => 1], 200),
        ]);

        $client = new VkApiClient();
        $client->sendMessageWithKeyboard('1', 'hi', ['inline' => true, 'buttons' => []]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.vk.com/method/messages.send';
        });
    }

    public function test_send_message_with_keyboard_encodes_keyboard_as_json(): void
    {
        Http::fake([
            'api.vk.com/*' => Http::response(['response' => 1], 200),
        ]);

        $keyboard = [
            'one_time' => false,
            'inline' => true,
            'buttons' => [[[
                'action' => ['type' => 'callback', 'label' => 'Go', 'payload' => '{"cv":"abc"}'],
                'color' => 'positive',
            ]]],
        ];

        $client = new VkApiClient();
        $client->sendMessageWithKeyboard('1', 'test', $keyboard);

        Http::assertSent(function ($request) use ($keyboard) {
            $data = $request->data();
            $decoded = json_decode($data['keyboard'], true);

            return is_string($data['keyboard'])
                && $decoded['inline'] === true
                && $decoded['buttons'][0][0]['action']['type'] === 'callback';
        });
    }

    public function test_send_message_with_keyboard_vk_error_returns_null(): void
    {
        Http::fake([
            'api.vk.com/*' => Http::response([
                'error' => ['error_code' => 900, 'error_msg' => 'Keyboard error'],
            ], 200),
        ]);

        Log::shouldReceive('error')->once();

        $client = new VkApiClient();
        $result = $client->sendMessageWithKeyboard('1', 'test', ['buttons' => []]);

        $this->assertNull($result);
    }

    public function test_send_message_with_keyboard_missing_config_returns_null(): void
    {
        config(['services.vk.bot_token' => null]);

        Http::fake([
            'api.vk.com/*' => Http::response(['response' => 1], 200),
        ]);

        Log::shouldReceive('warning')->once();

        $client = new VkApiClient();
        $result = $client->sendMessageWithKeyboard('1', 'test', []);

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    // ═══════════════════════════════════════
    // answerMessageEvent
    // ═══════════════════════════════════════

    public function test_answer_message_event_success_returns_true(): void
    {
        Http::fake([
            'api.vk.com/*' => Http::response(['response' => 1], 200),
        ]);

        $client = new VkApiClient();
        $result = $client->answerMessageEvent('evt-1', '42', '100', 'Done!');

        $this->assertTrue($result);
    }

    public function test_answer_message_event_uses_correct_url(): void
    {
        Http::fake([
            'api.vk.com/*' => Http::response(['response' => 1], 200),
        ]);

        $client = new VkApiClient();
        $client->answerMessageEvent('evt-1', '42', '100', 'Done!');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.vk.com/method/messages.sendMessageEventAnswer';
        });
    }

    public function test_answer_message_event_payload_contains_required_fields(): void
    {
        Http::fake([
            'api.vk.com/*' => Http::response(['response' => 1], 200),
        ]);

        $client = new VkApiClient();
        $client->answerMessageEvent('evt-abc', '42', '100', 'Hello!');

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $data['event_id'] === 'evt-abc'
                && $data['user_id'] === '42'
                && $data['peer_id'] === '100';
        });
    }

    public function test_answer_message_event_encodes_event_data_correctly(): void
    {
        Http::fake([
            'api.vk.com/*' => Http::response(['response' => 1], 200),
        ]);

        $client = new VkApiClient();
        $client->answerMessageEvent('evt-1', '42', '100', 'Accepted!');

        Http::assertSent(function ($request) {
            $data = $request->data();
            $decoded = json_decode($data['event_data'], true);

            return is_string($data['event_data'])
                && $decoded['type'] === 'show_snackbar'
                && $decoded['text'] === 'Accepted!';
        });
    }

    public function test_answer_message_event_vk_error_returns_false(): void
    {
        Http::fake([
            'api.vk.com/*' => Http::response([
                'error' => ['error_code' => 901, 'error_msg' => 'Event error'],
            ], 200),
        ]);

        Log::shouldReceive('error')->once();

        $client = new VkApiClient();
        $result = $client->answerMessageEvent('evt-1', '42', '100', 'fail');

        $this->assertFalse($result);
    }

    public function test_answer_message_event_http_failure_returns_false(): void
    {
        Http::fake([
            'api.vk.com/*' => Http::response('Bad Gateway', 502),
        ]);

        Log::shouldReceive('error')->once();

        $client = new VkApiClient();
        $result = $client->answerMessageEvent('evt-1', '42', '100', 'fail');

        $this->assertFalse($result);
    }

    public function test_answer_message_event_missing_config_returns_false(): void
    {
        config(['services.vk.bot_token' => null]);

        Http::fake([
            'api.vk.com/*' => Http::response(['response' => 1], 200),
        ]);

        Log::shouldReceive('warning')->once();

        $client = new VkApiClient();
        $result = $client->answerMessageEvent('evt-1', '42', '100', 'fail');

        $this->assertFalse($result);
        Http::assertNothingSent();
    }
}
