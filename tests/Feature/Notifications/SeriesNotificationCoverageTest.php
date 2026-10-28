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
use App\Services\VkApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Дефект 7: серийные операции инвалидируют старые задачи и напоминания
 * всех затронутых визитов, сообщение серии содержит новое расписание,
 * якорь не является единственным источником актуальности, получатели
 * обрабатываются по группам.
 */
class SeriesNotificationCoverageTest extends TestCase
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

    private function appointment(Client $client, Carbon $start, array $attrs = []): Appointment
    {
        return Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client)
            ->withMasterService($this->service)
            ->create(array_merge([
                'status' => AppointmentStatus::Booked,
                'source' => AppointmentSource::Admin,
                'start_time' => $start,
                'recurring_occurrence_date' => $start->format('Y-m-d'),
            ], $attrs));
    }

    private function makeSeries(Client $client, array $attrs = []): RecurringAppointmentSeries
    {
        return RecurringAppointmentSeries::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'master_id' => $this->master->id,
            'client_id' => $client->id,
            'master_service_id' => $this->service->id,
            'start_date' => Carbon::parse('2026-11-17')->format('Y-m-d'),
            'start_time' => '10:00',
            'recurrence_type' => RecurrenceType::Weekly,
            'interval' => 1,
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ], $attrs));
    }

    /**
     * Визиты серии по неделям, у каждого — своё created-событие
     * (как будто jobs ещё не обработаны).
     *
     * @return array{RecurringAppointmentSeries, Collection}
     */
    private function seriesWithVisits(Client $client, int $count = 3): array
    {
        $series = $this->makeSeries($client);
        $recorder = app(AppointmentNotificationRecorder::class);

        $visits = collect(range(1, $count))->map(function (int $i) use ($client, $series, $recorder) {
            $start = Carbon::parse('2026-11-17')->addWeeks($i - 1)->setTime(10, 0);
            $visit = $this->appointment($client, $start, [
                'recurring_series_id' => $series->id,
            ]);
            $version = $recorder->bumpVersion($visit);
            $event = $recorder->record(
                $visit->fresh(),
                AppointmentNotificationEventType::CreatedByMaster,
                [],
                $version,
            );
            $this->assertNotNull($event);

            return $visit;
        });

        return [$series, $visits];
    }

    private function assertNoOldJobSends(Appointment $visit, AppointmentNotificationEvent $event): void
    {
        $fresh = $visit->fresh();
        $this->assertGreaterThan(
            (int) $event->event_version,
            (int) $fresh->notification_version,
            "визит {$visit->id}: версия должна вырасти, чтобы старая job устарела",
        );

        $maxMock = $this->mock(MaxApiClient::class);
        $maxMock->shouldNotReceive('sendMessage');

        (new SendAppointmentNotificationJob($event->id))->handle(
            app(AppointmentChannelResolver::class),
            $maxMock,
            $this->mock(VkApiClient::class),
        );

        $delivered = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('status', AppointmentNotificationDelivery::STATUS_DELIVERED)
            ->exists();
        $this->assertFalse($delivered, "старая job визита {$visit->id} не должна отправлять подтверждение отменённой записи");
    }

    // ── Отмена серии: каждая запись инвалидирует старые jobs ──

    public function test_cancel_this_and_future_invalidates_old_jobs_of_every_visit(): void
    {
        $client = $this->client();
        [$series, $visits] = $this->seriesWithVisits($client);

        $events = $visits->map(fn (Appointment $v) => AppointmentNotificationEvent::where('appointment_id', $v->id)
            ->where('event_type', AppointmentNotificationEventType::CreatedByMaster)
            ->firstOrFail());

        app(RecurringAppointmentService::class)
            ->cancelThisAndFuture($series, $visits->first(), $this->master);

        $this->assertSame(AppointmentStatus::Cancelled, $visits->first()->fresh()->status);

        foreach ($visits as $i => $visit) {
            $fresh = $visit->fresh();
            if ($fresh->status === AppointmentStatus::Cancelled) {
                $this->assertNoOldJobSends($visit, $events[$i]);
            }
        }

        // Ровно одно series-сообщение, ноль индивидуальных.
        $this->assertSame(
            1,
            AppointmentNotificationEvent::where('event_type', AppointmentNotificationEventType::SeriesCancelled)->count(),
        );
        $this->assertSame(
            0,
            AppointmentNotificationEvent::where('event_type', AppointmentNotificationEventType::CancelledByMaster)->count(),
        );
    }

    public function test_cancel_whole_series_invalidates_old_jobs_of_every_visit(): void
    {
        $client = $this->client();
        [$series, $visits] = $this->seriesWithVisits($client);

        $events = $visits->map(fn (Appointment $v) => AppointmentNotificationEvent::where('appointment_id', $v->id)
            ->where('event_type', AppointmentNotificationEventType::CreatedByMaster)
            ->firstOrFail());

        app(RecurringAppointmentService::class)->cancelWholeSeries($series, $this->master);

        foreach ($visits as $i => $visit) {
            $this->assertNoOldJobSends($visit, $events[$i]);
        }
    }

    // ── Split: перебинденные экземпляры инвалидируют старые jobs ──

    public function test_split_rebind_invalidates_old_jobs_of_rebound_visits(): void
    {
        $client = $this->client();
        [$series, $visits] = $this->seriesWithVisits($client);

        $events = $visits->map(fn (Appointment $v) => AppointmentNotificationEvent::where('appointment_id', $v->id)
            ->where('event_type', AppointmentNotificationEventType::CreatedByMaster)
            ->firstOrFail());

        $splitAppt = $visits->first();
        $dates = $visits->map->recurring_occurrence_date->values()->all();

        app(RecurringAppointmentService::class)->splitSeries(
            $series,
            $splitAppt,
            [
                'recurrence_type' => RecurrenceType::Weekly->value,
                'interval' => 1,
                'start_time' => '14:00',
                'master_service_id' => $this->service->id,
                'occurrences_count' => 3,
            ],
            ['dates' => $dates],
        );

        // Каждый перебинденный экземпляр (включая якорный) должна получить
        // новую версию — иначе старая job ещё актуальна.
        foreach ($visits as $i => $visit) {
            $this->assertNoOldJobSends($visit, $events[$i]);
        }
    }

    // ── Сообщение серии содержит новое расписание ──

    public function test_series_updated_message_contains_new_time(): void
    {
        $client = $this->client();
        [$series, $visits] = $this->seriesWithVisits($client);

        app(RecurringAppointmentService::class)->splitSeries(
            $series,
            $visits->first(),
            [
                'recurrence_type' => RecurrenceType::Weekly->value,
                'interval' => 1,
                'start_time' => '14:30',
                'master_service_id' => $this->service->id,
                'occurrences_count' => 3,
            ],
            ['dates' => $visits->map->recurring_occurrence_date->values()->all()],
        );

        $event = AppointmentNotificationEvent::where('event_type', AppointmentNotificationEventType::SeriesUpdated)
            ->firstOrFail();

        $text = (string) $event->payload['text'];
        $this->assertStringContainsString('14:30', $text, 'series_updated должен сообщать новое время серии');
    }

    // ── Якорь не гасит серийное уведомление бесследно ──

    public function test_anchor_edit_does_not_kill_pending_series_notification(): void
    {
        $client = $this->client();
        $result = app(RecurringAppointmentService::class)->createSeries(
            $this->master,
            $client->id,
            $this->service->id,
            Carbon::parse('2026-11-17')->format('Y-m-d'),
            '10:00',
            RecurrenceType::Weekly,
            1,
            null,
            null,
            3,
            [
                '2026-11-17',
                '2026-11-24',
                '2026-12-01',
            ],
        );
        $this->assertInstanceOf(RecurringAppointmentSeries::class, $result);

        $seriesEvent = AppointmentNotificationEvent::where('event_type', AppointmentNotificationEventType::SeriesCreated)
            ->firstOrFail();

        $anchor = Appointment::findOrFail($seriesEvent->appointment_id);

        // Правка якорного визита после постановки series-события в очередь.
        $this->actingAs($this->master);
        $response = $this->patchJson("/admin/appointments/{$anchor->id}/status", ['price' => 4444]);
        $this->assertLessThan(400, $response->status(), $response->getContent());

        $this->assertNotSame(
            (int) $seriesEvent->event_version,
            (int) $anchor->fresh()->notification_version,
            'правка якоря меняет его версию',
        );

        // Job series-события всё ещё должна доставиться: правка одного
        // визита не гасит уведомление обо всей серии.
        $maxMock = $this->mock(MaxApiClient::class);
        $sent = null;
        $maxMock->shouldReceive('sendMessage')
            ->once()
            ->andReturnUsing(function (string $chatId, string $text) use (&$sent) {
                $sent = $text;

                return 'mid-1';
            });

        (new SendAppointmentNotificationJob($seriesEvent->id))->handle(
            app(AppointmentChannelResolver::class),
            $maxMock,
            $this->mock(VkApiClient::class),
        );

        $this->assertNotNull($sent, 'уведомление о серии не должно бесследно погаснуть из-за правки якоря');
        $this->assertStringContainsString('серию визитов', $sent);
    }

    // ── Разные получатели серии получают свои сообщения ──

    public function test_cancel_series_notifies_each_recipient_group_separately(): void
    {
        $clientA = $this->client(['max_id' => 'max-a']);
        $clientB = $this->client(['max_id' => 'max-b']);
        [$series, $visits] = $this->seriesWithVisits($clientA, 3);

        // Последний визит серии принадлежит другому клиенту.
        $lastVisit = $visits->last();
        $lastVisit->update(['client_id' => $clientB->id]);

        app(RecurringAppointmentService::class)->cancelWholeSeries($series, $this->master);

        $cancelEvents = AppointmentNotificationEvent::where('event_type', AppointmentNotificationEventType::SeriesCancelled)
            ->get();

        $this->assertCount(
            2,
            $cancelEvents,
            'получатели с разными клиентами должны получить отдельные series-сообщения',
        );

        $byAppointment = $cancelEvents->keyBy('appointment_id');
        $groupA = $byAppointment->get($visits->first()->id);
        $groupB = $byAppointment->get($lastVisit->id);

        $this->assertNotNull($groupA, 'клиент A получает сообщение по своим визитам');
        $this->assertNotNull($groupB, 'клиент B получает сообщение по своим визитам');

        $this->assertSame(2, (int) $groupA->payload['affected_count'], 'счётчик клиента A — только его визиты');
        $this->assertSame(1, (int) $groupB->payload['affected_count'], 'счётчик клиента B — только его визиты');
    }

    // ── Изменение одного визита инвалидирует его старую job ──

    public function test_edit_single_visit_invalidates_its_old_job(): void
    {
        $client = $this->client();
        [$series, $visits] = $this->seriesWithVisits($client, 1);
        $visit = $visits->first();
        $event = AppointmentNotificationEvent::where('appointment_id', $visit->id)
            ->where('event_type', AppointmentNotificationEventType::CreatedByMaster)
            ->firstOrFail();

        $result = app(BookingService::class)->rescheduleAppointment(
            $visit,
            Carbon::parse('2026-11-18')->format('Y-m-d'),
            '16:00',
            ignoreWarnings: true,
            confirmOutsideHours: true,
        );
        $this->assertTrue($result['success'], json_encode($result));

        $this->assertNoOldJobSends($visit, $event);
    }
}
