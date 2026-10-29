<?php

namespace Tests\Feature\Notifications;

use App\Enums\AppointmentNotificationEventType;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentNotificationDelivery;
use App\Models\AppointmentNotificationEvent;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Управляемое пересечение двух исполнителей claim'а на НЕЗАВИСИМЫХ
 * PostgreSQL-соединениях (каждый worker — отдельный PHP-процесс).
 *
 * RefreshDatabase оборачивает тест в транзакцию, невидимую для других
 * соединений, поэтому схема мигрируется заново (migrate:fresh), а фикстуры
 * коммитятся. Пересечение управляется барьером в concurrency_barriers.
 */
class NotificationClaimConcurrencyTest extends TestCase
{
    private const BARRIER_TABLE = 'concurrency_barriers';

    private string $childScript;

    /** @var list<resource> */
    private array $processes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true])->run();

        Schema::create(self::BARRIER_TABLE, function ($table) {
            $table->string('key')->primary();
            $table->string('value')->nullable();
        });

        $this->childScript = storage_path('framework/testing/notification-claim-child.php');
        file_put_contents($this->childScript, $this->childScriptSource());
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        $this->processes = [];

        @unlink($this->childScript);

        foreach (glob(storage_path('framework/testing/claim_*')) ?: [] as $file) {
            @unlink($file);
        }

        $this->artisan('migrate:fresh', ['--force' => true])->run();

        parent::tearDown();
    }

    public function test_two_executors_on_independent_connections_deliver_exactly_once(): void
    {
        $sendLog = storage_path('framework/testing/claim_sends.log');
        @unlink($sendLog);

        $event = $this->seedDeliverableEvent();

        $results = $this->runExecutors([
            ['id' => 'a', 'event_id' => $event->id, 'send_log' => $sendLog],
            ['id' => 'b', 'event_id' => $event->id, 'send_log' => $sendLog],
        ]);

        $this->assertArrayHasKey('a', $results);
        $this->assertArrayHasKey('b', $results);

        $sends = is_file($sendLog)
            ? array_values(array_filter(explode("\n", (string) file_get_contents($sendLog))))
            : [];
        $this->assertCount(
            1,
            $sends,
            'два пересекающихся исполнителя должны дать ровно одну отправку: '.json_encode($results),
        );

        $delivered = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('status', AppointmentNotificationDelivery::STATUS_DELIVERED)
            ->get();
        $this->assertCount(1, $delivered, 'ровно одна доставленная строка');
        $this->assertSame('mid-concurrent', $delivered->first()->provider_message_id);

        // Ни один исполнитель не оставил чужой/просроченный claim.
        $leftoverClaims = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->whereNotNull('claim_token')
            ->count();
        $this->assertSame(
            0,
            $leftoverClaims,
            'claim-оба исполнителя должны освободить',
        );
    }

    private function seedDeliverableEvent(bool $withVk = false): AppointmentNotificationEvent
    {
        $master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow'],
        ]);
        $workspace = Workspace::create([
            'name' => 'ws-claim-'.$master->id,
            'owner_id' => $master->id,
        ]);
        $workspace->ensureSlug();
        $master->update(['workspace_id' => $workspace->id]);
        $service = MasterService::factory()->forMaster($master)->create();
        $client = Client::factory()->create([
            'user_id' => $master->id,
            'max_id' => 'max-claim',
            'vk_id' => $withVk ? '777' : null,
            'vk_messages_allowed' => $withVk ? true : null,
        ]);

        $appointment = Appointment::factory()
            ->forMaster($master)
            ->forClient($client)
            ->withMasterService($service)
            ->create([
                'status' => AppointmentStatus::Booked,
                'source' => AppointmentSource::Admin,
                'start_time' => Carbon::now()->addDays(2),
            ]);

        return AppointmentNotificationEvent::create([
            'appointment_id' => $appointment->id,
            'event_type' => AppointmentNotificationEventType::CreatedByMaster,
            'event_version' => (int) $appointment->notification_version,
            'payload' => [
                'text' => 'Тест доставки',
                'client_id' => $client->id,
            ],
            'dispatched_at' => now(),
        ]);
    }

    /**
     * Управляемое пересечение claim'ов БЕЗ заранее существующих
     * delivery-строк: исполнители выбирают РАЗНЫЕ каналы (max и vk) и
     * стартуют одновременно. Пока оба не заблокированы в БД, постановка
     * не снимается: барьер удерживается SHARE ROW EXCLUSIVE-блокировкой
     * таблицы delivery (чтения проходят, записи ждут), поэтому на
     * сломанном коде оба проходят foreignLive-проверку по пустому набору
     * и вставляют claims разных каналов. На исправленном коде первый
     * держит lock строки события — второй ждёт и затем видит чужой
     * живой claim. HTTP должен выполнить ровно один.
     */
    public function test_two_channels_without_delivery_rows_perform_exactly_one_http(): void
    {
        $sendLog = storage_path('framework/testing/claim_sends.log');
        @unlink($sendLog);

        $event = $this->seedDeliverableEvent(withVk: true);

        // Барьерная блокировка — на отдельном соединении: основное
        // соединение теста свободно для постановки 'go', которую дети
        // обязаны видеть (иначе они ждут её до бесконечности).
        config(['database.connections.pgsql_barrier' => config('database.connections.pgsql')]);
        $barrier = DB::connection('pgsql_barrier');
        $barrier->beginTransaction();
        $barrier->getPdo()->exec('LOCK TABLE appointment_notification_deliveries IN SHARE ROW EXCLUSIVE MODE');

        try {
            $configs = $this->launchExecutors([
                ['id' => 'a', 'event_id' => $event->id, 'send_log' => $sendLog],
                ['id' => 'b', 'event_id' => $event->id, 'send_log' => $sendLog, 'force_channel' => 'vk'],
            ]);

            // Оба исполнителя должны ждать внутри claim-транзакции:
            // на сломанном коде оба ждут INSERT в deliveries, на
            // исправленном — один INSERT, второй lock строки события.
            try {
                $this->waitFor(function (): bool {
                    return (int) DB::selectOne(
                        "SELECT count(*) AS c FROM pg_stat_activity
                         WHERE wait_event_type = 'Lock'
                           AND query LIKE '%appointment_notification%'",
                    )->c >= 2;
                }, 10, 'оба исполнителя должны ждать блокировок claim-транзакции');
            } catch (\Throwable $waitError) {
                $locks = DB::select(
                    "SELECT l.pid, l.granted, c.relname, l.mode, l.page, l.tuple FROM pg_locks l
                     LEFT JOIN pg_class c ON c.oid = l.relation
                     WHERE c.relname IN ('appointment_notification_deliveries', 'appointment_notification_events')",
                );
                $pids = array_map(fn ($row) => (int) $row->pid, $locks);
                $pids[] = (int) DB::selectOne('SELECT pg_backend_pid() AS p')->p;
                $activity = DB::select(
                    'SELECT pid, state, wait_event_type, wait_event, left(query, 160) AS query
                     FROM pg_stat_activity WHERE pid IN ('.implode(',', array_unique($pids)).')',
                );
                $diag = [];
                foreach ($configs as $id => $config) {
                    $diag[$id] = is_file($config['result'])
                        ? file_get_contents($config['result'])
                        : 'no result, running='.(int) proc_get_status($config['process'])['running'];
                }
                $this->fail($waitError->getMessage().' | locks='.json_encode($locks).' | activity='.json_encode($activity).' | children='.json_encode($diag));
            }

            $barrier->commit();
        } catch (\Throwable $e) {
            $barrier->rollBack();

            throw $e;
        } finally {
            DB::purge('pgsql_barrier');
        }

        $results = $this->collectExecutors($configs);

        $this->assertArrayHasKey('a', $results);
        $this->assertArrayHasKey('b', $results);

        $sends = is_file($sendLog)
            ? array_values(array_filter(explode("\n", (string) file_get_contents($sendLog))))
            : [];
        $this->assertCount(
            1,
            $sends,
            'при отсутствии delivery-строк ровно один исполнитель выполняет HTTP: '.json_encode($results),
        );

        $delivered = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->where('status', AppointmentNotificationDelivery::STATUS_DELIVERED)
            ->get();
        $this->assertCount(1, $delivered, 'ровно одна доставленная строка');

        $leftoverClaims = AppointmentNotificationDelivery::query()
            ->where('appointment_notification_event_id', $event->id)
            ->whereNotNull('claim_token')
            ->count();
        $this->assertSame(0, $leftoverClaims, 'claim-оба исполнителя должны освободить');
    }

    /**
     * @param  array<int, array<string, string>>  $executors
     * @return array<string, array<string, mixed>>
     */
    private function runExecutors(array $executors): array
    {
        return $this->collectExecutors($this->launchExecutors($executors));
    }

    /**
     * @param  array<int, array<string, string>>  $executors
     * @return array<string, array{process: resource, pipes: array<int, resource>, result: string}>
     */
    private function launchExecutors(array $executors): array
    {
        $configs = [];

        foreach ($executors as $executor) {
            $id = $executor['id'];
            $configPath = storage_path("framework/testing/claim_{$id}.json");
            $resultPath = storage_path("framework/testing/claim_{$id}.result.json");
            @unlink($resultPath);

            file_put_contents($configPath, json_encode(array_merge($executor, [
                'base_path' => base_path(),
                'result' => $resultPath,
            ])));

            $command = [PHP_BINARY, $this->childScript, $configPath];
            $process = proc_open(
                $command,
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                base_path(),
                $this->childEnv(),
            );

            if (! is_resource($process)) {
                $this->fail("Не удалось запустить executor {$id}");
            }

            $this->processes[] = $process;
            $configs[$id] = [
                'process' => $process,
                'pipes' => $pipes,
                'result' => $resultPath,
            ];
        }

        // Оба процесса подняли соединения и ждут — открываем барьер.
        $this->waitFor(function () use ($executors) {
            $keys = array_map(fn (array $e) => 'ready:'.$e['id'], $executors);

            return DB::table(self::BARRIER_TABLE)->whereIn('key', $keys)->count() === count($executors);
        }, 30, 'executors not ready');

        DB::table(self::BARRIER_TABLE)->insert(['key' => 'go', 'value' => now()->toIso8601String()]);

        return $configs;
    }

    /**
     * @param  array<string, array{process: resource, pipes: array<int, resource>, result: string}>  $configs
     * @return array<string, array<string, mixed>>
     */
    private function collectExecutors(array $configs): array
    {
        $results = [];

        foreach ($configs as $id => $config) {
            $exitCode = $this->waitForProcessExit($config['process'], 45, $id);
            $stdout = stream_get_contents($config['pipes'][1]);
            $stderr = stream_get_contents($config['pipes'][2]);
            fclose($config['pipes'][1]);
            fclose($config['pipes'][2]);

            $raw = is_file($config['result']) ? file_get_contents($config['result']) : null;
            $decoded = $raw !== null ? json_decode($raw, true) : null;

            if (! is_array($decoded)) {
                $this->fail("Executor {$id}: нет результата (exit={$exitCode}, stdout={$stdout}, stderr={$stderr})");
            }

            $decoded['exit_code'] = $exitCode;
            $results[$id] = $decoded;
        }

        return $results;
    }

    /**
     * @param  callable(): bool  $condition
     */
    private function waitFor(callable $condition, int $timeoutSeconds, string $message): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if ($condition()) {
                return;
            }

            usleep(5000);
        }

        $this->fail($message);
    }

    /**
     * @param  resource  $process
     */
    private function waitForProcessExit($process, int $timeoutSeconds, string $id): int
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);

            if (! $status['running']) {
                return (int) $status['exitcode'];
            }

            usleep(5000);
        }

        $this->fail("Executor {$id}: таймаут ожидания завершения");
    }

    /**
     * @return array<string, string>
     */
    private function childEnv(): array
    {
        $env = getenv();
        $env = is_array($env) ? $env : [];

        $env['APP_ENV'] = 'testing';
        $env['APP_KEY'] = (string) config('app.key');
        $env['APP_DEBUG'] = 'false';
        $env['CACHE_STORE'] = 'array';
        $env['QUEUE_CONNECTION'] = 'sync';
        $env['SESSION_DRIVER'] = 'array';
        $env['MAIL_MAILER'] = 'array';
        $env['BCRYPT_ROUNDS'] = '4';
        $env['BROADCAST_CONNECTION'] = 'null';
        $env['DB_URL'] = '';

        $connection = (string) config('database.default');
        $env['DB_CONNECTION'] = $connection;
        $config = config("database.connections.{$connection}") ?: [];

        foreach ($config as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $env['DB_'.strtoupper($key)] = (string) $value;
            }
        }

        return $env;
    }

    private function childScriptSource(): string
    {
        return <<<'PHP'
<?php

// Дочерний исполнитель claim-гонки: отдельный PHP-процесс с собственным
// соединением к PostgreSQL. Управляется NotificationClaimConcurrencyTest.

$config = json_decode(file_get_contents($argv[1] ?? ''), true);
if (! is_array($config)) {
    fwrite(STDERR, "bad config\n");
    exit(2);
}

require $config['base_path'].'/vendor/autoload.php';
$app = require $config['base_path'].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

class ClaimChildMaxApiStub extends App\Services\MaxApiClient
{
    public function __construct(private string $sendLog) {}

    public function sendMessage(string $chatId, string $text, array $extra = []): ?string
    {
        // Аппенд с блокировкой файла: каждая реальная отправка — строка.
        file_put_contents($this->sendLog, 'max:'.$chatId."\n", FILE_APPEND | LOCK_EX);

        return 'mid-concurrent';
    }
}

class ClaimChildVkApiStub extends App\Services\VkApiClient
{
    public function __construct(private string $sendLog) {}

    public function sendMessage(string $peerId, string $text, array $extra = []): ?string
    {
        file_put_contents($this->sendLog, 'vk:'.$peerId."\n", FILE_APPEND | LOCK_EX);

        return 'mid-concurrent';
    }

    public function sendMessageWithKeyboard(string $peerId, string $text, array $keyboard): ?string
    {
        file_put_contents($this->sendLog, 'vk:'.$peerId."\n", FILE_APPEND | LOCK_EX);

        return 'mid-concurrent';
    }
}

try {
    Illuminate\Support\Facades\DB::statement("SET lock_timeout = '20s'");

    Illuminate\Support\Facades\DB::table('concurrency_barriers')->insert([
        'key' => 'ready:'.$config['id'],
        'value' => gmdate('c'),
    ]);

    $deadline = microtime(true) + 30;
    $go = false;
    while (microtime(true) < $deadline) {
        $go = (bool) Illuminate\Support\Facades\DB::table('concurrency_barriers')
            ->where('key', 'go')
            ->exists();
        if ($go) {
            break;
        }
        usleep(2000);
    }

    if (! $go) {
        file_put_contents($config['result'], json_encode(['ok' => false, 'error' => 'barrier timeout']));
        exit(3);
    }

    $app->instance(App\Services\MaxApiClient::class, new ClaimChildMaxApiStub($config['send_log']));
    $app->instance(App\Services\VkApiClient::class, new ClaimChildVkApiStub($config['send_log']));

    // Управляемый канал: один исполнитель резолвит max (обычный resolver),
    // второй — принудительно другой канал для гонки claim'ов.
    if (! empty($config['force_channel'])) {
        $app->instance(
            App\Services\Notification\AppointmentChannelResolver::class,
            new class($config['force_channel']) extends App\Services\Notification\AppointmentChannelResolver
            {
                public function __construct(private string $force) {}

                public function resolve(App\Models\Appointment $appointment): ?string
                {
                    return $this->force;
                }
            },
        );
    }

    $event = App\Models\AppointmentNotificationEvent::find($config['event_id']);
    if ($event === null) {
        file_put_contents($config['result'], json_encode(['ok' => false, 'error' => 'event not found']));
        exit(4);
    }

    $job = new App\Jobs\SendAppointmentNotificationJob($event->id);
    $outcome = 'delivered';
    try {
        $job->handle(
            app(App\Services\Notification\AppointmentChannelResolver::class),
            app(App\Services\MaxApiClient::class),
            app(App\Services\VkApiClient::class),
        );
    } catch (Throwable $e) {
        $outcome = 'exception: '.$e->getMessage();
    }

    $deliveries = App\Models\AppointmentNotificationDelivery::query()
        ->where('appointment_notification_event_id', $event->id)
        ->get()
        ->map(fn ($row) => [
            'channel' => $row->channel,
            'status' => $row->status,
            'claimed' => $row->claim_token !== null,
        ])
        ->values()
        ->all();

    file_put_contents($config['result'], json_encode([
        'ok' => true,
        'id' => $config['id'],
        'outcome' => $outcome,
        'deliveries' => $deliveries,
    ], JSON_UNESCAPED_UNICODE));
    exit(0);
} catch (Throwable $e) {
    file_put_contents($config['result'], json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]));
    exit(1);
}
PHP;
    }
}
