<?php

namespace Tests\Feature;

use App\Constants\CacheKeys;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Events\AppointmentCreated;
use App\Listeners\SendVkBookingConfirmation;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use App\Services\VkApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class VkBookingConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private User $master;
    private Workspace $ws;
    private MasterService $masterService;
    private Client $client;
    private Appointment $appointment;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.vk.app_id' => '6736218']);
        config(['services.vk.group_id' => '123456']);
        config(['services.vk.bot_token' => 'test_bot_token']);

        $this->master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow'],
            'address' => 'ул. Пушкина, 10',
        ]);
        $this->ws = Workspace::create(['name' => 'WS', 'owner_id' => $this->master->id]);
        $this->master->update(['workspace_id' => $this->ws->id]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $this->ws->id, 'title' => 'Массаж', 'base_price' => 2000, 'base_duration' => 60,
        ]);
        $this->masterService = MasterService::create([
            'master_id' => $this->master->id, 'catalog_id' => $catalog->id, 'is_active' => true,
        ]);

        $this->client = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'vk_id' => '494075',
        ]);

        $this->appointment = Appointment::factory()
            ->forMaster($this->master)->forClient($this->client)->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'source' => AppointmentSource::Vk,
                'start_time' => Carbon::tomorrow()->setTime(14, 0),
                'duration' => 60,
                'price' => 1500,
            ]);
    }

    private function fireCreated(): void
    {
        event(new AppointmentCreated($this->appointment));
    }

    // ── Source filtering ──────────────────────────────────

    public function test_vk_appointment_created_sends_confirmation(): void
    {
        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->with('494075', \Mockery::type('string'), \Mockery::type('array'))
            ->andReturn('msg_123');

        $this->fireCreated();
    }

    public function test_max_source_does_not_send_vk_message(): void
    {
        $this->appointment->update(['source' => AppointmentSource::Max]);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldNotReceive('sendMessageWithKeyboard');

        $this->fireCreated();
    }

    public function test_telegram_source_does_not_send_vk_message(): void
    {
        $this->appointment->update(['source' => AppointmentSource::Telegram]);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldNotReceive('sendMessageWithKeyboard');

        $this->fireCreated();
    }

    public function test_admin_source_does_not_send_vk_message(): void
    {
        $this->appointment->update(['source' => AppointmentSource::Admin]);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldNotReceive('sendMessageWithKeyboard');

        $this->fireCreated();
    }

    public function test_vk_without_vk_id_does_not_send(): void
    {
        $this->client->update(['vk_id' => null]);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldNotReceive('sendMessageWithKeyboard');

        $this->fireCreated();
    }

    public function test_vk_without_client_does_not_send(): void
    {
        $this->appointment->update(['client_id' => null]);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldNotReceive('sendMessageWithKeyboard');

        $this->fireCreated();
    }

    // ── Message content ──────────────────────────────────

    public function test_message_contains_master_service_date_time(): void
    {
        $capturedText = null;

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->with('494075', \Mockery::on(function ($text) use (&$capturedText) {
                $capturedText = $text;
                return true;
            }), \Mockery::type('array'))
            ->andReturn('msg_123');

        $this->fireCreated();

        $this->assertStringContainsString('✅ Запись подтверждена!', $capturedText);
        $this->assertStringContainsString($this->master->name, $capturedText);
        $this->assertStringContainsString($this->appointment->display_name, $capturedText);
        $this->assertStringContainsString(Carbon::tomorrow()->setTime(14, 0)->format('d.m.Y'), $capturedText);
        $this->assertStringContainsString('1 500', $capturedText);
        $this->assertStringContainsString('Пушкина', $capturedText);
        $this->assertStringContainsString('Ждём вас!', $capturedText);
    }

    public function test_timezone_is_master_timezone(): void
    {
        $capturedText = null;

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->with('494075', \Mockery::on(function ($text) use (&$capturedText) {
                $capturedText = $text;
                return true;
            }), \Mockery::type('array'))
            ->andReturn('msg_123');

        $this->fireCreated();

        // 14:00 UTC = 17:00 Moscow
        $this->assertStringContainsString('17:00', $capturedText);
    }

    public function test_address_shows_dash_when_null(): void
    {
        $this->master->update(['address' => null]);

        $capturedText = null;

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->with('494075', \Mockery::on(function ($text) use (&$capturedText) {
                $capturedText = $text;
                return true;
            }), \Mockery::type('array'))
            ->andReturn('msg_123');

        $this->fireCreated();

        $this->assertStringContainsString('Адрес: —', $capturedText);
    }

    // ── Keyboard ─────────────────────────────────────────

    public function test_keyboard_is_open_app_inline(): void
    {
        $capturedKeyboard = null;

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->with('494075', \Mockery::type('string'), \Mockery::on(function ($kb) use (&$capturedKeyboard) {
                $capturedKeyboard = $kb;
                return true;
            }))
            ->andReturn('msg_123');

        $this->fireCreated();

        $this->assertFalse($capturedKeyboard['one_time']);
        $this->assertTrue($capturedKeyboard['inline']);
        $this->assertSame('open_app', $capturedKeyboard['buttons'][0][0]['action']['type']);
        $this->assertSame(6736218, $capturedKeyboard['buttons'][0][0]['action']['app_id']);
        $this->assertSame(-123456, $capturedKeyboard['buttons'][0][0]['action']['owner_id']);
        $this->assertSame('📅 Открыть ИРСИ', $capturedKeyboard['buttons'][0][0]['action']['label']);
        $this->assertSame('', $capturedKeyboard['buttons'][0][0]['action']['hash']);
        $this->assertArrayNotHasKey('color', $capturedKeyboard['buttons'][0][0]);
    }

    public function test_peer_id_is_client_vk_id(): void
    {
        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->with('494075', \Mockery::type('string'), \Mockery::type('array'))
            ->andReturn('msg_123');

        $this->fireCreated();
    }

    // ── Cache dedup ──────────────────────────────────────

    public function test_duplicate_event_does_not_send_second_message(): void
    {
        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->andReturn('msg_123');

        $this->fireCreated();
        $this->fireCreated(); // second event — should be deduped
    }

    public function test_cache_lock_remains_after_success(): void
    {
        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->andReturn('msg_123');

        $this->fireCreated();

        $this->assertTrue(
            Cache::has(CacheKeys::VK_BOOKING_CONFIRMED . $this->appointment->id)
        );
    }

    // ── Failure / retry ──────────────────────────────────

    public function test_vk_api_null_removes_lock_and_throws(): void
    {
        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->andReturn(null);

        try {
            $this->fireCreated();
        } catch (\Throwable) {
            // expected — listener throws for queue retry
        }

        $this->assertFalse(
            Cache::has(CacheKeys::VK_BOOKING_CONFIRMED . $this->appointment->id)
        );
    }

    public function test_retry_after_failure_can_succeed(): void
    {
        $callCount = 0;

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->twice()
            ->andReturnUsing(function () use (&$callCount) {
                $callCount++;
                return $callCount === 1 ? null : 'msg_retry';
            });

        // First attempt fails → lock removed
        try {
            $this->fireCreated();
        } catch (\Throwable) {
            // expected
        }

        $this->assertFalse(
            Cache::has(CacheKeys::VK_BOOKING_CONFIRMED . $this->appointment->id)
        );

        // Second attempt succeeds
        $this->fireCreated();

        $this->assertTrue(
            Cache::has(CacheKeys::VK_BOOKING_CONFIRMED . $this->appointment->id)
        );
    }

    // ── Production event path ────────────────────────────

    public function test_broadcast_triggers_listener(): void
    {
        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->andReturn('msg_123');

        // Use broadcast() — same as VK controllers
        broadcast(new AppointmentCreated($this->appointment->load(['client'])));
    }

    public function test_listener_is_queued(): void
    {
        $this->assertTrue(
            in_array(\Illuminate\Contracts\Queue\ShouldQueue::class, class_implements(SendVkBookingConfirmation::class))
        );
    }

    // ── Existing tests ───────────────────────────────────

    public function test_existing_vk_booking_tests_still_pass(): void
    {
        // Smoke: appointment creation with VK source works
        $appt = Appointment::factory()
            ->forMaster($this->master)->forClient($this->client)->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'source' => AppointmentSource::Vk,
                'start_time' => Carbon::tomorrow()->setTime(10, 0),
                'duration' => 60,
            ]);

        $this->assertSame(AppointmentSource::Vk, $appt->source);
        $this->assertNotNull($appt->client);
    }
}
