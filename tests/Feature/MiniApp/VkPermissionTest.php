<?php

namespace Tests\Feature\MiniApp;

use App\Constants\CacheKeys;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Events\AppointmentCreated;
use App\Exceptions\VkMessagesNotAllowedException;
use App\Jobs\SendAppointmentNotificationJob;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
            $parts[] = $key.'='.$value;
        }
        $canonical = implode('&', $parts);
        $hmac = hash_hmac('sha256', $canonical, $this->testAppSecret, true);
        $sign = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($hmac));
        $all = array_merge($vkParams, ['sign' => $sign]);

        return ['Authorization' => 'Bearer '.http_build_query($all)];
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

    public function test_granted_true_without_undelivered_rows_does_not_dispatch(): void
    {
        // Единственный механизм доставки — outbox-job: permission больше
        // не шлёт AppointmentCreated для «запуска» старого listener'а.
        Queue::fake();
        Event::fake([AppointmentCreated::class]);

        $client = $this->createClient();
        $appointment = $this->createAppointment($client);

        $this->postJson('/api/miniapp/vk-permission', [
            'appointment_id' => $appointment->id,
            'granted' => true,
        ], $this->vkAuthHeaders('494075'))
            ->assertOk();

        $this->assertTrue($client->fresh()->vk_messages_allowed);
        Queue::assertNotPushed(SendAppointmentNotificationJob::class);
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
    // §25 — AppointmentCreated больше не шлёт подтверждение:
    // единственный механизм — outbox-job (см. VkBookingConfirmationTest,
    // VkBookingConfirmationChannelPinningTest)
    // ═══════════════════════════════════════════════

    public function test_appointment_created_does_not_send_vk_confirmation(): void
    {
        // allowed=true и null — раньше listener слал подтверждение по
        // broadcast(AppointmentCreated) параллельно outbox. Теперь — никогда.
        foreach ([[true, 14], [null, 16]] as [$allowed, $hour]) {
            $client = $this->createClient();
            $client->update(['vk_messages_allowed' => $allowed]);
            $appointment = Appointment::factory()
                ->forMaster($this->master)->forClient($client)->withMasterService($this->masterService)
                ->create([
                    'status' => AppointmentStatus::Booked,
                    'source' => AppointmentSource::Vk,
                    'start_time' => Carbon::tomorrow()->setTime($hour, 0),
                    'duration' => 60,
                    'price' => 1500,
                ]);

            $vkApi = $this->mock(VkApiClient::class);
            $vkApi->shouldNotReceive('sendMessageWithKeyboard');
            $vkApi->shouldNotReceive('sendMessage');

            broadcast(new AppointmentCreated($appointment->load(['client'])));
        }
    }

    // ═══════════════════════════════════════════════
    // §28 — Permission endpoint + retry-семантика
    // ═══════════════════════════════════════════════

    public function test_permission_true_without_outbox_event_does_not_dispatch(): void
    {
        Queue::fake();

        $client = $this->createClient();
        $appointment = $this->createAppointment($client);

        // granted=true: флаг ставится; без недоставленного BookingConfirmed
        // продолжение не ставится (retry идемпотентен).
        $this->postJson('/api/miniapp/vk-permission', [
            'appointment_id' => $appointment->id,
            'granted' => true,
        ], $this->vkAuthHeaders('494075'))
            ->assertOk();

        $this->assertTrue($client->fresh()->vk_messages_allowed);
        Queue::assertNotPushed(SendAppointmentNotificationJob::class);
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

        $vkApi = new VkApiClient;

        $this->expectException(VkMessagesNotAllowedException::class);

        // We need to mock Http to return error code 901
        Http::fake([
            'api.vk.com/*' => Http::response([
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

        $token = 'link_vk_test_'.uniqid();
        Cache::put(CacheKeys::VK_LINK_TOKEN.$token, $appointment->id, 900);

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->getJson('/api/miniapp/vk-consent/status?token='.$token)
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
