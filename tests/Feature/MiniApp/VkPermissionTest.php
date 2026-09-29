<?php

namespace Tests\Feature\MiniApp;

use App\Constants\CacheKeys;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Events\AppointmentCreated;
use App\Exceptions\VkMessagesNotAllowedException;
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
use Mockery;
use Tests\TestCase;

class VkPermissionTest extends TestCase
{
    use RefreshDatabase;

    private string $testAppId = '6736218';
    private string $testAppSecret = 'test_secret_key';
    private User $master;
    private Workspace $ws;
    private MasterService $masterService;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.vk.app_id' => $this->testAppId]);
        config(['services.vk.app_secret' => $this->testAppSecret]);
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
    }

    private function vkAuthHeaders(string $vkUserId): array
    {
        $vkParams = [
            'vk_user_id' => $vkUserId,
            'vk_app_id' => $this->testAppId,
            'vk_platform' => 'android',
        ];
        ksort($vkParams);
        $parts = [];
        foreach ($vkParams as $key => $value) {
            $parts[] = $key . '=' . $value;
        }
        $canonical = implode('&', $parts);
        $hmac = hash_hmac('sha256', $canonical, $this->testAppSecret, true);
        $sign = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($hmac));
        $all = array_merge($vkParams, ['sign' => $sign]);

        return ['Authorization' => 'Bearer ' . http_build_query($all)];
    }

    private function createClient(string $vkId = '494075'): Client
    {
        return Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'vk_id' => $vkId,
        ]);
    }

    private function createAppointment(Client $client): Appointment
    {
        return Appointment::factory()
            ->forMaster($this->master)->forClient($client)->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'source' => AppointmentSource::Vk,
                'start_time' => Carbon::tomorrow()->setTime(14, 0),
                'duration' => 60,
                'price' => 1500,
            ]);
    }

    // ═══════════════════════════════════════════════
    // §24 — Permission Endpoint: granted=true
    // ═══════════════════════════════════════════════

    public function test_granted_true_sets_vk_messages_allowed(): void
    {
        $client = $this->createClient();
        $appointment = $this->createAppointment($client);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')->andReturn('msg_123');

        $this->postJson('/api/miniapp/vk-permission', [
            'appointment_id' => $appointment->id,
            'granted' => true,
        ], $this->vkAuthHeaders('494075'))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $client->refresh();
        $this->assertTrue($client->vk_messages_allowed);
    }

    public function test_granted_true_respects_already_true(): void
    {
        $client = $this->createClient();
        $client->update(['vk_messages_allowed' => true]);
        $appointment = $this->createAppointment($client);

        $this->postJson('/api/miniapp/vk-permission', [
            'appointment_id' => $appointment->id,
            'granted' => true,
        ], $this->vkAuthHeaders('494075'))
            ->assertOk();

        $client->refresh();
        $this->assertTrue($client->vk_messages_allowed);
    }

    public function test_granted_true_fires_appointment_created_when_transitioning(): void
    {
        Event::fake([AppointmentCreated::class]);

        $client = $this->createClient();
        $appointment = $this->createAppointment($client);

        $this->postJson('/api/miniapp/vk-permission', [
            'appointment_id' => $appointment->id,
            'granted' => true,
        ], $this->vkAuthHeaders('494075'))
            ->assertOk();

        Event::assertDispatched(AppointmentCreated::class, function ($event) use ($appointment) {
            return $event->appointment->id === $appointment->id;
        });
    }

    public function test_granted_true_does_not_fire_when_already_true(): void
    {
        Event::fake([AppointmentCreated::class]);

        $client = $this->createClient();
        $client->update(['vk_messages_allowed' => true]);
        $appointment = $this->createAppointment($client);

        $this->postJson('/api/miniapp/vk-permission', [
            'appointment_id' => $appointment->id,
            'granted' => true,
        ], $this->vkAuthHeaders('494075'))
            ->assertOk();

        Event::assertNotDispatched(AppointmentCreated::class);
    }

    // ═══════════════════════════════════════════════
    // §24 — Permission Endpoint: granted=false
    // ═══════════════════════════════════════════════

    public function test_granted_false_sets_vk_messages_allowed_to_false_when_null(): void
    {
        $client = $this->createClient();
        $this->assertNull($client->vk_messages_allowed);

        $appointment = $this->createAppointment($client);

        $this->postJson('/api/miniapp/vk-permission', [
            'appointment_id' => $appointment->id,
            'granted' => false,
        ], $this->vkAuthHeaders('494075'))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $client->refresh();
        $this->assertFalse($client->vk_messages_allowed);
    }

    public function test_granted_false_does_not_downgrade_true(): void
    {
        $client = $this->createClient();
        $client->update(['vk_messages_allowed' => true]);
        $appointment = $this->createAppointment($client);

        $this->postJson('/api/miniapp/vk-permission', [
            'appointment_id' => $appointment->id,
            'granted' => false,
        ], $this->vkAuthHeaders('494075'))
            ->assertOk();

        $client->refresh();
        $this->assertTrue($client->vk_messages_allowed);
    }

    public function test_granted_false_leaves_false_as_false(): void
    {
        $client = $this->createClient();
        $client->update(['vk_messages_allowed' => false]);
        $appointment = $this->createAppointment($client);

        $this->postJson('/api/miniapp/vk-permission', [
            'appointment_id' => $appointment->id,
            'granted' => false,
        ], $this->vkAuthHeaders('494075'))
            ->assertOk();

        $client->refresh();
        $this->assertFalse($client->vk_messages_allowed);
    }

    // ═══════════════════════════════════════════════
    // §24 — Permission Endpoint: ownership / validation
    // ═══════════════════════════════════════════════

    public function test_missing_fields_returns_422(): void
    {
        $this->postJson('/api/miniapp/vk-permission', [], $this->vkAuthHeaders('494075'))
            ->assertUnprocessable();
    }

    public function test_missing_granted_returns_422(): void
    {
        $client = $this->createClient();
        $appointment = $this->createAppointment($client);

        $this->postJson('/api/miniapp/vk-permission', [
            'appointment_id' => $appointment->id,
        ], $this->vkAuthHeaders('494075'))
            ->assertUnprocessable();
    }

    public function test_cross_client_appointment_returns_403(): void
    {
        $clientA = $this->createClient('111111');
        $clientB = $this->createClient('222222');
        $appointment = $this->createAppointment($clientA);

        $this->postJson('/api/miniapp/vk-permission', [
            'appointment_id' => $appointment->id,
            'granted' => true,
        ], $this->vkAuthHeaders('222222'))
            ->assertStatus(403);
    }

    public function test_nonexistent_appointment_returns_404(): void
    {
        $this->postJson('/api/miniapp/vk-permission', [
            'appointment_id' => '00000000-0000-0000-0000-000000000000',
            'granted' => true,
        ], $this->vkAuthHeaders('494075'))
            ->assertStatus(404);
    }

    public function test_valid_granted_true_returns_ok(): void
    {
        $client = $this->createClient();
        $appointment = $this->createAppointment($client);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')->andReturn('msg_123');

        $this->postJson('/api/miniapp/vk-permission', [
            'appointment_id' => $appointment->id,
            'granted' => true,
        ], $this->vkAuthHeaders('494075'))
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_valid_granted_false_returns_ok(): void
    {
        $client = $this->createClient();
        $appointment = $this->createAppointment($client);

        $this->postJson('/api/miniapp/vk-permission', [
            'appointment_id' => $appointment->id,
            'granted' => false,
        ], $this->vkAuthHeaders('494075'))
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    // ═══════════════════════════════════════════════
    // §25 — Confirmation: permission gate
    // ═══════════════════════════════════════════════

    public function test_confirmation_skipped_when_vk_messages_allowed_false(): void
    {
        $client = $this->createClient();
        $client->update(['vk_messages_allowed' => false]);
        $appointment = $this->createAppointment($client);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldNotReceive('sendMessageWithKeyboard');

        event(new AppointmentCreated($appointment));
    }

    public function test_confirmation_sent_when_vk_messages_allowed_true(): void
    {
        $client = $this->createClient();
        $client->update(['vk_messages_allowed' => true]);
        $appointment = $this->createAppointment($client);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->andReturn('msg_123');

        event(new AppointmentCreated($appointment));
    }

    public function test_confirmation_sent_when_vk_messages_allowed_null_legacy(): void
    {
        $client = $this->createClient();
        $this->assertNull($client->vk_messages_allowed);
        $appointment = $this->createAppointment($client);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->andReturn('msg_123');

        event(new AppointmentCreated($appointment));
    }

    // ═══════════════════════════════════════════════
    // §25 — Confirmation: dedup with sent_at
    // ═══════════════════════════════════════════════

    public function test_confirmation_skipped_when_sent_at_already_set(): void
    {
        $client = $this->createClient();
        $client->update(['vk_messages_allowed' => true]);
        $appointment = $this->createAppointment($client);
        $appointment->update(['vk_confirmation_sent_at' => now()]);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldNotReceive('sendMessageWithKeyboard');

        event(new AppointmentCreated($appointment));
    }

    public function test_sent_at_set_after_successful_send(): void
    {
        $client = $this->createClient();
        $client->update(['vk_messages_allowed' => true]);
        $appointment = $this->createAppointment($client);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->andReturn('msg_123');

        event(new AppointmentCreated($appointment));

        $appointment->refresh();
        $this->assertNotNull($appointment->vk_confirmation_sent_at);
    }

    // ═══════════════════════════════════════════════
    // §26 — VK error 901: terminal handling
    // ═══════════════════════════════════════════════

    public function test_901_sets_vk_messages_allowed_false(): void
    {
        $client = $this->createClient();
        $this->assertNull($client->vk_messages_allowed);
        $appointment = $this->createAppointment($client);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->andThrow(new VkMessagesNotAllowedException());

        try {
            event(new AppointmentCreated($appointment));
        } catch (\Throwable) {
            // listener may throw for retry, but 901 should have set permission
        }

        $client->refresh();
        $this->assertFalse($client->vk_messages_allowed);
    }

    public function test_901_does_not_set_sent_at(): void
    {
        $client = $this->createClient();
        $appointment = $this->createAppointment($client);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->andThrow(new VkMessagesNotAllowedException());

        try {
            event(new AppointmentCreated($appointment));
        } catch (\Throwable) {
            // expected
        }

        $appointment->refresh();
        $this->assertNull($appointment->vk_confirmation_sent_at);
    }

    public function test_901_sets_false_even_when_already_true(): void
    {
        $client = $this->createClient();
        $client->update(['vk_messages_allowed' => true]);
        $appointment = $this->createAppointment($client);

        // Force re-send by clearing sent_at
        $appointment->update(['vk_confirmation_sent_at' => null]);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->andThrow(new VkMessagesNotAllowedException());

        try {
            event(new AppointmentCreated($appointment));
        } catch (\Throwable) {
            // expected
        }

        // 901 is a factual VK signal — always sets false
        $client->refresh();
        $this->assertFalse($client->vk_messages_allowed);
    }

    // ═══════════════════════════════════════════════
    // §27 — Legacy null learning: success sets true
    // ═══════════════════════════════════════════════

    public function test_legacy_null_becomes_true_after_successful_send(): void
    {
        $client = $this->createClient();
        $this->assertNull($client->vk_messages_allowed);
        $appointment = $this->createAppointment($client);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->andReturn('msg_123');

        event(new AppointmentCreated($appointment));

        $client->refresh();
        $this->assertTrue($client->vk_messages_allowed);
    }

    public function test_already_true_stays_true_after_successful_send(): void
    {
        $client = $this->createClient();
        $client->update(['vk_messages_allowed' => true]);
        $appointment = $this->createAppointment($client);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->andReturn('msg_123');

        event(new AppointmentCreated($appointment));

        $client->refresh();
        $this->assertTrue($client->vk_messages_allowed);
    }

    // ═══════════════════════════════════════════════
    // §28 — Permission endpoint + race scenario
    // ═══════════════════════════════════════════════

    public function test_permission_true_then_event_fires_confirmation(): void
    {
        $client = $this->createClient();
        $appointment = $this->createAppointment($client);

        Event::fake([AppointmentCreated::class]);

        // Grant permission
        $this->postJson('/api/miniapp/vk-permission', [
            'appointment_id' => $appointment->id,
            'granted' => true,
        ], $this->vkAuthHeaders('494075'))
            ->assertOk();

        // AppointmentCreated should have been dispatched
        Event::assertDispatched(AppointmentCreated::class, function ($event) use ($appointment) {
            return $event->appointment->id === $appointment->id;
        });
    }

    public function test_multiple_clients_same_vk_id(): void
    {
        $client1 = $this->createClient('494075');
        $client2 = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'vk_id' => '494075',
        ]);

        $appointment1 = $this->createAppointment($client1);
        $appointment2 = Appointment::factory()
            ->forMaster($this->master)->forClient($client2)->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'source' => AppointmentSource::Vk,
                'start_time' => Carbon::tomorrow()->setTime(16, 0),
                'duration' => 60,
                'price' => 1500,
            ]);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')->andReturn('msg_123');

        // Grant for first appointment
        $this->postJson('/api/miniapp/vk-permission', [
            'appointment_id' => $appointment1->id,
            'granted' => true,
        ], $this->vkAuthHeaders('494075'))
            ->assertOk();

        // Client1 should have vk_messages_allowed set to true
        $client1->refresh();
        $this->assertTrue($client1->vk_messages_allowed);

        // Client2 is a different Client record — not affected
        $client2->refresh();
        $this->assertNull($client2->vk_messages_allowed);
    }

    // ═══════════════════════════════════════════════
    // §28 — VkApiClient: 901 throws typed exception
    // ═══════════════════════════════════════════════

    public function test_vk_api_client_throws_901_exception(): void
    {
        $client = $this->createClient();

        $vkApi = new VkApiClient();

        $this->expectException(VkMessagesNotAllowedException::class);

        // We need to mock Http to return error code 901
        \Illuminate\Support\Facades\Http::fake([
            'api.vk.com/*' => \Illuminate\Support\Facades\Http::response([
                'error' => [
                    'error_code' => 901,
                    'error_msg' => 'Can\'t send messages for users without permission',
                ],
            ]),
        ]);

        $vkApi->sendMessage($client->vk_id, 'test');
    }

    // ═══════════════════════════════════════════════
    // Consent status includes appointment_id
    // ═══════════════════════════════════════════════

    public function test_consent_status_includes_appointment_id(): void
    {
        $client = $this->createClient();
        $appointment = $this->createAppointment($client);

        $token = 'link_vk_test_' . uniqid();
        Cache::put(CacheKeys::VK_LINK_TOKEN . $token, $appointment->id, 900);

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->getJson('/api/miniapp/vk-consent/status?token=' . $token)
            ->assertOk()
            ->assertJsonFragment(['appointment_id' => $appointment->id]);
    }

    // ═══════════════════════════════════════════════
    // Migration schema
    // ═══════════════════════════════════════════════

    public function test_client_has_vk_messages_allowed_column(): void
    {
        $client = $this->createClient();
        $this->assertNull($client->vk_messages_allowed);

        $client->update(['vk_messages_allowed' => true]);
        $this->assertTrue($client->fresh()->vk_messages_allowed);

        $client->update(['vk_messages_allowed' => false]);
        $this->assertFalse($client->fresh()->vk_messages_allowed);
    }

    public function test_appointment_has_vk_confirmation_sent_at_column(): void
    {
        $client = $this->createClient();
        $appointment = $this->createAppointment($client);
        $this->assertNull($appointment->vk_confirmation_sent_at);

        $appointment->update(['vk_confirmation_sent_at' => now()]);
        $this->assertNotNull($appointment->fresh()->vk_confirmation_sent_at);
    }
}
