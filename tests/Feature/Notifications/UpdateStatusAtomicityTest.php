<?php

namespace Tests\Feature\Notifications;

use App\Enums\AppointmentNotificationEventType;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Jobs\SendAppointmentNotificationJob;
use App\Models\Appointment;
use App\Models\AppointmentNotificationEvent;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Booking\BookingService;
use App\Services\MaxApiClient;
use App\Services\Notification\AppointmentChannelResolver;
use App\Services\VkApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Дефекты 5–6: одно пользовательское сохранение = одна транзакция и одно
 * согласованное сообщение; смена услуги проверяет принадлежность и
 * занятость итогового интервала существующим механизмом доступности.
 */
class UpdateStatusAtomicityTest extends TestCase
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

    private function appointment(Client $client, array $attrs = []): Appointment
    {
        return Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client)
            ->withMasterService($this->service)
            ->create(array_merge([
                'status' => AppointmentStatus::Booked,
                'source' => AppointmentSource::Admin,
                'start_time' => Carbon::parse('2026-11-12 10:00:00', 'Europe/Moscow'),
                'duration' => 60,
                'price' => 1500,
            ], $attrs));
    }

    private function patchStatus(Appointment $appointment, array $payload)
    {
        return $this->actingAs($this->master)
            ->patchJson("/admin/appointments/{$appointment->id}/status", $payload);
    }

    // ── Дефект 5: одно сохранение — одно согласованное сообщение ──

    public function test_reschedule_with_price_change_records_single_coherent_event(): void
    {
        $appointment = $this->appointment($this->client());
        $versionBefore = (int) $appointment->notification_version;

        $response = $this->patchStatus($appointment, [
            'start_time' => '2026-11-13 15:00:00',
            'price' => 2900,
        ]);
        $this->assertLessThan(400, $response->status(), $response->getContent());

        $events = AppointmentNotificationEvent::where('appointment_id', $appointment->id)->get();
        $this->assertCount(
            1,
            $events,
            'перенос + смена цены одним запросом = одно согласованное сообщение',
        );

        $event = $events->first();
        $this->assertSame(
            AppointmentNotificationEventType::Rescheduled,
            $event->event_type,
            'уведомление о переносе не должно подавляться поправкой полей',
        );

        $fresh = $appointment->fresh();
        $this->assertSame(
            (int) $fresh->notification_version,
            (int) $event->event_version,
            'событие не должно устаревать из-за второй части того же сохранения',
        );
        $this->assertStringContainsString('перенесена', (string) $event->payload['text']);
        $this->assertStringContainsString('Стоимость', (string) $event->payload['text'], 'изменение цены должно попасть в то же сообщение');
    }

    public function test_cancel_with_fields_records_only_cancellation_message(): void
    {
        $appointment = $this->appointment($this->client());

        $response = $this->patchStatus($appointment, [
            'status' => 'cancelled',
            'price' => 900,
        ]);
        $this->assertLessThan(400, $response->status(), $response->getContent());

        $events = AppointmentNotificationEvent::where('appointment_id', $appointment->id)->get();
        $cancelEvents = $events->filter(
            fn ($e) => $e->event_type === AppointmentNotificationEventType::CancelledByMaster,
        );
        $fieldEvents = $events->filter(
            fn ($e) => $e->event_type === AppointmentNotificationEventType::FieldsChanged,
        );

        $this->assertCount(1, $cancelEvents, 'отмена должна породить своё событие');
        $this->assertCount(
            0,
            $fieldEvents,
            'после отмены не должно уходить последующее «Ждём вас» из fields_changed',
        );

        $fresh = $appointment->fresh();
        $this->assertSame(AppointmentStatus::Cancelled, $fresh->status);
        $this->assertSame(900.0, (float) $fresh->price, 'изменение цены применяется молча');

        $text = (string) $cancelEvents->first()->payload['text'];
        $this->assertStringContainsString('Мастер отменил запись', $text);
        $this->assertStringNotContainsString('Ждём вас', $text);
    }

    public function test_error_in_second_part_leaves_nothing_applied(): void
    {
        $appointment = $this->appointment($this->client());
        $versionBefore = (int) $appointment->notification_version;

        // status=cancelled + перенос: после отмены перенос невозможен —
        // ошибка второй части не должна оставить первую уже применённой.
        $response = $this->patchStatus($appointment, [
            'status' => 'cancelled',
            'start_time' => '2026-11-13 15:00:00',
        ]);

        $this->assertSame(422, $response->status(), $response->getContent());

        $fresh = $appointment->fresh();
        $this->assertSame(
            AppointmentStatus::Booked,
            $fresh->status,
            'ошибка второй части не должна оставлять первую уже применённой',
        );
        $this->assertSame(
            $versionBefore,
            (int) $fresh->notification_version,
            'версия не должна измениться при откате',
        );
        $this->assertSame(
            0,
            AppointmentNotificationEvent::where('appointment_id', $appointment->id)->count(),
        );
    }

    public function test_create_then_change_before_delivery_sends_full_understandable_message(): void
    {
        $client = $this->client();

        // Ручное создание через BookingService — как в проде: outbox-событие
        // created_by_master поставлено в очередь, но ещё не обработано.
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
            ->first();
        $this->assertNotNull($created, 'ручная запись должна породить created_by_master');

        $response = $this->patchStatus($appointment, ['price' => 2100]);
        $this->assertLessThan(400, $response->status(), $response->getContent());

        $changed = AppointmentNotificationEvent::where('appointment_id', $appointment->id)
            ->where('event_type', AppointmentNotificationEventType::FieldsChanged)
            ->firstOrFail();

        $maxMock = $this->mock(MaxApiClient::class);
        $sent = null;
        $maxMock->shouldReceive('sendMessage')
            ->once()
            ->andReturnUsing(function (string $chatId, string $text) use (&$sent) {
                $sent = $text;

                return 'mid-1';
            });

        (new SendAppointmentNotificationJob($changed->id))->handle(
            app(AppointmentChannelResolver::class),
            $maxMock,
            $this->mock(VkApiClient::class),
        );

        $this->assertNotNull($sent, 'изменение должно доставиться');
        $this->assertStringContainsString(
            'Мастер записал вас',
            $sent,
            'клиент обязан понимать, куда и когда он записан, а не получить одну непонятную поправку',
        );
        $this->assertStringContainsString('12.11.2026', $sent);
        $this->assertStringContainsString('10:00', $sent);
        $this->assertStringContainsString('2 100', $sent, 'изменение цены должно присутствовать в объединённом сообщении');
    }

    // ── Дефект 6: доступ к услуге и занятость итогового интервала ──

    public function test_foreign_service_is_rejected_without_changes_or_events(): void
    {
        $foreignMaster = User::factory()->master()->create();
        $foreignWorkspace = Workspace::create([
            'name' => 'ws-foreign-'.$foreignMaster->id,
            'owner_id' => $foreignMaster->id,
        ]);
        $foreignWorkspace->ensureSlug();
        $foreignMaster->update(['workspace_id' => $foreignWorkspace->id]);
        $foreignService = MasterService::factory()->forMaster($foreignMaster)->create([
            'price_override' => 5000,
            'duration_override' => 90,
        ]);

        $appointment = $this->appointment($this->client());
        $versionBefore = (int) $appointment->notification_version;

        $response = $this->patchStatus($appointment, ['service_id' => $foreignService->id]);

        $this->assertSame(403, $response->status(), $response->getContent());

        $fresh = $appointment->fresh();
        $this->assertSame($this->service->id, $fresh->master_service_id, 'чужая услуга не должна применяться');
        $this->assertSame($versionBefore, (int) $fresh->notification_version);
        $this->assertSame(
            0,
            AppointmentNotificationEvent::where('appointment_id', $appointment->id)
                ->where('event_type', AppointmentNotificationEventType::FieldsChanged)
                ->count(),
            'чужая услуга не должна порождать событие',
        );
    }

    public function test_duration_increase_into_occupied_interval_is_rejected(): void
    {
        $client = $this->client();
        // Сосед занимает 11:00–12:00.
        $neighbour = $this->appointment($client, [
            'start_time' => Carbon::parse('2026-11-12 11:00:00', 'Europe/Moscow'),
            'duration' => 60,
        ]);
        $appointment = $this->appointment($client);

        $versionBefore = (int) $appointment->notification_version;

        // 10:00 + 120мин = 12:00 → пересекается с соседом 11:00.
        $response = $this->patchStatus($appointment, ['duration' => 120]);

        $this->assertSame(422, $response->status(), $response->getContent());

        $fresh = $appointment->fresh();
        $this->assertSame(60, (int) $fresh->duration, 'увеличенная длительность в занятый интервал не должна применяться');
        $this->assertSame($versionBefore, (int) $fresh->notification_version);
        $this->assertSame(
            0,
            AppointmentNotificationEvent::where('appointment_id', $appointment->id)
                ->where('event_type', AppointmentNotificationEventType::FieldsChanged)
                ->count(),
        );
        $this->assertNotNull($neighbour->fresh());
    }

    public function test_same_service_change_in_free_interval_is_allowed(): void
    {
        $client = $this->client();
        $appointment = $this->appointment($client);

        $replacement = MasterService::factory()->forMaster($this->master)->create([
            'price_override' => 3000,
            'duration_override' => 45,
        ]);

        $response = $this->patchStatus($appointment, ['service_id' => $replacement->id]);

        $this->assertLessThan(400, $response->status(), $response->getContent());

        $fresh = $appointment->fresh();
        $this->assertSame($replacement->id, $fresh->master_service_id);
        $this->assertSame(
            1,
            AppointmentNotificationEvent::where('appointment_id', $appointment->id)
                ->where('event_type', AppointmentNotificationEventType::FieldsChanged)
                ->count(),
        );
    }
}
