<?php

namespace Tests\Feature\Notifications;

use App\Constants\CacheKeys;
use App\Enums\AppointmentNotificationEventType;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Exceptions\VkMessagesNotAllowedException;
use App\Jobs\SendAppointmentNotificationJob;
use App\Models\Appointment;
use App\Models\AppointmentNotificationDelivery;
use App\Models\AppointmentNotificationEvent;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use App\Services\MaxApiClient;
use App\Services\Notification\AppointmentChannelResolver;
use App\Services\Notification\AppointmentNotificationRecorder;
use App\Services\VkApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * R01: подтверждение брони, созданное через VkLink/VkConfirm (payload
 * vk_only), доставляется ТОЛЬКО в ВК — во всех точках выбора канала.
 *
 * Тесты гоняют действующий outbox/job-пайплайн: фейкается только
 * диспатч очереди, сам job выполняется явно с подменёнными API.
 */
class VkBookingConfirmationChannelPinningTest extends TestCase
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

        // Outbox-dispatch не должен выполнять job в sync: тесты запускают
        // handle() явно с подменёнными API.
        Queue::fake();

        $this->master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow'],
            'address' => 'ул. Тестовая, 1',
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
        $token = 'link_vk_r01_'.uniqid();
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

    private function vkAppointment(Client $client, array $attrs = []): Appointment
    {
        return Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client)
            ->withMasterService($this->masterService)
            ->create(array_merge([
                'status' => AppointmentStatus::Booked,
                'source' => AppointmentSource::Vk,
                'start_time' => Carbon::tomorrow()->setTime(14, 0),
                'duration' => 60,
                'price' => 1500,
            ], $attrs));
    }

    /**
     * Подтверждение брони через ВК-вход — как VkLink/VkConfirm:
     * BookingConfirmed c payload vk_only.
     */
    private function vkConfirmedByVkEntry(
        Client $client,
        array $payloadOverrides = [],
    ): array {
        $appointment = $this->vkAppointment($client);

        $recorder = app(AppointmentNotificationRecorder::class);
        $version = $recorder->bumpVersion($appointment);
        $event = $recorder->record(
            $appointment->fresh(),
            AppointmentNotificationEventType::BookingConfirmed,
            array_merge([AppointmentChannelResolver::PAYLOAD_VK_ONLY => true], $payloadOverrides),
            $version,
        );
        $this->assertNotNull($event);

        return [$appointment, $event];
    }

    /** Подтверждение брони через MAX/прочие пути — без vk_only. */
    private function bookingConfirmedGeneral(
        Client $client,
        array $attrs = [],
        array $payloadOverrides = [],
    ): array {
        $appointment = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client)
            ->withMasterService($this->masterService)
            ->create(array_merge([
                'status' => AppointmentStatus::Booked,
                'source' => AppointmentSource::Admin,
                'start_time' => Carbon::tomorrow()->setTime(15, 0),
            ], $attrs));

        $recorder = app(AppointmentNotificationRecorder::class);
        $version = $recorder->bumpVersion($appointment);
        $event = $recorder->record(
            $appointment->fresh(),
            AppointmentNotificationEventType::BookingConfirmed,
            $payloadOverrides,
            $version,
        );
        $this->assertNotNull($event);

        return [$appointment, $event];
    }

    private function runJob(
        SendAppointmentNotificationJob $job,
        ?MaxApiClient $maxApi = null,
        ?VkApiClient $vkApi = null,
    ): void {
        $job->handle(
            app(AppointmentChannelResolver::class),
            $maxApi ?? $this->mock(MaxApiClient::class),
            $vkApi ?? $this->mock(VkApiClient::class),
        );
    }

    private function delivery(
        AppointmentNotificationEvent $event,
        string $channel,
    ): ?AppointmentNotificationDelivery {
        return AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->getKey())
            ->where('channel', $channel)
            ->first();
    }

    // ── 1. Входы VkLink / VkConfirm: событие vk_only ──

    public function test_vk_confirm_entry_records_vk_only_and_delivers_vk_only_after_grant(): void
    {
        $client = $this->client(['vk_messages_allowed' => false]);
        $draft = Appointment::factory()
            ->forMaster($this->master)
            ->withMasterService($this->masterService)
            ->create([
                'client_id' => null,
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(14, 0),
            ]);
        $token = $this->createToken($draft->id);

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-confirm', ['token' => $token])
            ->assertOk();

        $event = AppointmentNotificationEvent::query()
            ->where('appointment_id', $draft->id)
            ->where('event_type', AppointmentNotificationEventType::BookingConfirmed)
            ->firstOrFail();
        $this->assertTrue($event->payload[AppointmentChannelResolver::PAYLOAD_VK_ONLY] ?? false);
        Queue::assertPushed(SendAppointmentNotificationJob::class);

        // false: ни ВК, ни MAX.
        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldNotReceive('sendMessage');
        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldNotReceive('sendMessage');
        $vkApi->shouldNotReceive('sendMessageWithKeyboard');
        $this->runJob(new SendAppointmentNotificationJob($event->id), $maxApi, $vkApi);

        $this->assertSame(
            AppointmentNotificationDelivery::STATUS_NO_CHANNEL,
            $this->delivery($event, 'vk')?->status,
        );

        // granted=true: одна доставка в ВК, MAX не вызывается.
        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-permission', [
                'appointment_id' => $draft->id,
                'granted' => true,
            ])
            ->assertOk();

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->with('494075', \Mockery::type('string'), \Mockery::type('array'))
            ->andReturn('msg-vk-1');
        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldNotReceive('sendMessage');
        $this->runJob(new SendAppointmentNotificationJob($event->id), $maxApi, $vkApi);

        $this->assertSame(
            AppointmentNotificationDelivery::STATUS_DELIVERED,
            $this->delivery($event, 'vk')?->status,
        );
        $this->assertNotSame(
            AppointmentNotificationDelivery::STATUS_DELIVERED,
            $this->delivery($event, 'max')?->status,
        );

        // Повтор job/permission — без дубля.
        $vkApi2 = $this->mock(VkApiClient::class);
        $vkApi2->shouldNotReceive('sendMessage');
        $vkApi2->shouldNotReceive('sendMessageWithKeyboard');
        $this->runJob(new SendAppointmentNotificationJob($event->id), $this->mock(MaxApiClient::class), $vkApi2);

        $pushedBefore = Queue::pushed(SendAppointmentNotificationJob::class)->count();
        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-permission', [
                'appointment_id' => $draft->id,
                'granted' => true,
            ])
            ->assertOk();
        $this->assertSame(
            $pushedBefore,
            Queue::pushed(SendAppointmentNotificationJob::class)->count(),
            'Повторный granted=true не должен переставлять job',
        );
    }

    public function test_vk_link_entry_records_vk_only_and_false_never_reaches_max(): void
    {
        $phone = '79001234567';
        $client = $this->client([
            'phone' => $phone,
            'vk_messages_allowed' => false,
        ]);
        $draft = Appointment::factory()
            ->forMaster($this->master)
            ->withMasterService($this->masterService)
            ->create([
                'client_id' => null,
                'status' => AppointmentStatus::Booked,
                'start_time' => Carbon::tomorrow()->setTime(16, 0),
            ]);
        $token = $this->createToken($draft->id);
        $rawPhone = $phone;
        $sign = rtrim(strtr(base64_encode(hash(
            'sha256',
            $this->testAppId.$this->testAppSecret.'494075'.'phone_number'.$rawPhone,
            true,
        )), '+/', '-_'), '=');

        Cache::put(
            CacheKeys::VK_CONSENT_PENDING.'494075',
            'v1',
            900,
        );

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/link', [
                'token' => $token,
                'phone_number' => $rawPhone,
                'sign' => $sign,
            ])
            ->assertOk();

        $event = AppointmentNotificationEvent::query()
            ->where('appointment_id', $draft->id)
            ->where('event_type', AppointmentNotificationEventType::BookingConfirmed)
            ->firstOrFail();
        $this->assertTrue($event->payload[AppointmentChannelResolver::PAYLOAD_VK_ONLY] ?? false);

        // Клиент с MAX и запретом ВК: подтверждение не уходит в MAX.
        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldNotReceive('sendMessage');
        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldNotReceive('sendMessage');
        $vkApi->shouldNotReceive('sendMessageWithKeyboard');
        $this->runJob(new SendAppointmentNotificationJob($event->id), $maxApi, $vkApi);

        $this->assertNull($this->delivery($event, 'max')?->delivered_at);
        $this->assertSame(
            AppointmentNotificationDelivery::STATUS_NO_CHANNEL,
            $this->delivery($event, 'vk')?->status,
        );

        // Разрешение восстанавливает ровно одну ВК-доставку.
        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-permission', [
                'appointment_id' => $draft->id,
                'granted' => true,
            ])
            ->assertOk();

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')->once()->andReturn('msg-vk-link');
        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldNotReceive('sendMessage');
        $this->runJob(new SendAppointmentNotificationJob($event->id), $maxApi, $vkApi);

        $this->assertSame(
            AppointmentNotificationDelivery::STATUS_DELIVERED,
            $this->delivery($event, 'vk')?->status,
        );
    }

    // ── 2. Resolver: пиннинг по событию ──

    public function test_resolver_pins_vk_only_booking_confirmed_to_vk_states(): void
    {
        $resolver = app(AppointmentChannelResolver::class);
        $client = $this->client(['vk_messages_allowed' => null]);
        $appointment = $this->vkAppointment($client);
        $event = AppointmentNotificationEvent::create([
            'appointment_id' => $appointment->id,
            'event_type' => AppointmentNotificationEventType::BookingConfirmed,
            'event_version' => (int) $appointment->notification_version,
            'payload' => [
                'client_id' => $client->id,
                AppointmentChannelResolver::PAYLOAD_VK_ONLY => true,
            ],
        ]);

        $this->assertSame(AppointmentChannelResolver::CHANNEL_VK, $resolver->resolveForEvent($event, $appointment));

        $client->update(['vk_messages_allowed' => false]);
        $this->assertNull($resolver->resolveForEvent($event->fresh(), $appointment->fresh()));

        $client->update(['vk_messages_allowed' => true]);
        $this->assertSame(AppointmentChannelResolver::CHANNEL_VK, $resolver->resolveForEvent($event->fresh(), $appointment->fresh()));
    }

    public function test_resolver_keeps_general_fallback_for_other_booking_confirmed(): void
    {
        $resolver = app(AppointmentChannelResolver::class);
        $client = $this->client(['vk_messages_allowed' => false]);
        $appointment = $this->vkAppointment($client);
        $event = AppointmentNotificationEvent::create([
            'appointment_id' => $appointment->id,
            'event_type' => AppointmentNotificationEventType::BookingConfirmed,
            'event_version' => (int) $appointment->notification_version,
            'payload' => ['client_id' => $client->id],
        ]);

        // Без маркера vk_only — общий fallback: привязанный MAX.
        $this->assertSame(AppointmentChannelResolver::CHANNEL_MAX, $resolver->resolveForEvent($event, $appointment));
    }

    // ── 3. false → нет отправки; null → ВК-попытка; 901 → без MAX ──

    public function test_vk_only_with_false_records_no_channel_without_any_http(): void
    {
        $client = $this->client(['vk_messages_allowed' => false]);
        [$appointment, $event] = $this->vkConfirmedByVkEntry($client);

        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldNotReceive('sendMessage');
        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldNotReceive('sendMessage');
        $vkApi->shouldNotReceive('sendMessageWithKeyboard');

        $this->runJob(new SendAppointmentNotificationJob($event->id), $maxApi, $vkApi);

        $this->assertSame(
            AppointmentNotificationDelivery::STATUS_NO_CHANNEL,
            $this->delivery($event, 'vk')?->status,
        );
        $this->assertSame(
            AppointmentNotificationDelivery::STATUS_NO_CHANNEL,
            $this->delivery($event, 'max')?->status,
        );
    }

    public function test_vk_only_with_null_attempts_vk_never_max(): void
    {
        $client = $this->client(['vk_messages_allowed' => null]);
        [$appointment, $event] = $this->vkConfirmedByVkEntry($client);

        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldNotReceive('sendMessage');
        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->with('494075', \Mockery::type('string'), \Mockery::type('array'))
            ->andReturn('msg-null-1');

        $this->runJob(new SendAppointmentNotificationJob($event->id), $maxApi, $vkApi);

        $vkRow = $this->delivery($event, 'vk');
        $this->assertSame(AppointmentNotificationDelivery::STATUS_DELIVERED, $vkRow?->status);
        $this->assertSame('msg-null-1', $vkRow?->provider_message_id);
        $this->assertNull($this->delivery($event, 'max')?->delivered_at);
        $this->assertTrue($client->fresh()->vk_messages_allowed);
    }

    public function test_after_901_job_retry_never_calls_max(): void
    {
        $client = $this->client(['vk_messages_allowed' => null]);
        [$appointment, $event] = $this->vkConfirmedByVkEntry($client);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->andThrow(new VkMessagesNotAllowedException);

        try {
            $this->runJob(
                new SendAppointmentNotificationJob($event->id),
                $this->mock(MaxApiClient::class),
                $vkApi,
            );
            $this->fail('Expected RuntimeException after 901');
        } catch (\RuntimeException) {
            // handle() терминализирует 901 исключением — bounded retry очереди
        }

        $this->assertFalse($client->fresh()->vk_messages_allowed);
        $vkRow = $this->delivery($event, 'vk');
        $this->assertSame(AppointmentNotificationDelivery::STATUS_FAILED, $vkRow?->status);
        $this->assertSame('vk_messages_not_allowed', $vkRow?->failure_reason);

        // Повторный запуск job: MAX не вызывается, ВК не повторяется.
        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldNotReceive('sendMessage');
        $vkApi2 = $this->mock(VkApiClient::class);
        $vkApi2->shouldNotReceive('sendMessage');
        $vkApi2->shouldNotReceive('sendMessageWithKeyboard');

        $this->runJob(new SendAppointmentNotificationJob($event->id), $maxApi, $vkApi2);

        $this->assertNotSame(
            AppointmentNotificationDelivery::STATUS_DELIVERED,
            $this->delivery($event, 'max')?->status,
        );
    }

    // ── 4. granted=true восстанавливает ВК-доставку той же идентичности ──

    public function test_granted_after_901_restores_vk_delivery_without_version_bump(): void
    {
        $client = $this->client(['vk_messages_allowed' => null]);
        [$appointment, $event] = $this->vkConfirmedByVkEntry($client);
        $versionBefore = (int) $event->event_version;

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->andThrow(new VkMessagesNotAllowedException);
        try {
            $this->runJob(new SendAppointmentNotificationJob($event->id), null, $vkApi);
        } catch (\RuntimeException) {
        }

        // Разрешение: тот же event key, без bump'а версии.
        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-permission', [
                'appointment_id' => $appointment->id,
                'granted' => true,
            ])
            ->assertOk();

        $event->refresh();
        $this->assertSame($versionBefore, (int) $event->event_version);
        $this->assertSame(
            AppointmentNotificationDelivery::STATUS_PENDING,
            $this->delivery($event, 'vk')?->status,
        );

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')->once()->andReturn('msg-restored');
        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldNotReceive('sendMessage');
        $this->runJob(new SendAppointmentNotificationJob($event->id), $maxApi, $vkApi);

        $this->assertSame(
            AppointmentNotificationDelivery::STATUS_DELIVERED,
            $this->delivery($event, 'vk')?->status,
        );

        // Успешно доставленное не повторять.
        $vkApi2 = $this->mock(VkApiClient::class);
        $vkApi2->shouldNotReceive('sendMessage');
        $vkApi2->shouldNotReceive('sendMessageWithKeyboard');
        $this->runJob(new SendAppointmentNotificationJob($event->id), $this->mock(MaxApiClient::class), $vkApi2);
    }

    public function test_parallel_permission_requests_do_not_duplicate(): void
    {
        $client = $this->client(['vk_messages_allowed' => false]);
        [$appointment, $event] = $this->vkConfirmedByVkEntry($client);

        $this->runJob(new SendAppointmentNotificationJob($event->id));

        // «Параллельный» повтор: первый retry сбросил no_channel→pending и
        // поставил job; второй retry (уже allowed=true) не находит
        // no_channel/failed строк — второй dispatch не рождается.
        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-permission', [
                'appointment_id' => $appointment->id,
                'granted' => true,
            ])
            ->assertOk();
        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-permission', [
                'appointment_id' => $appointment->id,
                'granted' => true,
            ])
            ->assertOk();

        // Оба «параллельных» запроса не дают второго redispatch'а:
        // исходный outbox-dispatch + один retry после granted.
        $this->assertSame(
            2,
            Queue::pushed(SendAppointmentNotificationJob::class)->count(),
            'Только исходный outbox-dispatch + один retry после granted',
        );

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')->once()->andReturn('msg-once');
        $this->runJob(new SendAppointmentNotificationJob($event->id), null, $vkApi);

        $this->assertSame(
            1,
            AppointmentNotificationDelivery::query()
                ->where('appointment_notification_event_id', $event->id)
                ->where('status', AppointmentNotificationDelivery::STATUS_DELIVERED)
                ->count(),
        );
    }

    public function test_granted_does_not_delete_or_touch_foreign_live_claim(): void
    {
        $client = $this->client(['vk_messages_allowed' => false]);
        [$appointment, $event] = $this->vkConfirmedByVkEntry($client);

        // Работающий job: живой claim на vk-строке.
        $liveToken = (string) Str::uuid();
        AppointmentNotificationDelivery::create([
            'appointment_notification_event_id' => $event->id,
            'channel' => 'vk',
            'status' => AppointmentNotificationDelivery::STATUS_PENDING,
            'claim_token' => $liveToken,
            'lease_expires_at' => now()->addSeconds(60),
        ]);

        $pushedBefore = Queue::pushed(SendAppointmentNotificationJob::class)->count();

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-permission', [
                'appointment_id' => $appointment->id,
                'granted' => true,
            ])
            ->assertOk();

        $row = $this->delivery($event, 'vk');
        $this->assertNotNull($row, 'Живой claim не должен удаляться permission-запросом');
        $this->assertSame(AppointmentNotificationDelivery::STATUS_PENDING, $row->status);
        $this->assertSame($liveToken, $row->claim_token);
        $this->assertNotNull($row->lease_expires_at);
        $this->assertSame(
            $pushedBefore,
            Queue::pushed(SendAppointmentNotificationJob::class)->count(),
            'Работающий job не должен получить параллельный дубль-dispatch',
        );
    }

    public function test_granted_resets_failed_row_cas_without_delete(): void
    {
        $client = $this->client(['vk_messages_allowed' => false]);
        [$appointment, $event] = $this->vkConfirmedByVkEntry($client);

        AppointmentNotificationDelivery::create([
            'appointment_notification_event_id' => $event->id,
            'channel' => 'vk',
            'status' => AppointmentNotificationDelivery::STATUS_FAILED,
            'failure_reason' => 'vk_messages_not_allowed',
            'failed_at' => now(),
        ]);

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-permission', [
                'appointment_id' => $appointment->id,
                'granted' => true,
            ])
            ->assertOk();

        $row = $this->delivery($event, 'vk');
        $this->assertNotNull($row, 'CAS-сброс не должен удалять строку');
        $this->assertSame(AppointmentNotificationDelivery::STATUS_PENDING, $row->status);
        $this->assertNull($row->failure_reason);
        $this->assertNull($row->failed_at);
        $this->assertNull($row->claim_token);
    }

    // ── 4б. Гонки permission × job ──

    public function test_grant_racing_no_channel_write_recovers_on_repeat_granted(): void
    {
        $client = $this->client(['vk_messages_allowed' => false]);
        [$appointment, $event] = $this->vkConfirmedByVkEntry($client);

        // Исходный outbox-dispatch уже в очереди.
        $original = Queue::pushed(SendAppointmentNotificationJob::class)->first();
        $this->assertNotNull($original);

        // Пересечение: granted=true попал между resolve(job)=false и
        // записью no_channel — retry не нашёл delivery-строк, продолжение
        // не поставлено. Разрешение при этом сохранено.
        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-permission', [
                'appointment_id' => $appointment->id,
                'granted' => true,
            ])
            ->assertOk();
        $this->assertTrue($client->fresh()->vk_messages_allowed);
        $this->assertSame(
            1,
            Queue::pushed(SendAppointmentNotificationJob::class)->count(),
            'Retry без delivery-строк не ставит продолжение',
        );

        // Исходный job завершился по устаревшему снимку false → no_channel;
        // разрешение grant'а остаётся true в БД. Query-builder update:
        // Eloquent-update по не-dirty false не тронул бы строку.
        Client::query()
            ->whereKey($client->getKey())
            ->update(['vk_messages_allowed' => false]);
        $staleMax = $this->mock(MaxApiClient::class);
        $staleMax->shouldNotReceive('sendMessage');
        $staleVk = $this->mock(VkApiClient::class);
        $staleVk->shouldNotReceive('sendMessage');
        $staleVk->shouldNotReceive('sendMessageWithKeyboard');
        $this->runJob($original, $staleMax, $staleVk);
        $this->assertSame(
            AppointmentNotificationDelivery::STATUS_NO_CHANNEL,
            $this->delivery($event, 'vk')?->status,
        );
        Client::query()
            ->whereKey($client->getKey())
            ->update(['vk_messages_allowed' => true]);

        // Повтор granted=true (перехода false→true больше не было) обязан
        // восстановить: CAS-сброс + постановка продолжения через очередь.
        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-permission', [
                'appointment_id' => $appointment->id,
                'granted' => true,
            ])
            ->assertOk();

        $this->assertSame(
            2,
            Queue::pushed(SendAppointmentNotificationJob::class)->count(),
            'Повтор granted=true должен переставить job той же идентичности',
        );

        // Итоговая доставка — фактическим продолжением из очереди.
        $continuation = Queue::pushed(SendAppointmentNotificationJob::class)->last();
        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->with('494075', \Mockery::type('string'), \Mockery::type('array'))
            ->andReturn('msg-race-recovered');
        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldNotReceive('sendMessage');
        $this->runJob($continuation, $maxApi, $vkApi);

        $this->assertSame(
            AppointmentNotificationDelivery::STATUS_DELIVERED,
            $this->delivery($event, 'vk')?->status,
        );
        $this->assertNotSame(
            AppointmentNotificationDelivery::STATUS_DELIVERED,
            $this->delivery($event, 'max')?->status,
        );
    }

    public function test_late_901_preserves_grant_and_repeat_granted_recovers(): void
    {
        $client = $this->client(['vk_messages_allowed' => null]);
        [$appointment, $event] = $this->vkConfirmedByVkEntry($client);

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->andReturnUsing(function () use ($appointment) {
                // granted=true приходит во время HTTP, начатого до разрешения.
                $this->withHeaders($this->vkAuthHeaders('494075'))
                    ->postJson('/api/miniapp/vk-permission', [
                        'appointment_id' => $appointment->id,
                        'granted' => true,
                    ])
                    ->assertOk();

                // Поздний 901 от этого (устаревшего по ВК) запроса.
                throw new VkMessagesNotAllowedException;
            });

        try {
            $this->runJob(
                Queue::pushed(SendAppointmentNotificationJob::class)->first(),
                null,
                $vkApi,
            );
            $this->fail('Expected RuntimeException after 901');
        } catch (\RuntimeException) {
            // handle() терминализирует 901 исключением — bounded retry очереди
        }

        // Более новое разрешение не затёрто поздним 901 (CAS).
        $this->assertTrue(
            $client->fresh()->vk_messages_allowed,
            'Поздний 901 не должен затирать granted=true во время HTTP',
        );
        $vkRow = $this->delivery($event, 'vk');
        $this->assertSame(AppointmentNotificationDelivery::STATUS_FAILED, $vkRow?->status);
        $this->assertSame('vk_messages_not_allowed', $vkRow?->failure_reason);

        // Повтор granted=true (allowed уже true) восстанавливает доставку.
        $pushedBefore = Queue::pushed(SendAppointmentNotificationJob::class)->count();
        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-permission', [
                'appointment_id' => $appointment->id,
                'granted' => true,
            ])
            ->assertOk();
        $this->assertSame(
            $pushedBefore + 1,
            Queue::pushed(SendAppointmentNotificationJob::class)->count(),
            'Повтор granted=true после 901 должен переставить job',
        );
        $this->assertSame(
            AppointmentNotificationDelivery::STATUS_PENDING,
            $this->delivery($event, 'vk')?->status,
        );

        // Итоговая доставка — фактическим продолжением из очереди.
        $continuation = Queue::pushed(SendAppointmentNotificationJob::class)->last();
        $vkApi2 = $this->mock(VkApiClient::class);
        $vkApi2->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->andReturn('msg-after-late-901');
        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldNotReceive('sendMessage');
        $this->runJob($continuation, $maxApi, $vkApi2);

        $this->assertSame(
            AppointmentNotificationDelivery::STATUS_DELIVERED,
            $this->delivery($event, 'vk')?->status,
        );
        $this->assertTrue($client->fresh()->vk_messages_allowed);
    }

    // ── 5. Отказ / «Позже» / закрытие окна ──

    public function test_refusal_does_not_dispatch_or_change_deliveries(): void
    {
        $client = $this->client(['vk_messages_allowed' => false]);
        [$appointment, $event] = $this->vkConfirmedByVkEntry($client);
        $this->runJob(new SendAppointmentNotificationJob($event->id));

        $pushedBefore = Queue::pushed(SendAppointmentNotificationJob::class)->count();

        // «Отказ»/«Позже» — granted=false; закрытие окна — вообще без
        // запроса. Ни то ни другое не запускает MAX-подтверждение.
        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-permission', [
                'appointment_id' => $appointment->id,
                'granted' => false,
            ])
            ->assertOk();

        $this->assertFalse($client->fresh()->vk_messages_allowed);
        $this->assertSame(
            $pushedBefore,
            Queue::pushed(SendAppointmentNotificationJob::class)->count(),
        );
        $this->assertSame(
            AppointmentNotificationDelivery::STATUS_NO_CHANNEL,
            $this->delivery($event, 'vk')?->status,
        );

        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldNotReceive('sendMessage');
        $this->runJob(new SendAppointmentNotificationJob($event->id), $maxApi, $this->mock(VkApiClient::class));
    }

    public function test_granted_false_does_not_revoke_saved_true(): void
    {
        $client = $this->client(['vk_messages_allowed' => true]);
        $appointment = $this->vkAppointment($client);

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-permission', [
                'appointment_id' => $appointment->id,
                'granted' => false,
            ])
            ->assertOk();

        $this->assertTrue($client->fresh()->vk_messages_allowed);
    }

    // ── 6. Permission до первого запуска job ──

    public function test_permission_before_first_job_run_delivers_vk_once(): void
    {
        $client = $this->client(['vk_messages_allowed' => false]);
        [$appointment, $event] = $this->vkConfirmedByVkEntry($client);

        $this->withHeaders($this->vkAuthHeaders('494075'))
            ->postJson('/api/miniapp/vk-permission', [
                'appointment_id' => $appointment->id,
                'granted' => true,
            ])
            ->assertOk();

        // Нет ещё delivery-строк — retry ничего не сбрасывает; первый
        // запуск job сам резолвит ВК.
        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')->once()->andReturn('msg-first');
        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldNotReceive('sendMessage');
        $this->runJob(new SendAppointmentNotificationJob($event->id), $maxApi, $vkApi);

        $this->assertSame(
            AppointmentNotificationDelivery::STATUS_DELIVERED,
            $this->delivery($event, 'vk')?->status,
        );
    }

    // ── 7. Устаревшее событие / чужой клиент ──

    public function test_stale_vk_only_event_is_not_delivered(): void
    {
        $client = $this->client(['vk_messages_allowed' => true]);
        [$appointment, $event] = $this->vkConfirmedByVkEntry($client);

        app(AppointmentNotificationRecorder::class)->bumpVersion($appointment);

        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldNotReceive('sendMessage');
        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldNotReceive('sendMessage');
        $vkApi->shouldNotReceive('sendMessageWithKeyboard');

        $this->runJob(new SendAppointmentNotificationJob($event->id), $maxApi, $vkApi);

        $this->assertNotSame(
            AppointmentNotificationDelivery::STATUS_DELIVERED,
            $this->delivery($event, 'vk')?->status,
        );
    }

    public function test_foreign_recipient_vk_only_event_is_not_delivered(): void
    {
        $client = $this->client(['vk_messages_allowed' => true]);
        [$appointment, $event] = $this->vkConfirmedByVkEntry($client);

        $newClient = $this->client(['vk_id' => '555', 'max_id' => 'max-other']);
        $appointment->update(['client_id' => $newClient->id]);

        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldNotReceive('sendMessage');
        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldNotReceive('sendMessage');
        $vkApi->shouldNotReceive('sendMessageWithKeyboard');

        $this->runJob(new SendAppointmentNotificationJob($event->id), $maxApi, $vkApi);

        $this->assertNotSame(
            AppointmentNotificationDelivery::STATUS_DELIVERED,
            $this->delivery($event, 'vk')?->status,
        );
    }

    // ── 8. Прочие события и MAX-подтверждение не тронуты ──

    public function test_general_event_still_falls_back_to_max(): void
    {
        $client = $this->client(['vk_messages_allowed' => false]);
        $appointment = $this->vkAppointment($client);

        $recorder = app(AppointmentNotificationRecorder::class);
        $version = $recorder->bumpVersion($appointment);
        $event = $recorder->record(
            $appointment->fresh(),
            AppointmentNotificationEventType::CreatedByMaster,
            [],
            $version,
        );
        $this->assertNotNull($event);

        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldReceive('sendMessage')->once()->with('max-1', \Mockery::type('string'))->andReturn('mid-1');

        $this->runJob(new SendAppointmentNotificationJob($event->id), $maxApi, $this->mock(VkApiClient::class));

        $this->assertSame(
            AppointmentNotificationDelivery::STATUS_DELIVERED,
            $this->delivery($event, 'max')?->status,
        );
    }

    public function test_max_booking_confirmed_still_delivered_to_max(): void
    {
        $client = $this->client(['vk_messages_allowed' => false]);
        [$appointment, $event] = $this->bookingConfirmedGeneral($client, [], [
            'max_source_message_id' => 'mid_src',
            'max_source_recipient_id' => 'max-1',
        ]);

        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldReceive('sendMessage')->once()->andReturn('mid-max-1');
        $maxApi->shouldReceive('deleteMessage')->once()->with('mid_src')->andReturn(true);
        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldNotReceive('sendMessage');
        $vkApi->shouldNotReceive('sendMessageWithKeyboard');

        $this->runJob(new SendAppointmentNotificationJob($event->id), $maxApi, $vkApi);

        $this->assertSame(
            AppointmentNotificationDelivery::STATUS_DELIVERED,
            $this->delivery($event, 'max')?->status,
        );
    }

    public function test_max_booking_confirmed_falls_back_to_vk_when_max_unreachable(): void
    {
        $client = $this->client(['max_id' => null, 'vk_messages_allowed' => true]);
        [$appointment, $event] = $this->bookingConfirmedGeneral($client);

        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldNotReceive('sendMessage');
        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')->once()->andReturn('msg-fb');

        $this->runJob(new SendAppointmentNotificationJob($event->id), $maxApi, $vkApi);

        $this->assertSame(
            AppointmentNotificationDelivery::STATUS_DELIVERED,
            $this->delivery($event, 'vk')?->status,
        );
    }
}
