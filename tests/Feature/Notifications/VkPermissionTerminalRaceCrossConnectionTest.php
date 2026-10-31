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
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use App\Services\MaxApiClient;
use App\Services\Notification\AppointmentChannelResolver;
use App\Services\Notification\AppointmentNotificationRecorder;
use App\Services\VkApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * R01: гонка granted=true с терминализацией в markFailed — регрессия
 * именно порядка «grant ПОСЛЕ чтения версии внутри markFailed, ДО записи
 * failed».
 *
 * Межтранзакционное поведение проверяется на ДВУХ соединениях
 * PostgreSQL:
 *  - job выполняется на основном соединении в РЕАЛЬНОЙ транзакции
 *    (обёртывающий транзакцией RefreshDatabase отключён: его rollback
 *    держал бы lock строки markFailed до конца теста);
 *  - единственный permission-запрос выполняется дочерним PHP-процессом
 *    (tests/Support/vk_permission_child.php) на собственном backend'е —
 *    pid'ы соединений сверяются в тесте. Вложенный HTTP-вызов в той же
 *    транзакции не доказывал бы межтранзакционное поведение.
 *
 * На дефектном коде grant успевал принять решение «pending — не
 * переставлять», markFailed коммитил failed — и подтверждение терялось.
 * С общим протоколом (решение permission под SELECT … FOR UPDATE той же
 * строки — блокировка берётся даже для pending-строки) grant ждёт lock
 * job'а и после его коммита видит failed → сброс → автоматическое
 * продолжение одной ВК-доставкой.
 */
class VkPermissionTerminalRaceCrossConnectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Реальные коммиты без обёртки: обёртывающая транзакция держала бы
     * lock markFailed до конца теста, и дочерний процесс не завершил бы
     * permission-запрос. Данные чистятся в tearDown.
     */
    protected $connectionsToTransact = [];

    private const CHILD_SCRIPT = __DIR__.'/../../Support/vk_permission_child.php';

    private const POLL_TIMEOUT_SECONDS = 20.0;

    private string $testAppId = '6736218';

    private string $testAppSecret = 'test_secret_key';

    private User $master;

    private Workspace $ws;

    private MasterService $masterService;

    private ?Process $grantProcess = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.max.api_url' => 'https://api.max.example',
            'services.max.bot_token' => 'test_max_token',
            'services.vk.app_id' => $this->testAppId,
            'services.vk.app_secret' => $this->testAppSecret,
            'services.vk.group_id' => '123456',
            'services.vk.bot_token' => 'test_bot_token',
        ]);

        Queue::fake();

        $this->master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow'],
            'address' => 'ул. Тестовая, 1',
        ]);
        $this->ws = Workspace::create(['name' => 'WS', 'owner_id' => $this->master->id]);
        $this->master->update(['workspace_id' => $this->ws->id]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $this->ws->id,
            'title' => 'Массаж',
            'base_price' => 2000,
            'base_duration' => 60,
        ]);
        $this->masterService = MasterService::create([
            'master_id' => $this->master->id,
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->grantProcess !== null && $this->grantProcess->isRunning()) {
            $this->grantProcess->stop(1.0);
        }

        // Обёртки RefreshDatabase нет — чистим test DB явно, иначе
        // данные утекут в следующие тест-классы.
        foreach (DB::select("select tablename from pg_tables where schemaname = 'public'") as $table) {
            DB::statement('truncate table "'.$table->tablename.'" cascade');
        }

        parent::tearDown();
    }

    // ── Хелперы ──

    private function vkAuthHeaders(string $vkUserId): array
    {
        $vkParams = [
            'vk_user_id' => $vkUserId,
            'vk_app_id' => $this->testAppId,
            'vk_platform' => 'android',
        ];
        ksort($vkParams);
        $parts = [];
        foreach ($vkParams as $key => $value) {
            $parts[] = $key.'='.$value;
        }
        $canonical = implode('&', $parts);
        $hmac = hash_hmac('sha256', $canonical, $this->testAppSecret, true);
        $sign = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($hmac));
        $all = array_merge($vkParams, ['sign' => $sign]);

        return ['Authorization' => 'Bearer '.http_build_query($all)];
    }

    private function client(array $attrs = []): Client
    {
        return Client::factory()->create(array_merge([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
            'vk_id' => '494075',
            'max_id' => 'max-1',
            'pdn_consent_at' => now(),
            'pdn_consent_version' => 'v1',
        ], $attrs));
    }

    private function vkConfirmedByVkEntry(Client $client): array
    {
        $appointment = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client)
            ->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'source' => AppointmentSource::Vk,
                'start_time' => Carbon::tomorrow()->setTime(14, 0),
                'duration' => 60,
                'price' => 1500,
            ]);

        $recorder = app(AppointmentNotificationRecorder::class);
        $version = $recorder->bumpVersion($appointment);
        $event = $recorder->record(
            $appointment->fresh(),
            AppointmentNotificationEventType::BookingConfirmed,
            [AppointmentChannelResolver::PAYLOAD_VK_ONLY => true],
            $version,
        );
        $this->assertNotNull($event);

        return [$appointment, $event];
    }

    private function runJob(
        SendAppointmentNotificationJob $job,
        ?MaxApiClient $maxApi,
        ?VkApiClient $vkApi,
    ): void {
        $job->handle(
            app(AppointmentChannelResolver::class),
            $maxApi,
            $vkApi,
        );
    }

    private function delivery(
        AppointmentNotificationEvent $event,
        string $channel,
    ): ?AppointmentNotificationDelivery {
        return AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->getKey())
            ->where('channel', $channel)
            ->first();
    }

    /**
     * Единственный permission-запрос — дочерним процессом на втором
     * соединении PostgreSQL.
     */
    private function startGrantChild(Appointment $appointment): Process
    {
        $env = array_merge((array) getenv(), [
            'VK_APP_ID' => $this->testAppId,
            'VK_APP_SECRET' => $this->testAppSecret,
            'VK_GROUP_ID' => '123456',
            'VK_BOT_TOKEN' => 'test_bot_token',
        ]);

        $process = new Process(
            [
                PHP_BINARY,
                self::CHILD_SCRIPT,
                (string) json_encode([
                    'appointment_id' => (string) $appointment->id,
                    'authorization' => $this->vkAuthHeaders('494075')['Authorization'],
                ]),
            ],
            base_path(),
            $env,
        );
        $process->setTimeout(120.0);
        $process->start();

        return $process;
    }

    /**
     * Есть ли ЧУЖОЙ процесс, ожидающий блокировку в нашей test DB — на
     * фиксе дочерний SELECT … FOR UPDATE ждёт lock markFailed (wait_event
     * типа Lock); на дефектном коде обычный SELECT никогда не ждёт,
     * поэтому ребёнок просто завершается. pg_locks для transactionid
     * ненадёжен по database (поле может быть пустым) — берём
     * pg_stat_activity.
     */
    private function deliveryLockWaiterExists(): bool
    {
        $row = DB::selectOne(
            'select count(*) as c from pg_stat_activity'.
            ' where pid <> pg_backend_pid()'.
            ' and datname = current_database()'.
            " and wait_event_type = 'Lock'"
        );

        return (int) ($row->c ?? 0) > 0;
    }

    private function waitGrantChildExit(Process $process): void
    {
        $deadline = microtime(true) + self::POLL_TIMEOUT_SECONDS;

        while ($process->isRunning() && microtime(true) < $deadline) {
            usleep(20000);
        }
    }

    // ── Регрессия ──

    public function test_grant_after_version_read_inside_markfailed_is_not_lost(): void
    {
        $client = $this->client(['vk_messages_allowed' => null]);
        [$appointment, $event] = $this->vkConfirmedByVkEntry($client);

        $original = Queue::pushed(SendAppointmentNotificationJob::class)->first();
        $this->assertNotNull($original);

        $parentBackendPid = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;

        $seamFired = false;
        $childSpawned = 0;
        $versionBeforeGrant = null;
        $versionSeenInsideSeam = null;

        // Шов стоит ПОСЛЕ чтения версии в markFailed (guard уже прошёл)
        // и ДО записи failed ($row->update) — ровно окно замечания.
        // Событие firing подтверждает позицию: markFailed — единственный
        // писатель status=failed в этом проходе.
        AppointmentNotificationDelivery::updating(function ($row) use (
            &$seamFired,
            &$childSpawned,
            &$versionBeforeGrant,
            &$versionSeenInsideSeam,
            $appointment,
            $client,
        ) {
            if ($seamFired
                || ! $row->isDirty('status')
                || $row->status !== AppointmentNotificationDelivery::STATUS_FAILED) {
                return;
            }

            $seamFired = true;

            // Версия на момент чтения guard'ом markFailed (до grant'а).
            $versionBeforeGrant = (int) $client->fresh()->vk_permission_version;

            $this->grantProcess = $this->startGrantChild($appointment);
            $childSpawned++;

            // 1) Второе соединение должно зафиксировать granted=true и
            //    новую версию — «grant после чтения версии». Чтение с
            //    основного соединения видит закоммиченные изменения
            //    второго backend (READ COMMITTED, отдельный процесс).
            $deadline = microtime(true) + self::POLL_TIMEOUT_SECONDS;
            while ((int) $client->fresh()->vk_permission_version === $versionBeforeGrant
                && $this->grantProcess->isRunning()
                && microtime(true) < $deadline) {
                usleep(20000);
            }

            $versionSeenInsideSeam = (int) $client->fresh()->vk_permission_version;

            // 2) Либо дочерний запрос уже завершился (дефект: его обычный
            //    SELECT не ждёт lock и решения о сбросе не принимает),
            //    либо его SELECT … FOR UPDATE ждёт наш lock (протокол) —
            //    только после одного из этих состояний пишем failed.
            $deadline = microtime(true) + self::POLL_TIMEOUT_SECONDS;
            while ($this->grantProcess->isRunning()
                && ! $this->deliveryLockWaiterExists()
                && microtime(true) < $deadline) {
                usleep(20000);
            }
        });

        $vkApi = $this->mock(VkApiClient::class);
        $vkApi->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->andThrow(new VkMessagesNotAllowedException);
        $maxApi = $this->mock(MaxApiClient::class);
        $maxApi->shouldNotReceive('sendMessage');

        $threw = false;
        try {
            $this->runJob($original, $maxApi, $vkApi);
        } catch (\RuntimeException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'handle() должен завершить попытку исключением — bounded retry очереди');
        $this->assertTrue($seamFired, 'Шов markFailed (после чтения версии, до записи failed) должен сработать');
        $this->assertSame(1, $childSpawned, 'Ровно один permission-запрос');

        // Дожидаемся дочернего процесса: на фиксе он после нашего коммита
        // сбрасывает failed→pending и завершает запрос с 200.
        $this->waitGrantChildExit($this->grantProcess);

        $stdout = $this->grantProcess->getOutput();
        $stderr = $this->grantProcess->getErrorOutput();

        $this->assertFalse(
            $this->grantProcess->isRunning(),
            "Дочерний permission-запрос завис. stdout=[{$stdout}] stderr=[{$stderr}]",
        );
        $this->assertSame(
            0,
            $this->grantProcess->getExitCode(),
            "Дочерний permission-запрос должен вернуть 200. stdout=[{$stdout}] stderr=[{$stderr}]",
        );

        // Два соединения PostgreSQL: backend-иды обязаны различаться.
        $this->assertSame(
            1,
            preg_match('/^PID:(\d+)$/m', $stdout, $matches),
            "В stdout дочернего процесса ожидался PID backend'а. stdout=[{$stdout}]",
        );
        $childBackendPid = (int) $matches[1];
        $this->assertNotSame(
            $parentBackendPid,
            $childBackendPid,
            'permission-запрос должен идти со ВТОРОГО соединения PostgreSQL',
        );
        $this->assertStringContainsString('STATUS:200', $stdout);

        // Grant действительно попал в окно после чтения версии.
        $this->assertSame(0, $versionBeforeGrant, 'Версия на момент чтения guard-а — снимок до grant');
        $this->assertSame(
            1,
            $versionSeenInsideSeam,
            'Версия должна вырасти ВНУТРИ шва markFailed — до записи failed',
        );
        $this->assertTrue($client->fresh()->vk_messages_allowed);
        $this->assertSame(1, (int) $client->fresh()->vk_permission_version);

        // Ключевой исход: grant после чтения версии НЕ потерян —
        // атомарный сброс permission дождался lock и вернул строку в
        // pending вместо терминального failed.
        $vkRow = $this->delivery($event, 'vk');
        $this->assertSame(
            AppointmentNotificationDelivery::STATUS_PENDING,
            $vkRow?->status,
            'Строка должна быть сброшена в pending атомарным переходом permission; '
                .'терминальный failed после чтения версии теряет подтверждение '
                .'(claim() вернёт завершённый исход без отправки)',
        );
        $this->assertNull($vkRow?->failure_reason);

        // Продолжение — автоматически bounded retry того же job: одна ВК,
        // без MAX, без второго dispatch'а и без второго permission.
        $vkApi2 = $this->mock(VkApiClient::class);
        $vkApi2->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->with('494075', \Mockery::type('string'), \Mockery::type('array'))
            ->andReturn('msg-grant-vs-terminal');
        $maxApi2 = $this->mock(MaxApiClient::class);
        $maxApi2->shouldNotReceive('sendMessage');

        $this->runJob($original, $maxApi2, $vkApi2);

        $this->assertSame(
            AppointmentNotificationDelivery::STATUS_DELIVERED,
            $this->delivery($event, 'vk')?->status,
        );
        $this->assertNotSame(
            AppointmentNotificationDelivery::STATUS_DELIVERED,
            $this->delivery($event, 'max')?->status,
        );
        $this->assertSame(
            1,
            AppointmentNotificationDelivery::query()
                ->where('appointment_notification_event_id', $event->id)
                ->where('status', AppointmentNotificationDelivery::STATUS_DELIVERED)
                ->count(),
            'Ровно одна доставка — без дублей',
        );
        $this->assertSame(
            1,
            Queue::pushed(SendAppointmentNotificationJob::class)->count(),
            'Ни второго permission-запроса, ни второго dispatch',
        );
    }
}
