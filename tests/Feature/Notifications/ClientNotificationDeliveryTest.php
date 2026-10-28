<?php

namespace Tests\Feature\Notifications;

use App\Enums\AppointmentNotificationEventType;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Enums\RecurrenceType;
use App\Enums\RecurringSeriesStatus;
use App\Jobs\SendAppointmentNotificationJob;
use App\Models\Appointment;
use App\Models\AppointmentNotificationDelivery;
use App\Models\AppointmentNotificationEvent;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\RecurringAppointmentSeries;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Booking\BookingService;
use App\Services\Booking\RecurringAppointmentService;
use App\Services\MaxApiClient;
use App\Services\Notification\AppointmentChannelResolver;
use App\Services\Notification\AppointmentNotificationRecorder;
use App\Services\Notification\AppointmentNotificationSummary;
use App\Services\VkApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * HTTP/webhook-точки → outbox-событие → job доставки.
 * Внешние API подменены; живых отправок нет.
 */
class ClientNotificationDeliveryTest extends TestCase
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
            'workspace_id' => $this->workspace->id,
        ], $attrs));
    }

    private function manualAppointment(Client $client, array $attrs = []): Appointment
    {
        return Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client)
            ->withMasterService($this->service)
            ->create(array_merge([
                'status' => AppointmentStatus::Booked,
                'source' => AppointmentSource::Admin,
                'start_time' => Carbon::now('Europe/Moscow')->addDays(3)->setTime(10, 0),
            ], $attrs));
    }

    /**
     * Создать запись + outbox-событие created_by_master (как BookingService).
     */
    private function manualAppointmentWithEvent(Client $client): array
    {
        $appointment = $this->manualAppointment($client);

        $recorder = app(AppointmentNotificationRecorder::class);
        $version = $recorder->bumpVersion($appointment);
        $event = $recorder->record(
            $appointment->fresh(),
            AppointmentNotificationEventType::CreatedByMaster,
            [],
            $version,
        );

        return [$appointment, $event];
    }

    // ── Миграции / схема ──

    public function test_notification_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('appointment_notification_events'));
        $this->assertTrue(Schema::hasTable('appointment_notification_deliveries'));
        $this->assertTrue(Schema::hasColumn('appointments', 'notification_version'));
    }

    // ── Выбор канала ──

    public function test_channel_resolver_prefers_max_for_admin_source(): void
    {
        $client = $this->client(['max_id' => 'max-1', 'vk_id' => '111', 'vk_messages_allowed' => true]);
        $appointment = $this->manualAppointment($client);

        $this->assertSame(
            AppointmentChannelResolver::CHANNEL_MAX,
            app(AppointmentChannelResolver::class)->resolve($appointment),
        );
    }

    public function test_channel_resolver_falls_back_to_vk_when_no_max(): void
    {
        $client = $this->client(['max_id' => null, 'vk_id' => '111', 'vk_messages_allowed' => true]);
        $appointment = $this->manualAppointment($client);

        $this->assertSame(
            AppointmentChannelResolver::CHANNEL_VK,
            app(AppointmentChannelResolver::class)->resolve($appointment),
        );
    }

    public function test_channel_resolver_returns_null_when_vk_forbidden_and_no_max(): void
    {
        $client = $this->client(['max_id' => null, 'vk_id' => '111', 'vk_messages_allowed' => false]);
        $appointment = $this->manualAppointment($client);

        $this->assertNull(app(AppointmentChannelResolver::class)->resolve($appointment));
    }

    public function test_channel_resolver_keeps_vk_source_channel(): void
    {
        $client = $this->client(['max_id' => 'max-1', 'vk_id' => '111', 'vk_messages_allowed' => true]);
        $appointment = $this->manualAppointment($client, ['source' => AppointmentSource::Vk]);

        $this->assertSame(
            AppointmentChannelResolver::CHANNEL_VK,
            app(AppointmentChannelResolver::class)->resolve($appointment),
        );
    }

    public function test_channel_resolver_does_not_use_telegram_for_admin_source(): void
    {
        $client = $this->client(['telegram_id' => '123', 'max_id' => null, 'vk_id' => null]);
        $appointment = $this->manualAppointment($client);

        $this->assertNull(app(AppointmentChannelResolver::class)->resolve($appointment));
    }

    // ── Outbox-запись при ручном создании ──

    public function test_manual_create_records_created_by_master_event(): void
    {
        Queue::fake();
        $client = $this->client(['max_id' => 'max-1']);

        $result = app(BookingService::class)->createManualAppointment(
            $this->master,
            $this->service,
            Carbon::now('Europe/Moscow')->addDays(2)->format('Y-m-d'),
            '11:00',
            ignoreWarnings: true,
            confirmOutsideHours: true,
            clientId: $client->id,
        );

        $this->assertTrue($result['success']);
        $appointment = $result['appointment'];

        $event = AppointmentNotificationEvent::where('appointment_id', $appointment->id)
            ->where('event_type', AppointmentNotificationEventType::CreatedByMaster)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame($appointment->notification_version, $event->event_version);
        $this->assertStringContainsString('Мастер записал вас', (string) ($event->payload['text'] ?? ''));
        // client_confirmed_at не устанавливается автоматически
        $this->assertNull($appointment->fresh()->client_confirmed_at);

        Queue::assertPushed(SendAppointmentNotificationJob::class);
    }

    public function test_widget_draft_without_client_records_nothing(): void
    {
        Queue::fake();

        $appointment = Appointment::factory()
            ->forMaster($this->master)
            ->withMasterService($this->service)
            ->create([
                'client_id' => null,
                'status' => AppointmentStatus::Booked,
                'source' => null,
                'start_time' => Carbon::now()->addDays(2),
            ]);

        $this->assertSame(0, AppointmentNotificationEvent::where('appointment_id', $appointment->id)->count());
        Queue::assertNothingPushed();
    }

    // ── Отмена мастером vs клиентом ──

    public function test_master_cancel_records_cancelled_by_master_event(): void
    {
        Queue::fake();
        $client = $this->client(['max_id' => 'max-1']);
        $appointment = $this->manualAppointment($client);

        app(BookingService::class)->cancel($appointment, $this->master);

        $event = AppointmentNotificationEvent::where('appointment_id', $appointment->id)
            ->where('event_type', AppointmentNotificationEventType::CancelledByMaster)
            ->first();

        $this->assertNotNull($event);
        $this->assertStringContainsString('Мастер отменил запись', (string) ($event->payload['text'] ?? ''));
        Queue::assertPushed(SendAppointmentNotificationJob::class);
    }

    public function test_client_cancel_does_not_record_master_cancel_event(): void
    {
        Queue::fake();
        $client = $this->client(['max_id' => 'max-1']);
        $appointment = $this->manualAppointment($client);

        app(BookingService::class)->cancel($appointment, $client);

        $this->assertSame(0, AppointmentNotificationEvent::where('appointment_id', $appointment->id)
            ->where('event_type', AppointmentNotificationEventType::CancelledByMaster)
            ->count());
    }

    public function test_system_cancel_without_actor_does_not_record_master_cancel(): void
    {
        Queue::fake();
        $client = $this->client(['max_id' => 'max-1']);
        $appointment = $this->manualAppointment($client);

        app(BookingService::class)->cancel($appointment, null);

        $this->assertSame(0, AppointmentNotificationEvent::where('appointment_id', $appointment->id)
            ->where('event_type', AppointmentNotificationEventType::CancelledByMaster)
            ->count());
    }

    // ── Перенос ──

    public function test_reschedule_records_rescheduled_event_with_old_and_new_time(): void
    {
        Queue::fake();
        $client = $this->client(['max_id' => 'max-1']);
        $appointment = $this->manualAppointment($client);

        $oldStart = $appointment->start_time->toIso8601String();
        $newDate = Carbon::now('Europe/Moscow')->addDays(5)->format('Y-m-d');

        $result = app(BookingService::class)->rescheduleAppointment(
            $appointment,
            $newDate,
            '14:00',
            ignoreWarnings: true,
            confirmOutsideHours: true,
        );

        $this->assertTrue($result['success']);

        $event = AppointmentNotificationEvent::where('appointment_id', $appointment->id)
            ->where('event_type', AppointmentNotificationEventType::Rescheduled)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame($oldStart, $event->payload['old_start_time']);
        $this->assertArrayHasKey('new_start_time', $event->payload);
        $this->assertStringContainsString('перенесена', (string) ($event->payload['text'] ?? ''));

        // Напоминания сброшены полностью
        $fresh = $appointment->fresh();
        $this->assertNull($fresh->reminder_24h_sent_at);
        $this->assertNull($fresh->reminder_24h_dispatched_at);
        $this->assertNull($fresh->reminder_24h_failed_at);
        $this->assertNull($fresh->reminder_final_sent_at);
        $this->assertNull($fresh->reminder_final_dispatched_at);
        $this->assertNull($fresh->reminder_final_failed_at);
    }

    // ── Изменение полей ──

    public function test_fields_change_records_single_fields_changed_event(): void
    {
        Queue::fake();
        $client = $this->client(['max_id' => 'max-1']);
        $appointment = $this->manualAppointment($client);

        $newService = MasterService::factory()->forMaster($this->master)->create([
            'price_override' => 2000,
            'duration_override' => 90,
        ]);
        if ($newService->catalog) {
            $newService->catalog->update(['title' => 'Другая услуга']);
        }

        $this->actingAs($this->master);
        $response = $this->patchJson("/admin/appointments/{$appointment->id}/status", [
            'service_id' => $newService->id,
            'price' => 2500,
            'duration' => 45,
        ]);

        if (! in_array($response->status(), [200, 302], true)) {
            $this->fail('Unexpected status '.$response->status().': '.$response->getContent());
        }

        $event = AppointmentNotificationEvent::where('appointment_id', $appointment->id)
            ->where('event_type', AppointmentNotificationEventType::FieldsChanged)
            ->first();

        $this->assertNotNull($event);
        $this->assertArrayHasKey('service_name', $event->payload['changes'] ?? []);
        $this->assertArrayHasKey('price', $event->payload['changes'] ?? []);
        $this->assertArrayHasKey('duration', $event->payload['changes'] ?? []);
        // Одно сохранение — одно событие
        $this->assertSame(1, AppointmentNotificationEvent::where('appointment_id', $appointment->id)
            ->where('event_type', AppointmentNotificationEventType::FieldsChanged)
            ->count());
    }

    public function test_save_without_changes_records_no_event(): void
    {
        Queue::fake();
        $client = $this->client(['max_id' => 'max-1']);
        $appointment = $this->manualAppointment($client);
        $versionBefore = (int) $appointment->fresh()->notification_version;

        $this->actingAs($this->master);
        $this->patchJson("/admin/appointments/{$appointment->id}/status", [
            'duration' => $appointment->duration,
            'price' => (float) $appointment->price,
        ]);

        $this->assertSame(0, AppointmentNotificationEvent::where('appointment_id', $appointment->id)
            ->where('event_type', AppointmentNotificationEventType::FieldsChanged)
            ->count());
        $this->assertSame($versionBefore, (int) $appointment->fresh()->notification_version);
    }

    // ── Идентичность / версия ──

    public function test_same_version_duplicate_event_is_rejected(): void
    {
        $client = $this->client(['max_id' => 'max-1']);
        $appointment = $this->manualAppointment($client);

        $recorder = app(AppointmentNotificationRecorder::class);
        $version = $recorder->bumpVersion($appointment);

        $first = $recorder->record($appointment->fresh(), AppointmentNotificationEventType::CreatedByMaster, [], $version);
        $this->assertNotNull($first);

        // Идентичность (appointment, event_type, event_version) закрыта
        // unique-индексом: повтор той же версии не создаёт второе событие.
        // Внутри RefreshDatabase-транзакции unique violation abort'ит
        // connection, поэтому ловим на уровне коннекта через savepoint.
        $conn = AppointmentNotificationEvent::query()->getConnection();
        $conn->statement('SAVEPOINT dup_event_test');
        $threw = false;
        try {
            AppointmentNotificationEvent::create([
                'appointment_id' => $appointment->id,
                'event_type' => AppointmentNotificationEventType::CreatedByMaster,
                'event_version' => $version,
                'payload' => [],
            ]);
        } catch (\Throwable) {
            $threw = true;
        }
        $conn->statement('ROLLBACK TO SAVEPOINT dup_event_test');
        $conn->statement('RELEASE SAVEPOINT dup_event_test');

        $this->assertTrue($threw, 'Duplicate (appointment, type, version) must violate unique index');

        $this->assertSame(1, AppointmentNotificationEvent::where('appointment_id', $appointment->id)
            ->where('event_type', AppointmentNotificationEventType::CreatedByMaster)
            ->count());
    }

    public function test_stale_event_is_superseded_and_not_sent(): void
    {
        $client = $this->client(['max_id' => 'max-1']);
        [$appointment, $event] = $this->manualAppointmentWithEvent($client);
        $this->assertNotNull($event);

        // Запись ушла вперёд (например, перенос)
        app(AppointmentNotificationRecorder::class)
            ->bumpVersion($appointment);

        $maxMock = $this->mock(MaxApiClient::class);
        $maxMock->shouldReceive('sendMessage')->never();

        (new SendAppointmentNotificationJob($event->id))->handle(
            app(AppointmentChannelResolver::class),
            $maxMock,
            $this->mock(VkApiClient::class),
        );

        $delivery = AppointmentNotificationDelivery::where('appointment_notification_event_id', $event->id)->first();
        // delivery либо superseded, либо отсутствует (событие отсеяно до claim)
        $this->assertTrue(
            $delivery === null
            || in_array($delivery->status, [
                AppointmentNotificationDelivery::STATUS_SUPERSEDED,
                AppointmentNotificationDelivery::STATUS_NO_CHANNEL,
            ], true),
        );
    }

    // ── Job: успех / null API / нет канала ──

    public function test_job_delivers_via_max_and_records_message_id(): void
    {
        $client = $this->client(['max_id' => 'max-1']);
        [$appointment, $event] = $this->manualAppointmentWithEvent($client);
        $this->assertNotNull($event);

        $maxMock = $this->mock(MaxApiClient::class);
        $maxMock->shouldReceive('sendMessage')
            ->once()
            ->andReturn('mid-123');

        (new SendAppointmentNotificationJob($event->id))->handle(
            app(AppointmentChannelResolver::class),
            $maxMock,
            $this->mock(VkApiClient::class),
        );

        $delivery = AppointmentNotificationDelivery::where('appointment_notification_event_id', $event->id)
            ->where('channel', 'max')
            ->firstOrFail();

        $this->assertSame(AppointmentNotificationDelivery::STATUS_DELIVERED, $delivery->status);
        $this->assertSame('mid-123', $delivery->provider_message_id);
        $this->assertNotNull($delivery->delivered_at);
    }

    public function test_job_null_from_api_is_not_success(): void
    {
        $client = $this->client(['max_id' => 'max-1']);
        [$appointment, $event] = $this->manualAppointmentWithEvent($client);
        $this->assertNotNull($event);

        $maxMock = $this->mock(MaxApiClient::class);
        $maxMock->shouldReceive('sendMessage')->once()->andReturnNull();

        try {
            (new SendAppointmentNotificationJob($event->id))->handle(
                app(AppointmentChannelResolver::class),
                $maxMock,
                $this->mock(VkApiClient::class),
            );
            $this->fail('Expected RuntimeException for failed delivery');
        } catch (\RuntimeException) {
            // bounded retry via queue
        }

        $delivery = AppointmentNotificationDelivery::where('appointment_notification_event_id', $event->id)->first();
        // claim released после неудачи — delivery pending или отсутствует, НЕ delivered
        if ($delivery !== null) {
            $this->assertNotSame(AppointmentNotificationDelivery::STATUS_DELIVERED, $delivery->status);
        }
    }

    public function test_job_records_no_channel_without_http(): void
    {
        $client = $this->client(['max_id' => null, 'vk_id' => null, 'telegram_id' => null]);
        [$appointment, $event] = $this->manualAppointmentWithEvent($client);
        $this->assertNotNull($event);

        $maxMock = $this->mock(MaxApiClient::class);
        $maxMock->shouldReceive('sendMessage')->never();

        (new SendAppointmentNotificationJob($event->id))->handle(
            app(AppointmentChannelResolver::class),
            $maxMock,
            $this->mock(VkApiClient::class),
        );

        $delivery = AppointmentNotificationDelivery::where('appointment_notification_event_id', $event->id)
            ->where('status', AppointmentNotificationDelivery::STATUS_NO_CHANNEL)
            ->first();

        $this->assertNotNull($delivery);
    }

    // ── Откат транзакции ──

    public function test_rollback_discards_notification_event(): void
    {
        Queue::fake();
        $client = $this->client(['max_id' => 'max-1']);

        try {
            DB::transaction(function () use ($client) {
                $recorder = app(AppointmentNotificationRecorder::class);
                $appointment = $this->manualAppointment($client);
                $version = $recorder->bumpVersion($appointment);
                $recorder->record($appointment->fresh(), AppointmentNotificationEventType::CreatedByMaster, [], $version);

                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, AppointmentNotificationEvent::count());
        Queue::assertNothingPushed();
    }

    // ── Recovery ──

    public function test_recovery_redispaches_undispatched_events(): void
    {
        Queue::fake();
        $client = $this->client(['max_id' => 'max-1']);
        $this->manualAppointmentWithEvent($client);

        // Симулируем сбой после commit: dispatched_at = null
        AppointmentNotificationEvent::query()->update(['dispatched_at' => null]);

        $this->travel(5)->minutes();
        $this->artisan('appointments:recover-notification-events', ['--min-age' => 0])
            ->assertSuccessful();

        Queue::assertPushed(SendAppointmentNotificationJob::class);
        $this->assertNotNull(AppointmentNotificationEvent::first()->dispatched_at);
    }

    // ── Сериальные операции ──

    public function test_series_cancel_emits_one_series_event_not_many(): void
    {
        Queue::fake();
        $client = $this->client(['max_id' => 'max-1']);
        $series = RecurringAppointmentSeries::create([
            'workspace_id' => $this->workspace->id,
            'master_id' => $this->master->id,
            'client_id' => $client->id,
            'master_service_id' => $this->service->id,
            'start_date' => Carbon::now()->addDay()->format('Y-m-d'),
            'start_time' => '10:00',
            'recurrence_type' => RecurrenceType::Weekly,
            'interval' => 1,
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);

        $appointments = collect(range(1, 3))->map(fn ($i) => $this->manualAppointment($client, [
            'start_time' => Carbon::now('Europe/Moscow')->addWeeks($i)->setTime(10, 0),
            'recurring_series_id' => $series->id,
            'recurring_occurrence_date' => Carbon::now('Europe/Moscow')->addWeeks($i)->format('Y-m-d'),
        ]));

        $anchor = $appointments->first();

        $count = app(RecurringAppointmentService::class)
            ->cancelWholeSeries($series, $this->master);

        $this->assertSame(3, $count);

        // Одно series_cancelled, ноль cancelled_by_master
        $this->assertSame(1, AppointmentNotificationEvent::where('event_type', AppointmentNotificationEventType::SeriesCancelled)->count());
        $this->assertSame(0, AppointmentNotificationEvent::where('event_type', AppointmentNotificationEventType::CancelledByMaster)->count());
        Queue::assertPushed(SendAppointmentNotificationJob::class, 1);
    }

    // ── Итог UI-сводки ──

    public function test_summary_hides_secrets_and_shows_label(): void
    {
        $client = $this->client(['max_id' => 'max-1']);
        [$appointment, $event] = $this->manualAppointmentWithEvent($client);
        $this->assertNotNull($event);

        AppointmentNotificationDelivery::create([
            'appointment_notification_event_id' => $event->id,
            'channel' => 'max',
            'status' => AppointmentNotificationDelivery::STATUS_DELIVERED,
            'provider_message_id' => 'secret-mid-xyz',
            'delivered_at' => now(),
        ]);

        $summary = app(AppointmentNotificationSummary::class)
            ->forAppointments(collect([$appointment->fresh()]));

        $row = $summary[$appointment->id];
        $this->assertSame('sent', $row['status']);
        $this->assertSame('max', $row['channel']);
        $this->assertStringContainsString('MAX', $row['label']);
        // provider_message_id не отдаётся в UI
        $this->assertArrayNotHasKey('provider_message_id', $row);
        $this->assertStringNotContainsString('secret-mid-xyz', json_encode($row));
    }
}
