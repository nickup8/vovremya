<?php

namespace Tests\Feature;

use App\Constants\CacheKeys;
use App\Enums\AppointmentNotificationEventType;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Events\AppointmentCreated;
use App\Listeners\SendVkBookingConfirmation;
use App\Models\Appointment;
use App\Models\AppointmentNotificationDelivery;
use App\Models\AppointmentNotificationEvent;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use App\Services\MaxApiClient;
use App\Services\VkApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Подтверждение брони через ВК-вход доставляется ТОЛЬКО через outbox-job.
 *
 * Старый listener SendVkBookingConfirmation удалён: автообнаружение Laravel
 * больше не регистрирует его на AppointmentCreated, broadcast не шлёт
 * ВК-сообщения. Тесты гоняют настоящую регистрацию обработчиков и
 * sync-очередь (QUEUE_CONNECTION=sync, без Queue::fake): outbox-job уходит
 * через DB::afterCommit инлайн. Подменены только внешние API — ровно один
 * вызов отправки ВК доказывает отсутствие параллельной отправки.
 */
class VkBookingConfirmationTest extends TestCase
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

        config([
            'services.max.api_url' => 'https://api.max.example',
            'services.max.bot_token' => 'test_max_token',
            'services.vk.app_id' => $this->testAppId,
            'services.vk.app_secret' => $this->testAppSecret,
            'services.vk.group_id' => '123456',
            'services.vk.bot_token' => 'test_bot_token',
            'legal.version' => 'v1',
        ]);

        $this->master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow'],
            'address' => 'ул. Пушкина, 10',
        ]);
        $this->ws = Workspace::create(['name' => 'WS', 'owner_id' => $this->master->id]);
        $this->master->update(['workspace_id' => $this->ws->id]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $this->ws->id,
            'title' => 'Массаж',
            'base_price' => 2000,
            'base_duration' => 60,
        ]);
        $this->masterService = MasterService::create([
            'master_id' => $this->master->id,
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);
    }

    // ── Хелперы ──

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

    private function createToken(string $appointmentId): string
    {
        $token = 'link_vk_single_'.uniqid();
        Cache::put(CacheKeys::VK_LINK_TOKEN.$token, $appointmentId, 900);

        return $token;
    }

    private function client(array $attrs = []): Client
    {
        return Client::factory()->create(array_merge([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'vk_id' => '494075',
            'max_id' => 'max-1',
            'pdn_consent_at' => now(),
            'pdn_consent_version' => 'v1',
        ], $attrs));
    }

    private function draftAppointment(): Appointment
    {
        return Appointment::factory()
            ->forMaster($this->master)
            ->withMasterService($this->masterService)
            ->create([
                'client_id' => null,
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(14, 0),
                'duration' => 60,
                'price' => 1500,
            ]);
    }

    private function vkDeliveredRow(Appointment $appointment): ?AppointmentNotificationDelivery
    {
        $event = AppointmentNotificationEvent::query()
            ->where('appointment_id', $appointment->getKey())
            ->where('event_type', AppointmentNotificationEventType::BookingConfirmed)
            ->first();

        if ($event === null) {
            return null;
        }

        return AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->getKey())
            ->where('channel', 'vk')
            ->first();
    }

    // ── Listener удалён: параллельной отправки нет ──

    public function test_listener_class_is_removed(): void
    {
        $this->assertFalse(
            class_exists(SendVkBookingConfirmation::class),
            'Старый listener должен быть удалён — outbox единственный механизм',
        );
    }

    public function test_appointment_created_broadcast_does_not_send_vk(): void
    {
        // allowed=true и VK-источник: при живом listener broadcast слал бы
        // второе подтверждение параллельно outbox-job.
        $client = $this->client(['vk_messages_allowed' => true]);
        $appointment = Appointment::factory()
            ->forMaster($this->master)->forClient($client)->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'source' => AppointmentSource::Vk,
                'start_time' => Carbon::tomorrow()->setTime(14, 0),
            ]);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldNotReceive('sendMessageWithKeyboard');
        $vkApi->shouldNotReceive('sendMessage');

        broadcast(new AppointmentCreated($appointment->load(['client'])));
    }

    // ── HTTP-входы: ровно одна ВК-отправка через outbox ──

    public function test_vk_confirm_with_allowed_true_sends_vk_exactly_once(): void
    {
        $client = $this->client(['vk_messages_allowed' => true]);
        $draft = $this->draftAppointment();
        $token = $this->createToken($draft->id);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->with('494075', \Mockery::type('string'), \Mockery::type('array'))
            ->andReturn('msg-once-confirm');
        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldNotReceive('sendMessage');

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-confirm', ['token' => $token])
            ->assertOk();

        // Mockery::once() в tearDown доказывает ровно один вызов: второй
        // (параллельный listener или MAX-fallback) обвалил бы тест.
        $row = $this->vkDeliveredRow($draft);
        $this->assertSame(AppointmentNotificationDelivery::STATUS_DELIVERED, $row?->status);
        $this->assertSame('msg-once-confirm', $row?->provider_message_id);
        $this->assertTrue($client->fresh()->vk_messages_allowed);
    }

    public function test_vk_link_with_allowed_true_sends_vk_exactly_once(): void
    {
        $phone = '79001234567';
        $client = $this->client([
            'phone' => $phone,
            'vk_messages_allowed' => true,
        ]);
        $draft = $this->draftAppointment();
        $token = $this->createToken($draft->id);
        $sign = rtrim(strtr(base64_encode(hash(
            'sha256',
            $this->testAppId.$this->testAppSecret.'494075'.'phone_number'.$phone,
            true,
        )), '+/', '-_'), '=');

        Cache::put(CacheKeys::VK_CONSENT_PENDING.'494075', 'v1', 900);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->with('494075', \Mockery::type('string'), \Mockery::type('array'))
            ->andReturn('msg-once-link');
        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldNotReceive('sendMessage');

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $phone,
                'sign' => $sign,
            ])
            ->assertOk();

        $row = $this->vkDeliveredRow($draft);
        $this->assertSame(AppointmentNotificationDelivery::STATUS_DELIVERED, $row?->status);
        $this->assertSame('msg-once-link', $row?->provider_message_id);
    }

    public function test_vk_confirm_with_allowed_false_sends_nothing(): void
    {
        $client = $this->client(['vk_messages_allowed' => false]);
        $draft = $this->draftAppointment();
        $token = $this->createToken($draft->id);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldNotReceive('sendMessageWithKeyboard');
        $vkApi->shouldNotReceive('sendMessage');
        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldNotReceive('sendMessage');

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-confirm', ['token' => $token])
            ->assertOk();

        $this->assertSame(
            AppointmentNotificationDelivery::STATUS_NO_CHANNEL,
            $this->vkDeliveredRow($draft)?->status,
        );
        $this->assertFalse($client->fresh()->vk_messages_allowed);
    }

    // ── Содержимое доставки через outbox-job ──

    public function test_outbox_delivery_contains_text_and_open_app_keyboard(): void
    {
        $this->client(['vk_messages_allowed' => true]);
        $draft = $this->draftAppointment();
        $token = $this->createToken($draft->id);

        $capturedText = null;
        $capturedKeyboard = null;

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->with('494075', \Mockery::on(function ($text) use (&$capturedText) {
                $capturedText = $text;

                return true;
            }), \Mockery::on(function ($kb) use (&$capturedKeyboard) {
                $capturedKeyboard = $kb;

                return true;
            }))
            ->andReturn('msg-content');

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-confirm', ['token' => $token])
            ->assertOk();

        $this->assertNotNull($capturedText);
        $this->assertStringContainsString('Запись подтверждена!', $capturedText);
        $this->assertStringContainsString($draft->display_name, $capturedText);
        $this->assertStringContainsString(
            Carbon::tomorrow()->setTime(14, 0)->timezone('Europe/Moscow')->format('d.m.Y'),
            $capturedText,
        );
        $this->assertStringContainsString(
            Carbon::tomorrow()->setTime(14, 0)->timezone('Europe/Moscow')->format('H:i'),
            $capturedText,
        );
        $this->assertStringContainsString('1500₽', $capturedText);
        $this->assertStringContainsString('Пушкина', $capturedText);
        $this->assertStringContainsString('Ждём вас!', $capturedText);

        $this->assertNotNull($capturedKeyboard);
        $this->assertFalse($capturedKeyboard['one_time']);
        $this->assertTrue($capturedKeyboard['inline']);
        $this->assertSame('open_app', $capturedKeyboard['buttons'][0][0]['action']['type']);
        $this->assertSame(6736218, $capturedKeyboard['buttons'][0][0]['action']['app_id']);
        $this->assertSame(-123456, $capturedKeyboard['buttons'][0][0]['action']['owner_id']);
        $this->assertSame('📅 Открыть ИРСИ', $capturedKeyboard['buttons'][0][0]['action']['label']);
    }
}
