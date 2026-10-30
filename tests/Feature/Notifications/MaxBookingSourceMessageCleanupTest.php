<?php

namespace Tests\Feature\Notifications;

use App\Constants\CacheKeys;
use App\Enums\AppointmentNotificationEventType;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Jobs\SendAppointmentNotificationJob;
use App\Models\Appointment;
use App\Models\AppointmentNotificationDelivery;
use App\Models\AppointmentNotificationEvent;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\User;
use App\Models\Workspace;
use App\Services\MaxApiClient;
use App\Services\Notification\AppointmentChannelResolver;
use App\Services\Notification\AppointmentNotificationRecorder;
use App\Services\VkApiClient;
use App\Webhooks\MaxWebhookHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/**
 * R02: после успешной доставки BookingConfirmed в MAX исходное сообщение
 * бота с кнопками удаляется. Ошибка удаления не влияет на delivery.
 */
class MaxBookingSourceMessageCleanupTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    private MasterService $service;

    private Workspace $workspace;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.max.api_url' => 'https://api.max.example',
            'services.max.bot_token' => 'test_max_token',
            'services.vk.bot_token' => 'test_vk_token',
            'services.vk.group_id' => '1',
            'services.vk.app_id' => '1',
            'legal.version' => 'v1',
        ]);

        Queue::fake();

        $this->master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow'],
        ]);
        $this->workspace = Workspace::create([
            'name' => 'ws-'.$this->master->id,
            'owner_id' => $this->master->id,
        ]);
        $this->master->update(['workspace_id' => $this->workspace->id]);
        $this->service = MasterService::factory()->forMaster($this->master)->create([
            'price_override' => 1500,
            'duration_override' => 60,
        ]);
        $this->client = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->workspace->id,
            'max_id' => 'max-1',
            'pdn_consent_at' => now(),
            'pdn_consent_version' => 'v1',
        ]);
    }

    private function appointment(array $attrs = [], ?Client $client = null): Appointment
    {
        return Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client ?? $this->client)
            ->withMasterService($this->service)
            ->create(array_merge([
                'status' => AppointmentStatus::Booked,
                'source' => AppointmentSource::Admin,
                'start_time' => Carbon::now('Europe/Moscow')->addDays(3)->setTime(10, 0),
            ], $attrs));
    }

    private function bookingConfirmedEvent(
        Appointment $appointment,
        array $payloadOverrides = [],
    ): AppointmentNotificationEvent {
        $recorder = app(AppointmentNotificationRecorder::class);
        $version = $recorder->bumpVersion($appointment);

        $event = $recorder->record(
            $appointment->fresh(),
            AppointmentNotificationEventType::BookingConfirmed,
            $payloadOverrides,
            $version,
        );
        $this->assertNotNull($event);

        return $event;
    }

    private function runJob(
        SendAppointmentNotificationJob $job,
        ?MaxApiClient $maxApi = null,
        ?VkApiClient $vkApi = null,
    ): void {
        $job->handle(
            app(AppointmentChannelResolver::class),
            $maxApi ?? app(MaxApiClient::class),
            $vkApi ?? $this->mock(VkApiClient::class),
        );
    }

    // ── 1. message ID и получатель доходят из callback в событие ──

    public function test_callback_message_id_and_recipient_reach_event_payload(): void
    {
        $maxApi = Mockery::mock(MaxApiClient::class);
        $maxApi->shouldReceive('answerCallback')->once()->andReturn(true);
        $maxApi->shouldReceive('sendMessage')->zeroOrMoreTimes()->andReturn('mid_refusal');
        $this->app->instance(MaxApiClient::class, $maxApi);

        $handler = app(MaxWebhookHandler::class);

        $draft = Appointment::factory()
            ->forMaster($this->master)
            ->withMasterService($this->service)
            ->create([
                'client_id' => null,
                'status' => AppointmentStatus::Booked,
                'source' => null,
                'start_time' => Carbon::now('Europe/Moscow')->addDays(2)->setTime(14, 0),
            ]);

        Cache::put(
            CacheKeys::MAX_BOOKING_DRAFT.'max-1',
            (string) $draft->id,
            3600,
        );

        $handler->handle([
            'update_type' => 'message_callback',
            'callback' => [
                'callback_id' => 'cb_r02',
                'payload' => 'max_confirm_'.$draft->id,
                'user' => ['user_id' => 'max-1'],
            ],
            // Официальная структура Update: message — sibling callback.
            'message' => [
                'sender' => ['user_id' => 'bot_1', 'first_name' => 'Бот'],
                'recipient' => [
                    'chat_id' => 'chat_1',
                    'type' => 'dialog',
                    'user_id' => 'max-1',
                ],
                'body' => ['mid' => 'mid_source_1', 'text' => ''],
            ],
        ]);

        $event = AppointmentNotificationEvent::where('appointment_id', $draft->id)
            ->where('event_type', AppointmentNotificationEventType::BookingConfirmed)
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('mid_source_1', $event->payload['max_source_message_id'] ?? null);
        $this->assertSame('max-1', $event->payload['max_source_recipient_id'] ?? null);
        $this->assertSame($this->client->id, $event->payload['client_id'] ?? null);
    }

    public function test_callback_without_message_still_confirms_without_metadata(): void
    {
        $maxApi = Mockery::mock(MaxApiClient::class);
        $maxApi->shouldReceive('answerCallback')->once()->andReturn(true);
        $maxApi->shouldReceive('sendMessage')->zeroOrMoreTimes()->andReturn('mid_refusal');
        $this->app->instance(MaxApiClient::class, $maxApi);

        $handler = app(MaxWebhookHandler::class);

        $draft = Appointment::factory()
            ->forMaster($this->master)
            ->withMasterService($this->service)
            ->create([
                'client_id' => null,
                'status' => AppointmentStatus::Booked,
                'source' => null,
                'start_time' => Carbon::now('Europe/Moscow')->addDays(2)->setTime(14, 0),
            ]);

        Cache::put(
            CacheKeys::MAX_BOOKING_DRAFT.'max-1',
            (string) $draft->id,
            3600,
        );

        $handler->handle([
            'update_type' => 'message_callback',
            'callback' => [
                'callback_id' => 'cb_r02_no_msg',
                'payload' => 'max_confirm_'.$draft->id,
                'user' => ['user_id' => 'max-1'],
            ],
        ]);

        $event = AppointmentNotificationEvent::where('appointment_id', $draft->id)
            ->where('event_type', AppointmentNotificationEventType::BookingConfirmed)
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertArrayNotHasKey('max_source_message_id', $event->payload);
        $this->assertNotNull($draft->fresh()->client_id, 'подтверждение без message должно пройти');
    }

    public function test_callback_with_null_message_still_confirms_without_metadata(): void
    {
        $maxApi = Mockery::mock(MaxApiClient::class);
        $maxApi->shouldReceive('answerCallback')->once()->andReturn(true);
        $maxApi->shouldReceive('sendMessage')->zeroOrMoreTimes()->andReturn('mid_refusal');
        $this->app->instance(MaxApiClient::class, $maxApi);

        $handler = app(MaxWebhookHandler::class);

        $draft = Appointment::factory()
            ->forMaster($this->master)
            ->withMasterService($this->service)
            ->create([
                'client_id' => null,
                'status' => AppointmentStatus::Booked,
                'source' => null,
                'start_time' => Carbon::now('Europe/Moscow')->addDays(2)->setTime(14, 0),
            ]);

        Cache::put(
            CacheKeys::MAX_BOOKING_DRAFT.'max-1',
            (string) $draft->id,
            3600,
        );

        // Документация: message может быть null, если сообщение удалено
        // к моменту получения callback.
        $handler->handle([
            'update_type' => 'message_callback',
            'callback' => [
                'callback_id' => 'cb_r02_null_msg',
                'payload' => 'max_confirm_'.$draft->id,
                'user' => ['user_id' => 'max-1'],
            ],
            'message' => null,
        ]);

        $event = AppointmentNotificationEvent::where('appointment_id', $draft->id)
            ->where('event_type', AppointmentNotificationEventType::BookingConfirmed)
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertArrayNotHasKey('max_source_message_id', $event->payload);
        $this->assertNotNull($draft->fresh()->client_id, 'подтверждение при message=null должно пройти');
    }

    // ── 2. Успешная MAX-доставка фиксируется до удаления ──

    public function test_successful_delivery_records_delivered_then_deletes_source_message(): void
    {
        $appointment = $this->appointment();
        $event = $this->bookingConfirmedEvent($appointment, [
            'max_source_message_id' => 'mid_source_1',
            'max_source_recipient_id' => 'max-1',
        ]);

        $order = [];
        $maxMock = $this->mock(MaxApiClient::class);
        $maxMock->shouldReceive('sendMessage')
            ->once()
            ->with('max-1', Mockery::type('string'))
            ->andReturnUsing(function () use (&$order): string {
                $order[] = 'send';

                return 'mid_out';
            });
        $maxMock->shouldReceive('deleteMessage')
            ->once()
            ->with('mid_source_1')
            ->andReturnUsing(function () use ($event, &$order): bool {
                $order[] = 'delete';
                // Удаление вызывается ПОСЛЕ recordDelivery: строка уже delivered.
                $row = AppointmentNotificationDelivery::query()
                    ->where('appointment_notification_event_id', $event->id)
                    ->where('channel', 'max')
                    ->first();
                Assert::assertSame(
                    AppointmentNotificationDelivery::STATUS_DELIVERED,
                    $row?->status,
                );

                return true;
            });

        $this->runJob(new SendAppointmentNotificationJob($event->id), $maxMock);

        $this->assertSame(['send', 'delete'], $order);
        $row = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('channel', 'max')
            ->firstOrFail();
        $this->assertSame(AppointmentNotificationDelivery::STATUS_DELIVERED, $row->status);
        $this->assertSame('mid_out', $row->provider_message_id);
    }

    // ── 3. Ошибка отправки / recordDelivery=false → удаления нет ──

    public function test_send_failure_does_not_delete_source_message(): void
    {
        $appointment = $this->appointment();
        $event = $this->bookingConfirmedEvent($appointment, [
            'max_source_message_id' => 'mid_source_1',
            'max_source_recipient_id' => 'max-1',
        ]);

        $maxMock = $this->mock(MaxApiClient::class);
        $maxMock->shouldReceive('sendMessage')->once()->andReturnNull();
        $maxMock->shouldNotReceive('deleteMessage');

        try {
            $this->runJob(new SendAppointmentNotificationJob($event->id), $maxMock);
        } catch (\RuntimeException) {
            // handle() бросает при failed — ожидаемо для send=null.
        }

        $row = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('channel', 'max')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(AppointmentNotificationDelivery::STATUS_PENDING, $row->status);
        $this->assertNull($row->provider_message_id);
    }

    public function test_record_delivery_false_does_not_delete_source_message(): void
    {
        $appointment = $this->appointment();
        $event = $this->bookingConfirmedEvent($appointment, [
            'max_source_message_id' => 'mid_source_1',
            'max_source_recipient_id' => 'max-1',
        ]);

        // Job берёт свой claim; внутри sendMessage перехватываем владение
        // (меняем claim_token в БД) и возвращаем mid — recordDelivery
        // теряет CAS по своему токену → busy, без удаления.
        $foreignToken = (string) Str::uuid();
        $sendCalls = 0;

        $maxMock = $this->mock(MaxApiClient::class);
        $maxMock->shouldReceive('sendMessage')
            ->once()
            ->with('max-1', Mockery::type('string'))
            ->andReturnUsing(function () use ($event, $foreignToken, &$sendCalls): string {
                $sendCalls++;
                // Симуляция перехвата claim после HTTP, до recordDelivery.
                $updated = AppointmentNotificationDelivery::query()
                    ->where('appointment_notification_event_id', $event->id)
                    ->where('channel', 'max')
                    ->where('status', AppointmentNotificationDelivery::STATUS_PENDING)
                    ->whereNotNull('claim_token')
                    ->update([
                        'claim_token' => $foreignToken,
                        'lease_expires_at' => now()->addSeconds(60),
                    ]);
                Assert::assertSame(1, $updated, 'в момент send claim должен принадлежать job');

                return 'mid_out';
            });
        $maxMock->shouldNotReceive('deleteMessage');

        $this->runJob(new SendAppointmentNotificationJob($event->id), $maxMock);

        $this->assertSame(1, $sendCalls, 'send должен быть вызван ровно один раз');

        $row = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('channel', 'max')
            ->firstOrFail();
        $this->assertSame(AppointmentNotificationDelivery::STATUS_PENDING, $row->status);
        $this->assertSame(
            $foreignToken,
            (string) $row->claim_token,
            'чужой claim не должен быть затёрт освобождением job',
        );
        $this->assertNull($row->provider_message_id, 'delivery не должен объявляться delivered');
    }

    // ── 4. delete=false/exception → delivered сохранён, повторного send нет ──

    public function test_delete_false_keeps_delivered_and_does_not_resend(): void
    {
        $appointment = $this->appointment();
        $event = $this->bookingConfirmedEvent($appointment, [
            'max_source_message_id' => 'mid_source_1',
            'max_source_recipient_id' => 'max-1',
        ]);

        $sendCalls = 0;
        $maxMock = $this->mock(MaxApiClient::class);
        $maxMock->shouldReceive('sendMessage')
            ->once()
            ->andReturnUsing(function () use (&$sendCalls): string {
                $sendCalls++;

                return 'mid_out';
            });
        $maxMock->shouldReceive('deleteMessage')->once()->andReturn(false);

        $this->runJob(new SendAppointmentNotificationJob($event->id), $maxMock);

        $row = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('channel', 'max')
            ->firstOrFail();
        $this->assertSame(AppointmentNotificationDelivery::STATUS_DELIVERED, $row->status);
        $this->assertSame(1, $sendCalls, 'ошибка удаления не должна повторять send');

        // Повтор job: delivered уже есть — второй send/delete нет.
        $this->runJob(new SendAppointmentNotificationJob($event->id), $maxMock);
        $this->assertSame(1, $sendCalls);
    }

    public function test_delete_exception_keeps_delivered_and_does_not_resend(): void
    {
        $appointment = $this->appointment();
        $event = $this->bookingConfirmedEvent($appointment, [
            'max_source_message_id' => 'mid_source_1',
            'max_source_recipient_id' => 'max-1',
        ]);

        $sendCalls = 0;
        $maxMock = $this->mock(MaxApiClient::class);
        $maxMock->shouldReceive('sendMessage')
            ->once()
            ->andReturnUsing(function () use (&$sendCalls): string {
                $sendCalls++;

                return 'mid_out';
            });
        $maxMock->shouldReceive('deleteMessage')
            ->once()
            ->andThrow(new \RuntimeException('delete exploded'));

        // Exception из deleteMessage не должен уйти в общий catch deliver().
        $this->runJob(new SendAppointmentNotificationJob($event->id), $maxMock);

        $row = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('channel', 'max')
            ->firstOrFail();
        $this->assertSame(AppointmentNotificationDelivery::STATUS_DELIVERED, $row->status);
        $this->assertSame(1, $sendCalls);

        $this->runJob(new SendAppointmentNotificationJob($event->id), $maxMock);
        $this->assertSame(1, $sendCalls, 'повтор job не отправляет второе подтверждение');
    }

    // ── 5. Повтор job не отправляет второе подтверждение ──

    public function test_job_rerun_after_successful_cleanup_does_not_resend(): void
    {
        $appointment = $this->appointment();
        $event = $this->bookingConfirmedEvent($appointment, [
            'max_source_message_id' => 'mid_source_1',
            'max_source_recipient_id' => 'max-1',
        ]);

        $maxMock = $this->mock(MaxApiClient::class);
        $maxMock->shouldReceive('sendMessage')->once()->andReturn('mid_out');
        $maxMock->shouldReceive('deleteMessage')->once()->andReturn(true);

        $this->runJob(new SendAppointmentNotificationJob($event->id), $maxMock);
        $this->runJob(new SendAppointmentNotificationJob($event->id), $maxMock);

        // once()×2 на mock: второй проход не вызывает send/delete.
    }

    // ── 6. Нет ID / другой канал или тип события → удаления нет ──

    public function test_no_message_id_metadata_skips_delete(): void
    {
        $appointment = $this->appointment();
        $event = $this->bookingConfirmedEvent($appointment);

        $maxMock = $this->mock(MaxApiClient::class);
        $maxMock->shouldReceive('sendMessage')->once()->andReturn('mid_out');
        $maxMock->shouldNotReceive('deleteMessage');

        $this->runJob(new SendAppointmentNotificationJob($event->id), $maxMock);

        $row = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('channel', 'max')
            ->firstOrFail();
        $this->assertSame(AppointmentNotificationDelivery::STATUS_DELIVERED, $row->status);
    }

    public function test_recipient_mismatch_skips_delete(): void
    {
        $appointment = $this->appointment();
        $event = $this->bookingConfirmedEvent($appointment, [
            'max_source_message_id' => 'mid_source_1',
            'max_source_recipient_id' => 'max_OTHER',
        ]);

        $maxMock = $this->mock(MaxApiClient::class);
        $maxMock->shouldReceive('sendMessage')->once()->andReturn('mid_out');
        $maxMock->shouldNotReceive('deleteMessage');

        $this->runJob(new SendAppointmentNotificationJob($event->id), $maxMock);
    }

    public function test_non_booking_confirmed_event_skips_delete(): void
    {
        $appointment = $this->appointment();
        $recorder = app(AppointmentNotificationRecorder::class);
        $version = $recorder->bumpVersion($appointment);
        $event = $recorder->record(
            $appointment->fresh(),
            AppointmentNotificationEventType::CreatedByMaster,
            [
                'max_source_message_id' => 'mid_source_1',
                'max_source_recipient_id' => 'max-1',
            ],
            $version,
        );
        $this->assertNotNull($event);

        $maxMock = $this->mock(MaxApiClient::class);
        $maxMock->shouldReceive('sendMessage')->once()->andReturn('mid_out');
        $maxMock->shouldNotReceive('deleteMessage');

        $this->runJob(new SendAppointmentNotificationJob($event->id), $maxMock);
    }

    public function test_vk_channel_skips_delete_even_with_max_metadata(): void
    {
        $client = Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->workspace->id,
            'max_id' => null,
            'vk_id' => '777',
            'vk_messages_allowed' => true,
        ]);
        $appointment = $this->appointment(client: $client);

        $event = $this->bookingConfirmedEvent($appointment, [
            'max_source_message_id' => 'mid_source_1',
            'max_source_recipient_id' => 'max-1',
        ]);

        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->with('777', Mockery::type('string'), Mockery::type('array'))
            ->andReturn('vk_mid');

        $maxMock = $this->mock(MaxApiClient::class);
        $maxMock->shouldNotReceive('sendMessage');
        $maxMock->shouldNotReceive('deleteMessage');

        $this->runJob(
            new SendAppointmentNotificationJob($event->id),
            $maxMock,
            $vkMock,
        );

        $row = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('channel', 'vk')
            ->firstOrFail();
        $this->assertSame(AppointmentNotificationDelivery::STATUS_DELIVERED, $row->status);
    }

    // ── 7. MaxApiClient::deleteMessage ──

    public function test_delete_message_api_success_true_returns_true(): void
    {
        Http::fake([
            'api.max.example/messages*' => Http::response(['success' => true], 200),
        ]);

        $api = new MaxApiClient;
        $this->assertTrue($api->deleteMessage('mid_1'));

        Http::assertSent(function ($request): bool {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), 'message_id=mid_1')
                && $request->hasHeader('Authorization', 'test_max_token');
        });
    }

    public function test_delete_message_api_http_200_success_false_returns_false(): void
    {
        Http::fake([
            'api.max.example/messages*' => Http::response([
                'success' => false,
                'message' => 'Message not found',
            ], 200),
        ]);

        $api = new MaxApiClient;
        $this->assertFalse($api->deleteMessage('mid_1'));
    }

    public function test_delete_message_api_http_error_returns_false(): void
    {
        Http::fake([
            'api.max.example/messages*' => Http::response(['error' => 'boom'], 500),
        ]);

        $api = new MaxApiClient;
        $this->assertFalse($api->deleteMessage('mid_1'));
    }

    public function test_delete_message_api_exception_returns_false(): void
    {
        Http::fake(function (): void {
            throw new \RuntimeException('network down');
        });

        $api = new MaxApiClient;
        $this->assertFalse($api->deleteMessage('mid_1'));
    }
}
