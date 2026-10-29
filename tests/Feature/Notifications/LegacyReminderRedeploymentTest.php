<?php

namespace Tests\Feature\Notifications;

use App\Console\Commands\RedispatchLegacyReminders;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Jobs\SendAppointmentReminderJob;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Дефект 6 (P2): старые напоминания молча исчезнут при выкладке.
 *
 * Legacy-payload без новых полей (expectedScheduleVersion /
 * expectedStartTime) теперь намеренно ничего не отправляет.
 * Но reminder_*_dispatched_at у записи уже заполнен. Scheduler такую
 * запись повторно не подберёт.
 *
 * Это безопасный отказ от недоказуемо актуальной задачи, но без
 * восстановления он означает потерю действующего напоминания.
 *
 * Стратегия: artisan-команда безопасно перевыставляет только необходимые
 * актуальные напоминания. Команда ограничена legacy-состоянием:
 * - записи с versioned-job'ом в очереди не сбрасываются (иначе дубль);
 * - UPDATE пинит прочитанное значение dispatched_at (запись, изменившаяся
 *   после выборки, не сбрасывается);
 * - подтверждённые/провалённые/вне окна не трогаются.
 * Не очищает очередь целиком, не рассылает историю, не отправляет
 * повторно уже подтверждённые.
 */
class LegacyReminderRedeploymentTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    private MasterService $service;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

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
                'start_time' => now()->addHours(24),
                'reminder_24h_dispatched_at' => now()->subHour(),
            ], $attrs));
    }

    /**
     * Красный: запись с legacy-dispatched_at (нет sent/failed) внутри
     * окна 24h. Команда должна сбросить dispatched_at, чтобы scheduler
     * мог перевыставить актуальный payload со schedule_version.
     */
    public function test_command_resets_dispatched_at_for_pending_24h_reminder_in_window(): void
    {
        // start_time ~24h от now: within [now+23h, now+25h].
        $appointment = $this->appointment([
            'start_time' => now()->addHours(24),
            'reminder_24h_dispatched_at' => now()->subMinutes(30),
        ]);

        $this->assertNotNull($appointment->reminder_24h_dispatched_at);

        $this->artisan('appointments:reminders:redispatch-legacy')
            ->assertExitCode(0);

        $fresh = $appointment->fresh();
        $this->assertNull(
            $fresh->reminder_24h_dispatched_at,
            'legacy-dispatched 24h в окне должен быть сброшен для повторной постановки',
        );
        $this->assertNull($fresh->reminder_24h_sent_at);
        $this->assertNull($fresh->reminder_24h_failed_at);
    }

    /**
     * Подтверждённые напоминания НЕ трогаются (не отправлять повторно).
     */
    public function test_command_skips_already_sent_reminders(): void
    {
        $appointment = $this->appointment([
            'start_time' => now()->addHours(24),
            'reminder_24h_dispatched_at' => now()->subMinutes(30),
            'reminder_24h_sent_at' => now()->subMinutes(20),
        ]);

        $this->artisan('appointments:reminders:redispatch-legacy')
            ->assertExitCode(0);

        $fresh = $appointment->fresh();
        $this->assertNotNull(
            $fresh->reminder_24h_dispatched_at,
            'подтверждённое напоминание не должно сбрасываться',
        );
        $this->assertNotNull($fresh->reminder_24h_sent_at);
    }

    /**
     * Вне окна — не трогаем (не рассылать историю).
     */
    public function test_command_skips_reminder_outside_window(): void
    {
        // start_time через 5 дней — далеко за пределами 24h-окна.
        $appointment = $this->appointment([
            'start_time' => now()->addDays(5),
            'reminder_24h_dispatched_at' => now()->subHour(),
        ]);

        $this->artisan('appointments:reminders:redispatch-legacy')
            ->assertExitCode(0);

        $this->assertNotNull(
            $appointment->fresh()->reminder_24h_dispatched_at,
            'вне окна dispatched_at не сбрасывается',
        );
    }

    /**
     * Уже проваленные — не трогаем.
     */
    public function test_command_skips_failed_reminders(): void
    {
        $appointment = $this->appointment([
            'start_time' => now()->addHours(24),
            'reminder_24h_dispatched_at' => now()->subMinutes(30),
            'reminder_24h_failed_at' => now()->subMinutes(10),
        ]);

        $this->artisan('appointments:reminders:redispatch-legacy')
            ->assertExitCode(0);

        $this->assertNotNull(
            $appointment->fresh()->reminder_24h_dispatched_at,
            'проваленное напоминание не сбрасывается',
        );
    }

    /**
     * Отменённые записи — не трогаем.
     */
    public function test_command_skips_cancelled_appointments(): void
    {
        $appointment = $this->appointment([
            'status' => AppointmentStatus::Cancelled,
            'start_time' => now()->addHours(24),
            'reminder_24h_dispatched_at' => now()->subMinutes(30),
        ]);

        $this->artisan('appointments:reminders:redispatch-legacy')
            ->assertExitCode(0);

        $this->assertNotNull(
            $appointment->fresh()->reminder_24h_dispatched_at,
            'отменённая запись не трогается',
        );
    }

    /**
     * Final-напоминания: та же логика для reminder_final_*.
     */
    public function test_command_resets_dispatched_at_for_pending_final_reminder_in_window(): void
    {
        // start_time через 2 часа — внутри final-окна (now, now+12h].
        $appointment = $this->appointment([
            'start_time' => now()->addHours(2),
            'reminder_24h_dispatched_at' => null,
            'reminder_final_dispatched_at' => now()->subMinutes(15),
        ]);
        $this->master->update([
            'settings' => array_merge($this->master->settings ?? [], [
                'reminder_hours_before_final' => 3,
            ]),
        ]);

        $this->artisan('appointments:reminders:redispatch-legacy')
            ->assertExitCode(0);

        $fresh = $appointment->fresh();
        $this->assertNull(
            $fresh->reminder_final_dispatched_at,
            'legacy-dispatched final в окне должен быть сброшен',
        );
    }

    /**
     * После сброса scheduler ставит job с актуальным schedule_version.
     */
    public function test_scheduler_redispatches_after_reset(): void
    {
        $appointment = $this->appointment([
            'start_time' => now()->addHours(24),
            'reminder_24h_dispatched_at' => now()->subMinutes(30),
        ]);

        $this->artisan('appointments:reminders:redispatch-legacy')
            ->assertExitCode(0);

        Queue::fake();

        $this->artisan('appointments:reminders')
            ->assertExitCode(0);

        Queue::assertPushed(SendAppointmentReminderJob::class, function (SendAppointmentReminderJob $job) use ($appointment) {
            // Актуальный payload: schedule_version зафиксирован.
            $prop = new \ReflectionProperty(SendAppointmentReminderJob::class, 'appointment');
            $prop->setAccessible(true);

            return $job->expectedScheduleVersion === (int) $appointment->fresh()->schedule_version
                && $prop->getValue($job)->id === $appointment->id;
        });
    }

    /**
     * Новая versioned-job в очереди: запись принадлежит новому поколению
     * постановки. Сброс дал бы ДУБЛЬ после её выполнения — команда не
     * должна трогать такие записи.
     */
    public function test_command_keeps_dispatched_at_when_versioned_job_is_queued(): void
    {
        $appointment = $this->appointment([
            'start_time' => now()->addHours(24),
            'reminder_24h_dispatched_at' => now()->subMinutes(30),
        ]);

        // Реальный versioned-job в database-очереди (expectedScheduleVersion
        // зафиксирован в payload).
        DB::table('jobs')->insert([
            'id' => 1,
            'queue' => 'default',
            'payload' => json_encode([
                'uuid' => (string) Str::uuid(),
                'displayName' => SendAppointmentReminderJob::class,
                'job' => 'Illuminate\Queue\CallQueuedHandler@call',
                'maxTries' => null,
                'timeout' => null,
                'data' => [
                    'commandName' => SendAppointmentReminderJob::class,
                    'command' => serialize(new SendAppointmentReminderJob($appointment, '24h')),
                ],
                'id' => (string) Str::uuid(),
            ]),
            'attempts' => 1,
            'reserved_at' => now()->getTimestamp(),
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ]);

        $this->artisan('appointments:reminders:redispatch-legacy')
            ->assertExitCode(0);

        $this->assertNotNull(
            $appointment->fresh()->reminder_24h_dispatched_at,
            'versioned-job в очереди — сброс недопустим, иначе дубль',
        );

        // Scheduler не должен перевыставить дубль versioned-job'а.
        Queue::fake();
        $this->artisan('appointments:reminders')->assertExitCode(0);
        Queue::assertNothingPushed();
    }

    /**
     * Запись, изменившаяся после выборки (параллельная постановка сдвинула
     * dispatched_at), не сбрасывается: UPDATE пинит прочитанное значение.
     */
    public function test_command_does_not_reset_row_changed_after_selection(): void
    {
        $originalDispatchedAt = now()->subMinutes(30);
        $appointment = $this->appointment([
            'start_time' => now()->addHours(24),
            'reminder_24h_dispatched_at' => $originalDispatchedAt,
        ]);

        // «Параллельная постановка» между выборкой и UPDATE: другое
        // значение dispatched_at.
        $concurrentDispatchedAt = now()->subMinutes(10);

        $command = new class extends RedispatchLegacyReminders
        {
            public ?Appointment $mutate = null;

            public ?\DateTimeInterface $concurrentDispatchedAt = null;

            protected function resetOne(
                Appointment $appointment,
                string $dispatchedField,
                string $sentField,
                string $failedField,
            ): bool {
                if ($this->mutate !== null
                    && $appointment->is($this->mutate)
                    && $this->concurrentDispatchedAt !== null) {
                    DB::table('appointments')
                        ->where('id', $appointment->id)
                        ->update([$dispatchedField => $this->concurrentDispatchedAt]);
                }

                return parent::resetOne($appointment, $dispatchedField, $sentField, $failedField);
            }
        };
        $command->setLaravel($this->app);
        $command->mutate = $appointment;
        $command->concurrentDispatchedAt = $concurrentDispatchedAt;

        $exitCode = $command->run(new ArrayInput([]), new BufferedOutput);
        $this->assertSame(0, $exitCode);

        $fresh = $appointment->fresh();
        $this->assertNotNull(
            $fresh->reminder_24h_dispatched_at,
            'запись, изменившаяся после выборки, не должна сбрасываться',
        );
        $this->assertSame(
            $concurrentDispatchedAt->getTimestamp(),
            $fresh->reminder_24h_dispatched_at->getTimestamp(),
            'значение, записанное параллельной постановкой, должно сохраниться',
        );
    }
}
