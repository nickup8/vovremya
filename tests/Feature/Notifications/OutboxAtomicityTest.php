<?php

namespace Tests\Feature\Notifications;

use App\Enums\AppointmentNotificationEventType;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Enums\RecurrenceType;
use App\Enums\RecurringSeriesStatus;
use App\Models\Appointment;
use App\Models\AppointmentNotificationEvent;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\RecurringAppointmentSeries;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Booking\BookingService;
use App\Services\Booking\RecurringAppointmentService;
use App\Services\Notification\AppointmentNotificationComposer;
use App\Services\Notification\AppointmentNotificationRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Дефект 4: изменение + версия + outbox — одна транзакция; запись
 * события атомарна с мутацией; unique-конфликт не отравляет внешнюю
 * транзакцию (savepoint).
 */
class OutboxAtomicityTest extends TestCase
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
                'start_time' => Carbon::now('Europe/Moscow')->addDays(3)->setTime(10, 0),
            ], $attrs));
    }

    /**
     * Composer, падающий при составлении указанного типа события.
     *
     * @param  class-string<AppointmentNotificationEventType>  $type
     */
    private function explodingComposer(string $type): void
    {
        $this->app->instance(
            AppointmentNotificationComposer::class,
            new class($type) extends AppointmentNotificationComposer
            {
                /** @param class-string<AppointmentNotificationEventType> $type */
                public function __construct(private string $type) {}

                public function compose(
                    Appointment $appointment,
                    AppointmentNotificationEventType $type,
                    array $payload,
                ): ?string {
                    if ($type->value === $this->type) {
                        throw new \RuntimeException('compose failed');
                    }

                    return parent::compose($appointment, $type, $payload);
                }
            },
        );
    }

    // ── transition(): статус + версия + событие — одна транзакция ──

    public function test_status_transition_rolls_back_when_event_record_fails(): void
    {
        $client = $this->client();
        $appointment = $this->appointment($client);
        $versionBefore = (int) $appointment->notification_version;

        $this->explodingComposer(AppointmentNotificationEventType::CancelledByMaster->value);

        try {
            app(BookingService::class)->cancel($appointment, $this->master);
            $this->fail('compose failure must propagate');
        } catch (\RuntimeException) {
        }

        $fresh = $appointment->fresh();
        $this->assertSame(
            AppointmentStatus::Booked,
            $fresh->status,
            'сбой записи события должен откатить смену статуса',
        );
        $this->assertSame($versionBefore, (int) $fresh->notification_version, 'версия не должна измениться при откате');
        $this->assertSame(
            0,
            AppointmentNotificationEvent::where('appointment_id', $appointment->id)->count(),
            'событий после отката быть не должно',
        );
    }

    public function test_status_transition_change_version_and_event_commit_atomically(): void
    {
        $client = $this->client();
        $appointment = $this->appointment($client);

        DB::transaction(function () use ($appointment) {
            app(BookingService::class)->cancel($appointment, $this->master);
        });

        $fresh = $appointment->fresh();
        $this->assertSame(AppointmentStatus::Cancelled, $fresh->status);
        $event = AppointmentNotificationEvent::where('appointment_id', $appointment->id)
            ->where('event_type', AppointmentNotificationEventType::CancelledByMaster)
            ->first();
        $this->assertNotNull($event);
        $this->assertSame((int) $fresh->notification_version, (int) $event->event_version);
    }

    // ── splitSeries: событие внутри транзакции ──

    public function test_split_series_rolls_back_when_series_event_record_fails(): void
    {
        $client = $this->client();
        $series = $this->makeSeries($client);

        // Три визита: сегодня+7, +14, +21 (все будущие).
        $appointments = collect(range(0, 2))->map(fn (int $i) => $this->appointment($client, [
            'start_time' => Carbon::now('Europe/Moscow')->addDays(7 + $i * 7)->setTime(10, 0),
            'recurring_series_id' => $series->id,
            'recurring_occurrence_date' => Carbon::now('Europe/Moscow')->addDays(7 + $i * 7)->format('Y-m-d'),
        ]));

        $splitAppt = $appointments->first();
        $oldStart = $splitAppt->start_time->toIso8601String();

        $service = app(RecurringAppointmentService::class);

        // Превью: все три визита остаются в серии, но переезжают на 12:00.
        $previewDates = $appointments->map(
            fn (Appointment $a) => $a->recurring_occurrence_date,
        )->values()->all();

        $this->explodingComposer(AppointmentNotificationEventType::SeriesUpdated->value);

        try {
            $service->splitSeries(
                $series,
                $splitAppt,
                [
                    'recurrence_type' => RecurrenceType::Weekly->value,
                    'interval' => 1,
                    'start_time' => '12:00',
                ],
                ['dates' => $previewDates],
            );
            $this->fail('compose failure must propagate');
        } catch (\RuntimeException) {
        }

        // Всё откатилось: визиты остались в старой серии и на старом времени.
        $this->assertSame(
            $oldStart,
            $appointments->first()->fresh()->start_time->toIso8601String(),
            'первая запись не должна измениться при откате',
        );
        foreach ($appointments as $appt) {
            $fresh = $appt->fresh();
            $this->assertSame(
                $series->id,
                $fresh->recurring_series_id,
                'распределение по сериям должно откатиться',
            );
        }
        $this->assertSame(
            0,
            AppointmentNotificationEvent::where('event_type', AppointmentNotificationEventType::SeriesUpdated)->count(),
        );
    }

    // ── record(): unique-конфликт не отравляет внешнюю транзакцию ──

    public function test_duplicate_record_keeps_outer_transaction_usable(): void
    {
        $client = $this->client();
        $appointment = $this->appointment($client);
        $second = $this->appointment($client, [
            'start_time' => Carbon::now('Europe/Moscow')->addDays(4)->setTime(11, 0),
        ]);

        $recorder = app(AppointmentNotificationRecorder::class);
        $version = $recorder->bumpVersion($appointment);

        $duplicateHandled = false;
        $secondCommitted = false;

        try {
            DB::transaction(function () use ($recorder, $appointment, $second, $version, &$duplicateHandled, &$secondCommitted) {
                $first = $recorder->record(
                    $appointment->fresh(),
                    AppointmentNotificationEventType::CreatedByMaster,
                    [],
                    $version,
                );
                $this->assertNotNull($first);

                // Дубль той же идентичности должен быть пойман без отравления
                // внешней транзакции.
                $dup = $recorder->record(
                    $appointment->fresh(),
                    AppointmentNotificationEventType::CreatedByMaster,
                    [],
                    $version,
                );
                $this->assertNull($dup, 'дубль события должен быть пойман и вернуть null');
                $duplicateHandled = true;

                // Внешняя транзакция всё ещё рабочая: следующая запись проходит.
                $v2 = $recorder->bumpVersion($second);
                $next = $recorder->record(
                    $second->fresh(),
                    AppointmentNotificationEventType::CreatedByMaster,
                    [],
                    $v2,
                );
                $this->assertNotNull($next, 'внешняя транзакция должна оставаться рабочей после дубля');
                $secondCommitted = true;
            });
        } catch (\Throwable $e) {
            $this->fail('внешняя транзакция отравлена: '.$e->getMessage());
        }

        $this->assertTrue($duplicateHandled);
        $this->assertTrue($secondCommitted);
        $this->assertSame(
            1,
            AppointmentNotificationEvent::where('appointment_id', $appointment->id)
                ->where('event_type', AppointmentNotificationEventType::CreatedByMaster)
                ->count(),
            'дубль не должен создать второе событие',
        );
        $this->assertSame(
            1,
            AppointmentNotificationEvent::where('appointment_id', $second->id)->count(),
            'запись после дубля должна закоммититься',
        );
    }

    // ── Откат внешней транзакции не оставляет ничего ──

    public function test_outer_rollback_leaves_no_changes_events_or_jobs(): void
    {
        $client = $this->client();
        $appointment = $this->appointment($client);
        $versionBefore = (int) $appointment->notification_version;

        try {
            DB::transaction(function () use ($appointment) {
                $recorder = app(AppointmentNotificationRecorder::class);
                $version = $recorder->bumpVersion($appointment);
                $recorder->record(
                    $appointment->fresh(),
                    AppointmentNotificationEventType::CreatedByMaster,
                    [],
                    $version,
                );

                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $fresh = $appointment->fresh();
        $this->assertSame($versionBefore, (int) $fresh->notification_version);
        $this->assertSame(0, AppointmentNotificationEvent::count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    private function makeSeries(Client $client): RecurringAppointmentSeries
    {
        return RecurringAppointmentSeries::create([
            'workspace_id' => $this->workspace->id,
            'master_id' => $this->master->id,
            'client_id' => $client->id,
            'master_service_id' => $this->service->id,
            'start_date' => Carbon::now('Europe/Moscow')->addDays(7)->format('Y-m-d'),
            'start_time' => '10:00',
            'recurrence_type' => RecurrenceType::Weekly,
            'interval' => 1,
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);
    }
}
