<?php

namespace Tests\Feature\Notifications;

use App\Enums\AppointmentNotificationEventType;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Events\AppointmentNotificationUpdated;
use App\Jobs\SendAppointmentNotificationJob;
use App\Models\Appointment;
use App\Models\AppointmentNotificationDelivery;
use App\Models\AppointmentNotificationEvent;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\User;
use App\Services\Notification\AppointmentNotificationSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Дефект 2 (P1): failed() не должен завершать чужое живое владение.
 *
 * Осиротевший live claim (lease жив, активного преемника в очереди нет)
 * не терминализируется немедленно: проверка очереди и обновление разделены,
 * между ними может появиться преемник (в т.ч. с уже pop'нутым job'ом —
 * строка jobs пуста, но исполнитель работает). CAS по прочитанному чужому
 * токену не доказывает владение.
 *
 * Контракт:
 * - failed() не трогает живые claims ни при каком состоянии очереди;
 * - recovery терминализирует осиротевший pending ПОСЛЕ истечения lease
 *   и рассылает AppointmentNotificationUpdated для обновления UI;
 * - Summary не показывает навсегда «Ожидает отправки».
 */
class TerminalTimeoutPendingTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    private MasterService $service;

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
            'queue.failed.database' => null,
        ]);

        $this->travelTo(Carbon::parse('2026-11-10 08:00:00', 'Europe/Moscow'));

        $this->master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow'],
        ]);
        $this->service = MasterService::factory()->forMaster($this->master)->create([
            'price_override' => 1500,
            'duration_override' => 60,
        ]);
        $this->client = Client::factory()->create([
            'user_id' => $this->master->id,
            'max_id' => 'max-1',
        ]);
    }

    private function appointment(array $attrs = []): Appointment
    {
        return Appointment::factory()
            ->forMaster($this->master)
            ->forClient($this->client)
            ->withMasterService($this->service)
            ->create(array_merge([
                'status' => AppointmentStatus::Booked,
                'source' => AppointmentSource::Admin,
                'start_time' => Carbon::parse('2026-11-12 10:00:00', 'Europe/Moscow'),
            ], $attrs));
    }

    private function seedEvent(Appointment $appointment): AppointmentNotificationEvent
    {
        return AppointmentNotificationEvent::create([
            'appointment_id' => $appointment->id,
            'event_type' => AppointmentNotificationEventType::CreatedByMaster,
            'event_version' => (int) $appointment->fresh()->notification_version,
            'payload' => [
                'text' => 'Мастер записал вас',
                'client_id' => $appointment->client_id,
            ],
            'dispatched_at' => now(),
        ]);
    }

    private function summary(AppointmentNotificationEvent $event): array
    {
        $appointment = Appointment::findOrFail($event->appointment_id);

        return app(AppointmentNotificationSummary::class)
            ->forAppointments(collect([$appointment]))[$appointment->id];
    }

    /**
     * Поздний failed() старого исполнителя: преемник захватил событие
     * (живой lease, свежий токен), его job уже pop'нут из очереди —
     * строки jobs нет. Старый failed() не должен завершить claim
     * преемника: CAS по чужому токену не доказывает владение.
     */
    public function test_late_failed_does_not_terminate_successor_live_claim(): void
    {
        $appointment = $this->appointment();
        $event = $this->seedEvent($appointment);

        // Claim преемника: живой lease, свежий токен, активного job'а в
        // очереди нет (worker уже pop'нул его — строка jobs пуста).
        $successorToken = (string) Str::uuid();
        AppointmentNotificationDelivery::create([
            'appointment_notification_event_id' => $event->id,
            'channel' => 'max',
            'status' => AppointmentNotificationDelivery::STATUS_PENDING,
            'claim_token' => $successorToken,
            'lease_expires_at' => now()->addSeconds(60),
        ]);

        (new SendAppointmentNotificationJob($event->id))
            ->failed(new TimeoutExceededException('timeout'));

        $row = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('channel', 'max')
            ->firstOrFail();

        $this->assertSame(
            AppointmentNotificationDelivery::STATUS_PENDING,
            $row->status,
            'failed() не должен завершать живой claim преемника при пустой очереди',
        );
        $this->assertSame($successorToken, (string) $row->claim_token);
        $this->assertNotNull($row->lease_expires_at);
        $this->assertTrue($row->lease_expires_at->isFuture());
    }

    /**
     * После истечения lease без преемника строка не должна остаться
     * вечным pending: recovery терминализирует осиротевший claim и
     * рассылает AppointmentNotificationUpdated для обновления UI.
     */
    public function test_recovery_fails_orphaned_pending_after_lease_expiry(): void
    {
        $appointment = $this->appointment();
        $event = $this->seedEvent($appointment);

        // Осиротевший claim: токен остался, lease истёк, преемника нет.
        AppointmentNotificationDelivery::create([
            'appointment_notification_event_id' => $event->id,
            'channel' => 'max',
            'status' => AppointmentNotificationDelivery::STATUS_PENDING,
            'claim_token' => (string) Str::uuid(),
            'lease_expires_at' => now()->subSeconds(10),
        ]);

        Event::fake([AppointmentNotificationUpdated::class]);

        $this->artisan('appointments:recover-notification-events', ['--min-age' => 0])
            ->assertExitCode(0);

        $row = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('channel', 'max')
            ->firstOrFail();

        $this->assertSame(
            AppointmentNotificationDelivery::STATUS_FAILED,
            $row->status,
            'recovery должна терминализировать осиротевший pending после lease',
        );
        $this->assertSame('orphaned_claim', $row->failure_reason);
        $this->assertSame('failed', $this->summary($event)['status']);

        Event::assertDispatched(
            AppointmentNotificationUpdated::class,
            fn (AppointmentNotificationUpdated $e): bool => $e->appointmentId === (string) $appointment->id,
        );
    }

    /**
     * Recovery НЕ трогает чужой живой claim (lease в будущем).
     */
    public function test_recovery_keeps_foreign_live_claim_pending(): void
    {
        $appointment = $this->appointment();
        $event = $this->seedEvent($appointment);

        $liveToken = (string) Str::uuid();
        AppointmentNotificationDelivery::create([
            'appointment_notification_event_id' => $event->id,
            'channel' => 'max',
            'status' => AppointmentNotificationDelivery::STATUS_PENDING,
            'claim_token' => $liveToken,
            'lease_expires_at' => now()->addSeconds(45),
        ]);

        $this->artisan('appointments:recover-notification-events', ['--min-age' => 0])
            ->assertExitCode(0);

        $row = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->firstOrFail();

        $this->assertSame(AppointmentNotificationDelivery::STATUS_PENDING, $row->status);
        $this->assertSame($liveToken, (string) $row->claim_token);
    }

    /**
     * Полный жизненный цикл: claim → HTTP висит → timeout → failed()
     * НЕ терминализирует живой claim → после истечения lease recovery
     * доводит строку до терминала, Summary не показывает pending.
     */
    public function test_timeout_then_recovery_leaves_no_eternal_pending(): void
    {
        config(['queue.default' => 'database']);
        config(['queue.failed.database' => null]);

        $appointment = $this->appointment();
        $event = $this->seedEvent($appointment);

        // Симуляция: handle() успел сделать claim, затем процесс умер по
        // timeout до HTTP. В БД — pending с живым lease, job в failed_jobs.
        $ownToken = (string) Str::uuid();
        AppointmentNotificationDelivery::create([
            'appointment_notification_event_id' => $event->id,
            'channel' => 'max',
            'status' => AppointmentNotificationDelivery::STATUS_PENDING,
            'claim_token' => $ownToken,
            'lease_expires_at' => now()->addSeconds(60),
        ]);

        DB::table('failed_jobs')->insert([
            'id' => 1,
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'uuid' => (string) Str::uuid(),
                'displayName' => SendAppointmentNotificationJob::class,
                'data' => [
                    'commandName' => SendAppointmentNotificationJob::class,
                    'command' => 'O:30:"App\Jobs\SendAppointmentNotificationJob":1:{s:7:"eventId";s:36:"'.$event->id.'";}',
                ],
            ]),
            'exception' => 'Illuminate\Queue\TimeoutExceededException',
            'failed_at' => now(),
        ]);

        // Failed-hook упавшего job'а (worker вызывает failed() после fail).
        (new SendAppointmentNotificationJob($event->id))
            ->failed(new TimeoutExceededException('timeout'));

        $row = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('channel', 'max')
            ->firstOrFail();

        $this->assertSame(
            AppointmentNotificationDelivery::STATUS_PENDING,
            $row->status,
            'failed() не должен терминализировать живой claim — это отложено до recovery',
        );

        // Lease истёк, преемник не появился — recovery терминализирует.
        $this->travel(70)->seconds();

        $this->artisan('appointments:recover-notification-events', ['--min-age' => 0])
            ->assertExitCode(0);

        $row->refresh();
        $this->assertSame(
            AppointmentNotificationDelivery::STATUS_FAILED,
            $row->status,
            'после lease recovery должна довести осиротевший pending до терминала',
        );
        $this->assertSame('orphaned_claim', $row->failure_reason);
        $this->assertSame('failed', $this->summary($event)['status']);
        $this->assertNotSame('pending', $this->summary($event)['status']);
    }
}
