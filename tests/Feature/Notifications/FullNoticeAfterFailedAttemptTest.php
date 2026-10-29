<?php

namespace Tests\Feature\Notifications;

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
use App\Services\Booking\BookingService;
use App\Services\MaxApiClient;
use App\Services\Notification\AppointmentChannelResolver;
use App\Services\Notification\AppointmentNotificationRecorder;
use App\Services\VkApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Дефект 5 (P2): полное первое уведомление теряется после неудачной попытки.
 *
 * Recorder выставлял full_notice по подтверждённой доставке, но живой
 * claim считал гарантией будущей доставки. Это неверно: после формирования
 * короткой поправки первая отправка может завершиться ошибкой, а старое
 * событие подавляется новой версией — клиент никогда не получит полный
 * контекст. Ранее CreationEvents искались только по CreatedByMaster,
 * из-за чего widget-сценарий (BookingConfirmed без CreatedByMaster)
 * маскировался createManual в тестах.
 *
 * Контракт: полный актуальный контекст required, если первоначальная
 * доставка не ПОДТВЕРЖДЕНА (status=delivered). Живой claim не является
 * подтверждением. Учитываются и CreatedByMaster, и BookingConfirmed.
 * Exactly-once не обещается.
 */
class FullNoticeAfterFailedAttemptTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    private MasterService $service;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.max.api_url' => 'https://api.max.example',
            'services.max.bot_token' => 'test_max_token',
            'services.vk.bot_token' => 'test_vk_token',
            'services.vk.group_id' => '1',
            'services.vk.app_id' => '1',
        ]);

        Queue::fake();

        $this->travelTo(Carbon::parse('2026-11-10 08:00:00', 'Europe/Moscow'));

        $this->master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow'],
            'address' => 'ул. Тестовая, 1',
        ]);
        $this->workspace = Workspace::create([
            'name' => 'ws-'.$this->master->id,
            'owner_id' => $this->master->id,
        ]);
        $this->workspace->ensureSlug();
        $this->master->update(['workspace_id' => $this->workspace->id]);
        $this->service = MasterService::factory()->forMaster($this->master)->create([
            'price_override' => 1500,
            'duration_override' => 60,
        ]);
    }

    private function client(array $attrs = []): Client
    {
        return Client::factory()->create(array_merge([
            'user_id' => $this->master->id,
            'max_id' => 'max-1',
        ], $attrs));
    }

    /**
     * Ручное создание записи через BookingService — как в проде.
     *
     * @return array{Appointment, AppointmentNotificationEvent}
     */
    private function createManual(Client $client): array
    {
        $result = app(BookingService::class)->createManualAppointment(
            $this->master,
            $this->service,
            '2026-11-12',
            '10:00',
            ignoreWarnings: true,
            confirmOutsideHours: true,
            clientId: $client->id,
        );
        $this->assertTrue($result['success'], json_encode($result));

        $appointment = $result['appointment'];
        $created = AppointmentNotificationEvent::where('appointment_id', $appointment->id)
            ->where('event_type', AppointmentNotificationEventType::CreatedByMaster)
            ->firstOrFail();

        return [$appointment, $created];
    }

    /**
     * Красный: создание → API вернул null (неудачная попытка, pending
     * delivery осталась) → изменение до retry → доставка актуального
     * полного сообщения.
     */
    public function test_creation_then_failed_attempt_then_change_sends_full_message(): void
    {
        $client = $this->client();
        [$appointment, $created] = $this->createManual($client);

        // Неудачная попытка: API вернул null → release, pending без терминала.
        $maxMock = Mockery::mock(MaxApiClient::class);
        $maxMock->shouldReceive('sendMessage')->once()->andReturnNull();
        $vkMock = Mockery::mock(VkApiClient::class);
        $vkMock->shouldNotReceive('sendMessage');

        $resolver = app(AppointmentChannelResolver::class);
        $this->app->instance(AppointmentChannelResolver::class, $resolver);

        try {
            (new SendAppointmentNotificationJob($created->id))->handle($resolver, $maxMock, $vkMock);
        } catch (\RuntimeException) {
            // handle() бросает RuntimeException при failed — ожидаемо.
        }

        // Pending-delivery осталась (claim освобождён, успеха нет).
        $pendingRow = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $created->id)
            ->where('status', AppointmentNotificationDelivery::STATUS_PENDING)
            ->first();
        $this->assertNotNull($pendingRow, 'неудачная попытка оставляет pending-строку');

        // Мастер меняет запись до retry.
        $response = $this->actingAs($this->master)
            ->patchJson("/admin/appointments/{$appointment->id}/status", ['price' => 2100]);
        $this->assertLessThan(400, $response->status(), $response->getContent());

        $changed = AppointmentNotificationEvent::where('appointment_id', $appointment->id)
            ->where('event_type', AppointmentNotificationEventType::FieldsChanged)
            ->firstOrFail();

        $this->assertTrue(
            (bool) ($changed->payload['full_notice'] ?? false),
            'после неудачной попытки (нет delivered) изменение должно нести full_notice',
        );

        // Доставка: клиент получает полный контекст, а не одну поправку.
        $sent = null;
        $okMock = Mockery::mock(MaxApiClient::class);
        $okMock->shouldReceive('sendMessage')
            ->once()
            ->andReturnUsing(function (string $chatId, string $text) use (&$sent) {
                $sent = $text;

                return 'mid-ok';
            });
        $this->app->instance(AppointmentChannelResolver::class, app(AppointmentChannelResolver::class));

        (new SendAppointmentNotificationJob($changed->id))->handle(
            app(AppointmentChannelResolver::class),
            $okMock,
            Mockery::mock(VkApiClient::class),
        );

        $this->assertNotNull($sent, 'изменение должно доставиться');
        $this->assertStringContainsString(
            'Мастер записал вас',
            $sent,
            'клиент должен получить полное сообщение о записи, а не одну поправку',
        );
        $this->assertStringContainsString('12.11.2026', $sent);
        $this->assertStringContainsString('10:00', $sent);
        $this->assertStringContainsString('2 100', $sent, 'изменение цены присутствует в объединённом сообщении');
    }

    /**
     * После подтверждённой доставки (delivered) изменение НЕ должно
     * дублировать полный контекст — только поправка.
     */
    public function test_change_after_delivered_sends_only_delta(): void
    {
        $client = $this->client();
        [$appointment, $created] = $this->createManual($client);

        // Успешная доставка создания.
        $okMock = Mockery::mock(MaxApiClient::class);
        $okMock->shouldReceive('sendMessage')->once()->andReturn('mid-ok');
        $resolver = app(AppointmentChannelResolver::class);
        $this->app->instance(AppointmentChannelResolver::class, $resolver);
        (new SendAppointmentNotificationJob($created->id))->handle(
            $resolver,
            $okMock,
            Mockery::mock(VkApiClient::class),
        );

        $this->assertSame(
            AppointmentNotificationDelivery::STATUS_DELIVERED,
            AppointmentNotificationDelivery::query()
                ->where('appointment_notification_event_id', $created->id)
                ->where('status', AppointmentNotificationDelivery::STATUS_DELIVERED)
                ->value('status'),
        );

        // Мастер меняет запись после доставки.
        $response = $this->actingAs($this->master)
            ->patchJson("/admin/appointments/{$appointment->id}/status", ['price' => 2100]);
        $this->assertLessThan(400, $response->status(), $response->getContent());

        $changed = AppointmentNotificationEvent::where('appointment_id', $appointment->id)
            ->where('event_type', AppointmentNotificationEventType::FieldsChanged)
            ->firstOrFail();

        $this->assertFalse(
            (bool) ($changed->payload['full_notice'] ?? false),
            'после подтверждённой доставки full_notice не ставится — только поправка',
        );
    }

    /**
     * Создание захвачено исполнителем (живой claim) → изменение → первая
     * отправка неуспешна → клиент обязан получить полный актуальный
     * контекст, а не короткую поправку. Живой claim не является
     * подтверждением будущей доставки.
     */
    public function test_change_while_creation_in_flight_still_carries_full_context(): void
    {
        $client = $this->client();
        [$appointment, $created] = $this->createManual($client);

        // Исполнитель создания в полёте: claim с живым lease.
        AppointmentNotificationDelivery::create([
            'appointment_notification_event_id' => $created->id,
            'channel' => 'max',
            'status' => AppointmentNotificationDelivery::STATUS_PENDING,
            'claim_token' => (string) Str::uuid(),
            'lease_expires_at' => now()->addSeconds(60),
        ]);

        // Мастер меняет запись, пока первая отправка ещё в полёте.
        $response = $this->actingAs($this->master)
            ->patchJson("/admin/appointments/{$appointment->id}/status", ['price' => 2100]);
        $this->assertLessThan(400, $response->status(), $response->getContent());

        $changed = AppointmentNotificationEvent::where('appointment_id', $appointment->id)
            ->where('event_type', AppointmentNotificationEventType::FieldsChanged)
            ->firstOrFail();

        $this->assertTrue(
            (bool) ($changed->payload['full_notice'] ?? false),
            'живой claim создания не подтверждает доставку — изменение несёт full_notice',
        );

        // Первая отправка неуспешна: HTTP вернул null, исполнитель
        // освободил claim, строка осталась pending без доставки.
        AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $created->id)
            ->update(['claim_token' => null, 'lease_expires_at' => null]);

        // Клиент получает полный актуальный контекст из изменения.
        $sent = null;
        $okMock = Mockery::mock(MaxApiClient::class);
        $okMock->shouldReceive('sendMessage')
            ->once()
            ->andReturnUsing(function (string $chatId, string $text) use (&$sent) {
                $sent = $text;

                return 'mid-ok';
            });

        (new SendAppointmentNotificationJob($changed->id))->handle(
            app(AppointmentChannelResolver::class),
            $okMock,
            Mockery::mock(VkApiClient::class),
        );

        $this->assertNotNull($sent, 'изменение должно доставиться');
        $this->assertStringContainsString('Мастер записал вас', $sent);
        $this->assertStringContainsString('12.11.2026', $sent);
        $this->assertStringContainsString('2 100', $sent);
    }

    /**
     * Настоящий widget-сценарий: BookingConfirmed БЕЗ CreatedByMaster →
     * неудачная доставка → изменение → полное сообщение (а не одна
     * поправка). Ранее тест маскировался createManual-событием.
     */
    public function test_booking_confirmed_without_creation_after_failed_attempt_keeps_full_context(): void
    {
        $client = $this->client();

        // Widget-запись: события создания нет, только BookingConfirmed.
        $appointment = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client)
            ->withMasterService($this->service)
            ->create([
                'status' => AppointmentStatus::Booked,
                'source' => AppointmentSource::Max,
                'start_time' => Carbon::parse('2026-11-12 10:00:00', 'Europe/Moscow'),
            ]);

        $recorder = app(AppointmentNotificationRecorder::class);
        $version = $recorder->bumpVersion($appointment);
        $bookingConfirmed = $recorder->record(
            $appointment->fresh(),
            AppointmentNotificationEventType::BookingConfirmed,
            [],
            $version,
        );
        $this->assertNotNull($bookingConfirmed);
        $this->assertSame(
            0,
            AppointmentNotificationEvent::where('appointment_id', $appointment->id)
                ->where('event_type', AppointmentNotificationEventType::CreatedByMaster)
                ->count(),
            'предусловие: CreatedByMaster отсутствует',
        );

        // Неудачная попытка доставки booking_confirmed.
        $maxMock = Mockery::mock(MaxApiClient::class);
        $maxMock->shouldReceive('sendMessage')->once()->andReturnNull();
        try {
            (new SendAppointmentNotificationJob($bookingConfirmed->id))->handle(
                app(AppointmentChannelResolver::class),
                $maxMock,
                Mockery::mock(VkApiClient::class),
            );
        } catch (\RuntimeException) {
        }

        $pending = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $bookingConfirmed->id)
            ->where('status', AppointmentNotificationDelivery::STATUS_PENDING)
            ->exists();
        $this->assertTrue($pending, 'после неудачной попытки booking_confirmed есть pending');

        // Мастер меняет цену — подтверждённой доставки полного контекста нет.
        $response = $this->actingAs($this->master)
            ->patchJson("/admin/appointments/{$appointment->id}/status", ['price' => 2500]);
        $this->assertLessThan(400, $response->status(), $response->getContent());

        $changed = AppointmentNotificationEvent::where('appointment_id', $appointment->id)
            ->where('event_type', AppointmentNotificationEventType::FieldsChanged)
            ->firstOrFail();

        $this->assertTrue(
            (bool) ($changed->payload['full_notice'] ?? false),
            'booking_confirmed не доставлен → изменение несёт полный контекст',
        );

        // Проверяем СОДЕРЖИМОЕ отправленного текста, а не только флаг.
        $sent = null;
        $okMock = Mockery::mock(MaxApiClient::class);
        $okMock->shouldReceive('sendMessage')
            ->once()
            ->andReturnUsing(function (string $chatId, string $text) use (&$sent) {
                $sent = $text;

                return 'mid-ok';
            });

        (new SendAppointmentNotificationJob($changed->id))->handle(
            app(AppointmentChannelResolver::class),
            $okMock,
            Mockery::mock(VkApiClient::class),
        );

        $this->assertNotNull($sent, 'изменение должно доставиться');
        $this->assertStringContainsString('Мастер записал вас', $sent, 'клиент получает полный контекст');
        $this->assertStringContainsString('12.11.2026', $sent);
        // Старт 10:00 UTC, таймзона мастера Europe/Moscow → 13:00 в тексте.
        $this->assertStringContainsString('13:00', $sent);
        $this->assertStringContainsString('2 500', $sent, 'актуальная цена в полном сообщении');
    }
}
