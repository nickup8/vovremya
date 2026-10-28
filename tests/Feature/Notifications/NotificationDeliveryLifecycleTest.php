<?php

namespace Tests\Feature\Notifications;

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
use App\Models\User;
use App\Services\MaxApiClient;
use App\Services\Notification\AppointmentChannelResolver;
use App\Services\Notification\AppointmentNotificationRecorder;
use App\Services\Notification\AppointmentNotificationSummary;
use App\Services\VkApiClient;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Дефекты 2–3: владение claim при смене канала и устойчивое хранение
 * терминальных исходов (failed / superseded / no_channel).
 */
class NotificationDeliveryLifecycleTest extends TestCase
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
        ]);

        $this->travelTo(Carbon::parse('2026-11-10 08:00:00', 'Europe/Moscow'));

        $this->master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow'],
            'address' => 'ул. Тестовая, 1',
        ]);
        $this->service = MasterService::factory()->forMaster($this->master)->create([
            'price_override' => 1500,
            'duration_override' => 60,
        ]);
        $this->client = Client::factory()->create([
            'user_id' => $this->master->id,
            'max_id' => 'max-1',
            'vk_id' => '222',
            'vk_messages_allowed' => true,
        ]);
    }

    /**
     * Resolver с управляемой последовательностью ответов: первый вызов —
     * исходный канал, второй (внутри deliver, после claim) — новый.
     *
     * @param  array<int, string|null>  $answers
     */
    private function sequenceResolver(array $answers): AppointmentChannelResolver
    {
        return new class($answers) extends AppointmentChannelResolver
        {
            /** @param array<int, string|null> $answers */
            public function __construct(private array $answers) {}

            public function resolve(Appointment $appointment): ?string
            {
                if ($this->answers !== []) {
                    return array_shift($this->answers);
                }

                return parent::resolve($appointment);
            }
        };
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

    private function eventWithAppointment(Appointment $appointment): AppointmentNotificationEvent
    {
        // Перехватываем outbox-dispatch: job выполняется тестом явно.
        Queue::fake();

        $recorder = app(AppointmentNotificationRecorder::class);
        $version = $recorder->bumpVersion($appointment);

        $event = $recorder->record(
            $appointment->fresh(),
            AppointmentNotificationEventType::CreatedByMaster,
            [],
            $version,
        );
        $this->assertNotNull($event);

        return $event;
    }

    /**
     * Событие в outbox без постановки job'а — для тестов на реальной очереди
     * (worker сам диспатчит job в database-подключении).
     */
    private function seedEvent(Appointment $appointment): AppointmentNotificationEvent
    {
        $version = (int) $appointment->fresh()->notification_version;

        return AppointmentNotificationEvent::create([
            'appointment_id' => $appointment->id,
            'event_type' => AppointmentNotificationEventType::CreatedByMaster,
            'event_version' => $version,
            'payload' => [
                'text' => 'Мастер записал вас',
                'client_id' => $appointment->client_id,
            ],
            'dispatched_at' => now(),
        ]);
    }

    private function runJob(
        SendAppointmentNotificationJob $job,
        ?AppointmentChannelResolver $resolver = null,
        ?VkApiClient $vkApi = null,
    ): void {
        // deliver() резолвит канал через контейнер — подменяем binding,
        // чтобы управлять перечитыванием маршрута между claim и HTTP.
        if ($resolver !== null) {
            $this->app->instance(AppointmentChannelResolver::class, $resolver);
        }

        $job->handle(
            $resolver ?? app(AppointmentChannelResolver::class),
            app(MaxApiClient::class),
            $vkApi ?? $this->mock(VkApiClient::class),
        );
    }

    private function summary(AppointmentNotificationEvent $event): array
    {
        $appointment = Appointment::findOrFail($event->appointment_id);

        return app(AppointmentNotificationSummary::class)
            ->forAppointments(collect([$appointment]))[$appointment->id];
    }

    // ── Дефект 2: владение claim при смене канала ──

    public function test_channel_change_between_claim_and_http_holds_new_channels_claim(): void
    {
        $appointment = $this->appointment();
        $event = $this->eventWithAppointment($appointment);

        // handle() резолвит 'max' → claim max; deliver() перечитывает и
        // получает 'vk' — HTTP должен идти под claim канала vk.
        $resolver = $this->sequenceResolver(['max', 'vk']);

        $claimAtSend = null;
        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldReceive('sendMessage')
            ->once()
            ->with('222', \Mockery::type('string'))
            ->andReturnUsing(function () use ($event, &$claimAtSend) {
                $claimAtSend = AppointmentNotificationDelivery::query()
                    ->where('appointment_notification_event_id', $event->id)
                    ->where('channel', 'vk')
                    ->first();

                return 'mid-vk';
            });
        $this->mock(MaxApiClient::class)->shouldNotReceive('sendMessage');

        $this->runJob(
            new SendAppointmentNotificationJob($event->id),
            $resolver,
            vkApi: $vkMock,
        );

        $this->assertNotNull($claimAtSend, 'HTTP не должен выполняться без строки delivery');
        $this->assertSame(
            AppointmentNotificationDelivery::STATUS_PENDING,
            $claimAtSend->status,
            'HTTP под claim нового канала: строка vk должна быть pending во время отправки',
        );
        $this->assertNotNull($claimAtSend->claim_token, 'HTTP без владения claim vk запрещён');

        $vkRow = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('channel', 'vk')
            ->firstOrFail();
        $this->assertSame(AppointmentNotificationDelivery::STATUS_DELIVERED, $vkRow->status);
        $this->assertSame('mid-vk', $vkRow->provider_message_id);

        // Исходный claim (max) должен быть освобождён своим токеном, не удержан.
        $maxRow = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('channel', 'max')
            ->first();
        if ($maxRow !== null) {
            $this->assertNull($maxRow->claim_token, 'claim исходного канала должен быть освобождён');
            $this->assertNull($maxRow->lease_expires_at);
        }
    }

    public function test_delivered_event_is_not_resent_on_preferred_channel_change(): void
    {
        $appointment = $this->appointment(['source' => AppointmentSource::Admin]);
        $event = $this->seedEvent($appointment);

        $maxMock = $this->mock(MaxApiClient::class);
        $maxMock->shouldReceive('sendMessage')->once()->andReturn('mid-max');

        // Первый проход: доставка в MAX.
        $this->runJob(
            new SendAppointmentNotificationJob($event->id),
            $this->sequenceResolver(['max', 'max']),
        );
        $this->assertSame(
            1,
            AppointmentNotificationDelivery::query()
                ->where('appointment_notification_event_id', $event->id)
                ->where('status', AppointmentNotificationDelivery::STATUS_DELIVERED)
                ->count(),
        );

        // Повтор: предпочтительный канал сменился на vk — событие уже
        // доставлено, повторная отправка в другой канал запрещена.
        $this->client->update(['max_id' => null, 'vk_id' => '333']);

        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldNotReceive('sendMessage');
        $vkMock->shouldNotReceive('sendMessageWithKeyboard');

        $this->runJob(
            new SendAppointmentNotificationJob($event->id),
            $this->sequenceResolver(['vk', 'vk']),
            vkApi: $vkMock,
        );

        $this->assertSame(
            1,
            AppointmentNotificationDelivery::query()
                ->where('appointment_notification_event_id', $event->id)
                ->where('status', AppointmentNotificationDelivery::STATUS_DELIVERED)
                ->count(),
            'событие не должно отправляться повторно в другой канал',
        );
    }

    public function test_stale_recipient_snapshot_is_not_sent_to_new_client(): void
    {
        $appointment = $this->appointment();
        $event = $this->eventWithAppointment($appointment);

        // Получатель записи сменился после фиксации события.
        $newClient = Client::factory()->create([
            'user_id' => $this->master->id,
            'max_id' => 'max-other',
        ]);
        $appointment->update(['client_id' => $newClient->id]);

        $maxMock = $this->mock(MaxApiClient::class);
        $maxMock->shouldNotReceive('sendMessage');
        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldNotReceive('sendMessage');
        $vkMock->shouldNotReceive('sendMessageWithKeyboard');

        $this->runJob(new SendAppointmentNotificationJob($event->id), vkApi: $vkMock);

        $delivered = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('status', AppointmentNotificationDelivery::STATUS_DELIVERED)
            ->exists();
        $this->assertFalse($delivered, 'сообщение старого снимка не должно уйти новому клиенту');
    }

    public function test_late_record_delivery_of_old_lease_owner_does_not_overwrite(): void
    {
        $appointment = $this->appointment();
        $event = $this->eventWithAppointment($appointment);

        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldReceive('sendMessage')->once()->andReturn('mid-current');
        $this->mock(MaxApiClient::class)->shouldNotReceive('sendMessage');

        // Просроченный lease старого исполнителя.
        $oldToken = Str::uuid();
        AppointmentNotificationDelivery::create([
            'appointment_notification_event_id' => $event->id,
            'channel' => 'vk',
            'status' => AppointmentNotificationDelivery::STATUS_PENDING,
            'claim_token' => $oldToken,
            'lease_expires_at' => now()->subSeconds(30),
        ]);

        $this->runJob(
            new SendAppointmentNotificationJob($event->id),
            $this->sequenceResolver(['vk', 'vk']),
            vkApi: $vkMock,
        );

        $row = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('channel', 'vk')
            ->firstOrFail();
        $this->assertSame(AppointmentNotificationDelivery::STATUS_DELIVERED, $row->status);
        $this->assertSame('mid-current', $row->provider_message_id);

        // Поздний результат старого исполнителя не должен ничего затирать.
        $method = new ReflectionMethod(SendAppointmentNotificationJob::class, 'recordDelivery');
        $written = $method->invoke(
            new SendAppointmentNotificationJob($event->id),
            $event,
            'vk',
            (string) $oldToken,
            'mid-late',
        );

        $this->assertFalse($written, 'CAS по чужому токену должен отклонить запись');
        $row->refresh();
        $this->assertSame('mid-current', $row->provider_message_id);
        $this->assertSame(AppointmentNotificationDelivery::STATUS_DELIVERED, $row->status);
    }

    public function test_foreign_live_claim_blocks_send(): void
    {
        $appointment = $this->appointment();
        $event = $this->eventWithAppointment($appointment);

        AppointmentNotificationDelivery::create([
            'appointment_notification_event_id' => $event->id,
            'channel' => 'max',
            'status' => AppointmentNotificationDelivery::STATUS_PENDING,
            'claim_token' => Str::uuid(),
            'lease_expires_at' => now()->addSeconds(60),
        ]);

        $maxMock = $this->mock(MaxApiClient::class);
        $maxMock->shouldNotReceive('sendMessage');

        $this->runJob(
            new SendAppointmentNotificationJob($event->id),
            $this->sequenceResolver(['max', 'max']),
        );

        Http::preventStrayRequests();
        $this->assertSame(0, Http::recorded()->count());
    }

    // ── Дефект 3: терминальные исходы ──

    public function test_superseded_before_first_claim_is_persisted(): void
    {
        $appointment = $this->appointment();
        $event = $this->eventWithAppointment($appointment);

        // Запись ушла вперёд до первого claim'а job'а.
        app(AppointmentNotificationRecorder::class)->bumpVersion($appointment);

        $maxMock = $this->mock(MaxApiClient::class);
        $maxMock->shouldNotReceive('sendMessage');

        $this->runJob(new SendAppointmentNotificationJob($event->id));

        $summary = $this->summary($event);
        $this->assertSame(
            'superseded',
            $summary['status'],
            'superseded до первого claim должен храниться терминально, а не отображаться pending',
        );
    }

    public function test_vk_901_failure_is_persisted_as_failed(): void
    {
        $this->client->update(['max_id' => null]);
        $appointment = $this->appointment();
        $event = $this->eventWithAppointment($appointment);

        $vkMock = $this->mock(VkApiClient::class);
        $vkMock->shouldReceive('sendMessage')
            ->once()
            ->andThrow(new VkMessagesNotAllowedException('901'));

        // 901 терминален для канала, но handle() завершает попытку
        // исключением (bounded retry); строка delivery уже терминальна.
        try {
            $this->runJob(
                new SendAppointmentNotificationJob($event->id),
                $this->sequenceResolver(['vk', 'vk']),
                vkApi: $vkMock,
            );
        } catch (\RuntimeException) {
        }

        $row = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('channel', 'vk')
            ->first();
        $this->assertNotNull($row, '901 должен оставить терминальную строку delivery');
        $this->assertSame(AppointmentNotificationDelivery::STATUS_FAILED, $row->status);
        $this->assertSame('vk_messages_not_allowed', $row->failure_reason);
        $this->assertFalse($this->client->fresh()->vk_messages_allowed);

        $this->assertSame('failed', $this->summary($event)['status']);
    }

    public function test_failed_hook_does_not_steal_foreign_live_claim(): void
    {
        $appointment = $this->appointment();
        $event = $this->eventWithAppointment($appointment);

        $foreignToken = Str::uuid();
        AppointmentNotificationDelivery::create([
            'appointment_notification_event_id' => $event->id,
            'channel' => 'max',
            'status' => AppointmentNotificationDelivery::STATUS_PENDING,
            'claim_token' => $foreignToken,
            'lease_expires_at' => now()->addSeconds(60),
        ]);

        (new SendAppointmentNotificationJob($event->id))
            ->failed(new \RuntimeException('exhausted'));

        $row = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('channel', 'max')
            ->firstOrFail();
        $this->assertSame(AppointmentNotificationDelivery::STATUS_PENDING, $row->status);
        $this->assertSame((string) $foreignToken, (string) $row->claim_token);
    }

    // ── Реальный жизненный цикл очереди ──

    public function test_exhausted_retries_persist_terminal_failed_via_worker(): void
    {
        config(['queue.default' => 'database']);
        Http::fake(['api.max.example/*' => Http::response(['error' => 'boom'], 500)]);

        $appointment = $this->appointment();
        $event = $this->seedEvent($appointment);

        $failedAttempts = 0;
        $failedJobs = 0;
        Event::listen(JobExceptionOccurred::class, function () use (&$failedAttempts) {
            $failedAttempts++;
        });
        Event::listen(JobFailed::class, function () use (&$failedJobs) {
            $failedJobs++;
        });

        SendAppointmentNotificationJob::dispatch($event->id);

        $worker = $this->makeWorker();
        $options = $this->workerOptions();

        foreach ([0, 5, 15] as $backoff) {
            if ($backoff > 0) {
                $this->travel($backoff)->seconds();
            }
            $this->runWorkerJob($worker, $options);
        }

        $this->assertSame(3, $failedAttempts, 'каждая попытка — реальная ошибка API');
        $this->assertSame(1, $failedJobs, 'третья реальная ошибка фейлит job терминально');
        $this->assertSame(0, DB::table('jobs')->count());

        $row = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('channel', 'max')
            ->first();
        $this->assertNotNull($row, 'после исчерпания попыток delivery обязан существовать');
        $this->assertSame(AppointmentNotificationDelivery::STATUS_FAILED, $row->status);

        $summary = $this->summary($event);
        $this->assertSame('failed', $summary['status'], 'Summary не должен навсегда показывать «Ожидает отправки»');
    }

    public function test_busy_release_keeps_job_alive_until_lease_expires_then_delivers(): void
    {
        config(['queue.default' => 'database']);
        Http::fake(['api.max.example/*' => Http::response(['message' => ['body' => ['mid' => 'mid-1']]])]);

        $appointment = $this->appointment();
        $event = $this->seedEvent($appointment);

        // Чужой живой claim.
        AppointmentNotificationDelivery::create([
            'appointment_notification_event_id' => $event->id,
            'channel' => 'max',
            'status' => AppointmentNotificationDelivery::STATUS_PENDING,
            'claim_token' => Str::uuid(),
            'lease_expires_at' => now()->addSeconds(60),
        ]);

        SendAppointmentNotificationJob::dispatch($event->id);

        $deadline = (int) json_decode((string) DB::table('jobs')->value('payload'), true)['retryUntil'];
        $this->assertGreaterThan(now()->getTimestamp(), $deadline);

        $worker = $this->makeWorker();
        $options = $this->workerOptions();

        $failedAttempts = 0;
        Event::listen(JobExceptionOccurred::class, function () use (&$failedAttempts) {
            $failedAttempts++;
        });

        // Попытка 1: занят — release с ожиданием lease, не failure.
        $this->runWorkerJob($worker, $options);
        $this->assertSame(0, $failedAttempts, 'busy-release не должен расходовать бюджет ошибок');
        Http::assertSentCount(0);
        $job = DB::table('jobs')->sole();
        $this->assertSame(1, (int) $job->attempts);
        $this->assertGreaterThanOrEqual(60, (int) $job->available_at - now()->getTimestamp());

        // Lease истёк, преемник не появился — тот же job доставляет.
        $this->travel(70)->seconds();
        AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->update(['lease_expires_at' => now()->subSeconds(10)]);

        $this->runWorkerJob($worker, $options);

        $this->assertSame(0, $failedAttempts, 'доставка не должна фейлить попытку');
        $this->assertSame(0, DB::table('jobs')->count());
        Http::assertSentCount(1);

        $row = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('channel', 'max')
            ->firstOrFail();
        $this->assertSame(AppointmentNotificationDelivery::STATUS_DELIVERED, $row->status);
        $this->assertSame('mid-1', $row->provider_message_id);
    }

    public function test_deadline_exceeded_fails_job_and_persists_terminal_failed(): void
    {
        config(['queue.default' => 'database']);
        Http::fake(['api.max.example/*' => Http::response(['error' => 'boom'], 500)]);

        $appointment = $this->appointment();
        $event = $this->seedEvent($appointment);

        $failedJobs = 0;
        Event::listen(JobFailed::class, function () use (&$failedJobs) {
            $failedJobs++;
        });

        SendAppointmentNotificationJob::dispatch($event->id);
        $deadline = (int) json_decode((string) DB::table('jobs')->value('payload'), true)['retryUntil'];

        // Время вышло: worker обязан фейлить job до исчерпания attempts.
        $this->travelTo(Carbon::createFromTimestamp($deadline)->addSeconds(5));

        $worker = $this->makeWorker();
        $this->runWorkerJob($worker, $this->workerOptions());

        $this->assertSame(1, $failedJobs, 'просроченный deadline должен фейлить job');
        $this->assertSame(0, DB::table('jobs')->count());

        $row = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('channel', 'max')
            ->first();
        $this->assertNotNull($row, 'deadline-фейл должен оставить терминальную строку');
        $this->assertSame(AppointmentNotificationDelivery::STATUS_FAILED, $row->status);
        $this->assertSame('failed', $this->summary($event)['status']);
    }

    public function test_timeout_failure_persists_terminal_failed(): void
    {
        config(['queue.default' => 'database']);
        // TimeoutExceededException иначе делает rollBack(toplevel 0) по
        // queue.failed.database и убивает транзакцию RefreshDatabase.
        config(['queue.failed.database' => null]);
        Http::fake(['api.max.example/*' => Http::response(['message' => ['body' => ['mid' => 'mid-x']]])]);

        $appointment = $this->appointment();
        $event = $this->seedEvent($appointment);

        SendAppointmentNotificationJob::dispatch($event->id);

        $failedJobs = 0;
        Event::listen(JobFailed::class, function () use (&$failedJobs) {
            $failedJobs++;
        });

        // Симуляция исхода, который worker производит по timeout
        // (pcntl-сигнал недоступен в тестовом окружении): тот же путь
        // Job::fail → failed() job'а.
        $job = app(QueueManager::class)->connection('database')
            ->pop('default');
        $this->assertNotNull($job);
        $job->fail(TimeoutExceededException::forJob($job));

        $this->assertSame(1, $failedJobs);
        $this->assertSame(0, DB::table('jobs')->count());

        $row = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('channel', 'max')
            ->first();
        $this->assertNotNull($row, 'timeout-фейл должен оставить терминальную строку');
        $this->assertSame(AppointmentNotificationDelivery::STATUS_FAILED, $row->status);
    }

    // ── Дефект 8: N+1 сводки ──

    public function test_summary_for_appointments_uses_constant_query_count(): void
    {
        $appointments = collect(range(1, 10))->map(function (int $i) {
            $appointment = $this->appointment([
                'start_time' => Carbon::parse('2026-11-12 10:00:00', 'Europe/Moscow')
                    ->addHours($i * 3),
            ]);
            $this->seedEvent($appointment);

            return $appointment->fresh();
        });

        $summary = app(AppointmentNotificationSummary::class);

        // Прогрев (связи схемы и т.п.).
        $summary->forAppointments($appointments->take(1));

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $result = $summary->forAppointments($appointments);

        $this->assertCount(10, $result);
        $this->assertLessThanOrEqual(
            2,
            $queryCount,
            "forAppointments(10) выполнил {$queryCount} запросов — N+1 на deliveries",
        );
    }

    // ── Helpers (зеркало ReceiptNotificationDeliveryTest) ──

    private function makeWorker(): Worker
    {
        $worker = new Worker(
            app(QueueManager::class),
            app(Dispatcher::class),
            app(ExceptionHandler::class),
            fn (): bool => false,
        );

        $worker->setCache(Cache::store());

        return $worker;
    }

    private function workerOptions(): WorkerOptions
    {
        return new WorkerOptions(
            name: 'default',
            backoff: 0,
            memory: 128,
            timeout: 60,
            sleep: 0,
            maxTries: 3,
        );
    }

    private function runWorkerJob(Worker $worker, WorkerOptions $options): void
    {
        $worker->runNextJob('database', 'default', $options);
    }
}
