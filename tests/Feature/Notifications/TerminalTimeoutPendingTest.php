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
use App\Services\Notification\AppointmentNotificationSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Дефект 2 (P1): окончательный timeout после claim оставляет вечный pending.
 *
 * timeout 30s < lease 60s: когда worker фейлит job по timeout, claim ещё
 * жив. failed() вызывает markAllPendingFailed/markFailed, но живой claim
 * они считают чужим и пропускают. Строка остаётся pending; job в
 * failed_jobs, dispatched_at заполнен, recovery не поднимает. Истечение
 * lease само по себе статус не меняет.
 *
 * Контракт:
 * - окончательный timeout при живом lease терминализирует строку, если
 *   для события нет активного преемника в очереди;
 * - поздний failed() старого исполнителя не повреждает claim нового
 *   владельца;
 * - после истечения lease не остаётся вечного pending (recovery);
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
     * Claim жив (lease 60s), job терминально упал по timeout (30s).
     * Нет преемника в очереди → строка обязана стать failed, а не
     * висеть pending до скончания веков.
     */
    public function test_final_timeout_with_live_claim_persists_terminal_failed(): void
    {
        $appointment = $this->appointment();
        $event = $this->seedEvent($appointment);

        // Claim жив: токен + lease в будущем (как после claim в handle()).
        $ownToken = (string) Str::uuid();
        AppointmentNotificationDelivery::create([
            'appointment_notification_event_id' => $event->id,
            'channel' => 'max',
            'status' => AppointmentNotificationDelivery::STATUS_PENDING,
            'claim_token' => $ownToken,
            'lease_expires_at' => now()->addSeconds(60),
        ]);

        // Job уже не в очереди (timeout убил процесс; failed_jobs/диспатч —
        // за пределами этого снапшота). Прецемника нет.
        (new SendAppointmentNotificationJob($event->id))
            ->failed(new TimeoutExceededException('timeout'));

        $row = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('channel', 'max')
            ->firstOrFail();

        $this->assertSame(
            AppointmentNotificationDelivery::STATUS_FAILED,
            $row->status,
            'окончательный timeout при живом lease без преемника должен терминализировать строку',
        );
        $this->assertNull($row->claim_token, 'терминальная строка не хранит claim');
        $this->assertNotNull($row->failed_at);
        $this->assertSame('retries_exhausted', $row->failure_reason);

        $this->assertSame('failed', $this->summary($event)['status']);
    }

    /**
     * Прецемник реально работает: его claim жив И его job в очереди
     * (reserved). Поздний failed() старого исполнителя не должен
     * повреждать владение преемника.
     */
    public function test_late_failed_does_not_damage_live_successor_claim(): void
    {
        $appointment = $this->appointment();
        $event = $this->seedEvent($appointment);

        $successorToken = (string) Str::uuid();
        AppointmentNotificationDelivery::create([
            'appointment_notification_event_id' => $event->id,
            'channel' => 'max',
            'status' => AppointmentNotificationDelivery::STATUS_PENDING,
            'claim_token' => $successorToken,
            'lease_expires_at' => now()->addSeconds(60),
        ]);

        // Преемник в очереди (reserved/available) — payload содержит eventId.
        DB::table('jobs')->insert([
            'id' => 1,
            'queue' => 'default',
            'payload' => json_encode([
                'uuid' => (string) Str::uuid(),
                'displayName' => SendAppointmentNotificationJob::class,
                'job' => 'Illuminate\Queue\CallQueuedHandler@call',
                'maxTries' => null,
                'timeout' => null,
                'data' => [
                    'commandName' => SendAppointmentNotificationJob::class,
                    'command' => 'O:30:"App\Jobs\SendAppointmentNotificationJob":1:{s:7:"eventId";s:36:"'.$event->id.'";}',
                ],
                'id' => (string) Str::uuid(),
            ]),
            'attempts' => 1,
            'reserved_at' => now()->getTimestamp(),
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
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
            'claim работающего преемника должен остаться нетронутым',
        );
        $this->assertSame($successorToken, (string) $row->claim_token);
        $this->assertNotNull($row->lease_expires_at);
        $this->assertTrue($row->lease_expires_at->isFuture());
    }

    /**
     * После истечения lease без преемника строка не должна остаться
     * вечным pending: recovery терминализирует осиротевший claim.
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
     * Полный жизненный цикл: claim → HTTP висит → timeout терминализирует
     * → Summary не показывает pending.
     */
    public function test_timeout_after_claim_via_worker_leaves_no_eternal_pending(): void
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
            AppointmentNotificationDelivery::STATUS_FAILED,
            $row->status,
            'после timeout-failed() строка не должна остаться pending при живом lease',
        );
        $this->assertSame('failed', $this->summary($event)['status']);
        $this->assertNotSame('pending', $this->summary($event)['status']);
    }
}
