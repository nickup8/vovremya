<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\MaxApiClient;
use App\Services\Notification\MasterNotificationService;
use App\Services\VkApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class MasterNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private MasterNotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MasterNotificationService();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ── 1. MAX: enabled with max_id → sends via MAX ──

    public function test_max_enabled_sends_via_max(): void
    {
        $maxMock = Mockery::mock(MaxApiClient::class);
        $maxMock->shouldReceive('sendMessage')
            ->once()
            ->with('max-user-123', 'Test message')
            ->andReturn('msg-id-1');
        $this->app->instance(MaxApiClient::class, $maxMock);

        $master = User::factory()->create([
            'max_id' => 'max-user-123',
            'max_notifications' => true,
        ]);

        $this->service->sendToMaster($master, 'Test message');
    }

    // ── 2. MAX disabled → no MAX send ──

    public function test_max_disabled_does_not_send(): void
    {
        $maxMock = Mockery::mock(MaxApiClient::class);
        $maxMock->shouldNotReceive('sendMessage');
        $this->app->instance(MaxApiClient::class, $maxMock);

        $master = User::factory()->create([
            'max_id' => 'max-user-123',
            'max_notifications' => false,
        ]);

        $this->service->sendToMaster($master, 'Test message');
    }

    // ── 3. VK: enabled with vk_id → sends via VK ──

    public function test_vk_enabled_sends_via_vk(): void
    {
        $vkMock = Mockery::mock(VkApiClient::class);
        $vkMock->shouldReceive('sendMessage')
            ->once()
            ->with('vk-user-456', 'Test message')
            ->andReturn('vk-msg-id-1');
        $this->app->instance(VkApiClient::class, $vkMock);

        $maxMock = Mockery::mock(MaxApiClient::class);
        $maxMock->shouldNotReceive('sendMessage');
        $this->app->instance(MaxApiClient::class, $maxMock);

        $master = User::factory()->create([
            'vk_id' => 'vk-user-456',
            'vk_notifications' => true,
        ]);

        $this->service->sendToMaster($master, 'Test message');
    }

    // ── 4. VK disabled → no VK send ──

    public function test_vk_disabled_does_not_send(): void
    {
        $vkMock = Mockery::mock(VkApiClient::class);
        $vkMock->shouldNotReceive('sendMessage');
        $this->app->instance(VkApiClient::class, $vkMock);

        $master = User::factory()->create([
            'vk_id' => 'vk-user-456',
            'vk_notifications' => false,
        ]);

        $this->service->sendToMaster($master, 'Test message');
    }

    // ── 5. VK ID missing + vk_notifications=true → no VK send ──

    public function test_vk_enabled_but_no_vk_id_does_not_send(): void
    {
        $vkMock = Mockery::mock(VkApiClient::class);
        $vkMock->shouldNotReceive('sendMessage');
        $this->app->instance(VkApiClient::class, $vkMock);

        $master = User::factory()->create([
            'vk_id' => null,
            'vk_notifications' => true,
        ]);

        $this->service->sendToMaster($master, 'Test message');
    }

    // ── 6. Telegram legacy fields → no Telegram send ──

    public function test_telegram_legacy_fields_do_not_trigger_send(): void
    {
        $maxMock = Mockery::mock(MaxApiClient::class);
        $maxMock->shouldNotReceive('sendMessage');
        $this->app->instance(MaxApiClient::class, $maxMock);

        $vkMock = Mockery::mock(VkApiClient::class);
        $vkMock->shouldNotReceive('sendMessage');
        $this->app->instance(VkApiClient::class, $vkMock);

        $master = User::factory()->create([
            'telegram_id' => 'tg-chat-id',
            'telegram_notifications' => true,
            'max_id' => null,
            'max_notifications' => false,
            'vk_id' => null,
            'vk_notifications' => false,
        ]);

        $this->service->sendToMaster($master, 'Test message');
    }

    // ── 7. MAX + VK both enabled → both channels called ──

    public function test_both_max_and_vk_enabled_calls_both(): void
    {
        $maxMock = Mockery::mock(MaxApiClient::class);
        $maxMock->shouldReceive('sendMessage')
            ->once()
            ->with('max-user-123', 'Test message')
            ->andReturn('msg-id-1');
        $this->app->instance(MaxApiClient::class, $maxMock);

        $vkMock = Mockery::mock(VkApiClient::class);
        $vkMock->shouldReceive('sendMessage')
            ->once()
            ->with('vk-user-456', 'Test message')
            ->andReturn('vk-msg-id-1');
        $this->app->instance(VkApiClient::class, $vkMock);

        $master = User::factory()->create([
            'max_id' => 'max-user-123',
            'max_notifications' => true,
            'vk_id' => 'vk-user-456',
            'vk_notifications' => true,
        ]);

        $this->service->sendToMaster($master, 'Test message');
    }

    // ── 8. VK exception → does not propagate ──

    public function test_vk_exception_does_not_propagate(): void
    {
        $vkMock = Mockery::mock(VkApiClient::class);
        $vkMock->shouldReceive('sendMessage')
            ->once()
            ->andThrow(new \RuntimeException('VK API error'));
        $this->app->instance(VkApiClient::class, $vkMock);

        Log::shouldReceive('warning')
            ->once()
            ->with('VK master notification failed', Mockery::on(fn ($ctx) =>
                $ctx['channel'] === 'vk'
                && $ctx['master_id'] !== null
            ));

        $master = User::factory()->create([
            'vk_id' => 'vk-user-456',
            'vk_notifications' => true,
        ]);

        $this->service->sendToMaster($master, 'Test message');
    }

    // ── 9. MAX success + VK failure → MAX still called, no exception ──

    public function test_max_success_vk_failure_both_attempted(): void
    {
        $maxMock = Mockery::mock(MaxApiClient::class);
        $maxMock->shouldReceive('sendMessage')
            ->once()
            ->with('max-user-123', 'Test message')
            ->andReturn('msg-id-1');
        $this->app->instance(MaxApiClient::class, $maxMock);

        $vkMock = Mockery::mock(VkApiClient::class);
        $vkMock->shouldReceive('sendMessage')
            ->once()
            ->with('vk-user-456', 'Test message')
            ->andThrow(new \RuntimeException('VK API error'));
        $this->app->instance(VkApiClient::class, $vkMock);

        Log::shouldReceive('warning')
            ->once()
            ->with('VK master notification failed', Mockery::on(fn ($ctx) =>
                $ctx['channel'] === 'vk'
            ));

        $master = User::factory()->create([
            'max_id' => 'max-user-123',
            'max_notifications' => true,
            'vk_id' => 'vk-user-456',
            'vk_notifications' => true,
        ]);

        $this->service->sendToMaster($master, 'Test message');
    }
}
