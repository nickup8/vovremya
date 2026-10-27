<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Реальная конкурентность на независимых PostgreSQL-соединениях.
 *
 * RefreshDatabase оборачивает тест в независимую транзакцию, которую второй
 * соединение не видит, поэтому класс мигрирует схему заново и коммитит
 * фикстуры, а рабочие процессы запускаются через proc_open: каждый worker —
 * отдельный PHP-процесс с собственным соединением к PostgreSQL.
 * Пересечение операций управляется барьером в таблице concurrency_barriers.
 *
 * (DatabaseMigrations не используется: migrate:rollback в этом репо
 * ломается на legacy down() — см. ReceiptBackfillConcurrencyTest.)
 */
class ClientConcurrencyTest extends TestCase
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

        $this->childScript = storage_path('framework/testing/client-phone-concurrency-child.php');
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

        foreach (glob(storage_path('framework/testing/concurrency_*')) ?: [] as $file) {
            @unlink($file);
        }

        $this->artisan('migrate:fresh', ['--force' => true])->run();

        parent::tearDown();
    }

    public function test_concurrent_manual_create_vs_confirmation_shares_one_client(): void
    {
        [$master, $ws] = $this->makeMasterWithWorkspace();

        $results = $this->runWorkers([
            ['id' => 'manual', 'op' => 'create_manual', 'master_id' => $master->id, 'workspace_id' => $ws->id, 'phone' => '79001234567', 'name' => 'Анна Ручная'],
            ['id' => 'confirm', 'op' => 'find_or_create', 'master_id' => $master->id, 'workspace_id' => $ws->id, 'phone' => '79001234567', 'name' => 'Подтверждение'],
        ]);

        $this->assertWorkerSucceeded('manual', $results['manual']);
        $this->assertWorkerSucceeded('confirm', $results['confirm']);

        $manual = $results['manual']['outcome']['result'];
        $confirm = $results['confirm']['outcome']['result'];

        $this->assertContains($manual, ['created', 'duplicate'], 'manual: '.json_encode($results['manual']));
        $this->assertContains($confirm, ['created', 'reused'], 'confirm: '.json_encode($results['confirm']));

        // Ровно одна карточка; подтверждение не может создать второй.
        $this->assertSame(1, Client::where('user_id', $master->id)->count());

        if ($manual === 'created') {
            $this->assertSame('reused', $confirm, 'подтверждение должно переиспользовать карточку: '.json_encode($results));
            $this->assertSame(
                $results['manual']['outcome']['id'],
                $results['confirm']['outcome']['id'],
                'обе операции должны работать с одним client_id',
            );
        } else {
            $this->assertSame('created', $confirm, json_encode($results));
        }
    }

    public function test_concurrent_creates_in_same_workspace_do_not_duplicate(): void
    {
        [$owner, $ws] = $this->makeMasterWithWorkspace();
        $member = User::factory()->master()->create([
            'workspace_id' => $ws->id,
            'role' => 'master',
            'phone' => '79000000021',
        ]);

        $results = $this->runWorkers([
            ['id' => 'a', 'op' => 'create_manual', 'master_id' => $owner->id, 'workspace_id' => $ws->id, 'phone' => '79001234567', 'name' => 'Первый мастер'],
            ['id' => 'b', 'op' => 'create_manual', 'master_id' => $member->id, 'workspace_id' => $ws->id, 'phone' => '79001234567', 'name' => 'Второй мастер'],
        ]);

        $this->assertWorkerSucceeded('a', $results['a']);
        $this->assertWorkerSucceeded('b', $results['b']);

        $outcomes = [$results['a']['outcome']['result'], $results['b']['outcome']['result']];
        sort($outcomes);

        $this->assertSame(['created', 'duplicate'], $outcomes, json_encode($results));
        $this->assertSame(1, Client::where('phone', '79001234567')->count());
    }

    public function test_concurrent_phone_change_and_create_do_not_deadlock(): void
    {
        [$master, $ws] = $this->makeMasterWithWorkspace();
        $card = Client::create([
            'user_id' => $master->id,
            'workspace_id' => $ws->id,
            'phone' => '79001110001',
            'name' => 'Меняет телефон',
        ]);

        $results = $this->runWorkers([
            ['id' => 'update', 'op' => 'update_phone', 'client_id' => $card->id, 'phone' => '79002220002', 'name' => 'Меняет телефон', 'notes' => null],
            ['id' => 'create', 'op' => 'create_manual', 'master_id' => $master->id, 'workspace_id' => $ws->id, 'phone' => '79001110001', 'name' => 'Новый на старый номер'],
        ]);

        $this->assertWorkerSucceeded('update', $results['update']);
        $this->assertWorkerSucceeded('create', $results['create']);

        $updateResult = $results['update']['outcome']['result'];
        $createResult = $results['create']['outcome']['result'];

        $this->assertContains($updateResult, ['updated'], json_encode($results));
        $this->assertContains($createResult, ['created', 'duplicate'], json_encode($results));

        // Смена телефона всегда применяется; карточка-дубль не появляется
        // с тем же нормализованным номером, что и новая карточка.
        $this->assertSame('79002220002', $card->fresh()->phone);

        if ($createResult === 'created') {
            $this->assertSame(2, Client::where('user_id', $master->id)->count());
            $this->assertSame(1, Client::where('phone', '79001110001')->count());
        } else {
            $this->assertSame(1, Client::where('user_id', $master->id)->count());
        }
    }

    public function test_concurrent_provider_links_produce_single_winner(): void
    {
        [$master, $ws] = $this->makeMasterWithWorkspace();
        $cardA = Client::create([
            'user_id' => $master->id,
            'workspace_id' => $ws->id,
            'phone' => '79001110001',
            'name' => 'Первая',
        ]);
        $cardB = Client::create([
            'user_id' => $master->id,
            'workspace_id' => $ws->id,
            'phone' => '79001110002',
            'name' => 'Вторая',
        ]);

        $results = $this->runWorkers([
            ['id' => 'link_a', 'op' => 'link', 'client_id' => $cardA->id, 'provider' => 'max', 'provider_id' => 'max_race_uid'],
            ['id' => 'link_b', 'op' => 'link', 'client_id' => $cardB->id, 'provider' => 'max', 'provider_id' => 'max_race_uid'],
        ]);

        $this->assertWorkerSucceeded('link_a', $results['link_a']);
        $this->assertWorkerSucceeded('link_b', $results['link_b']);

        $outcomes = [$results['link_a']['outcome']['result'], $results['link_b']['outcome']['result']];
        sort($outcomes);

        $this->assertSame(['conflict', 'linked'], $outcomes, json_encode($results));

        $linked = Client::where('max_id', 'max_race_uid')->count();
        $this->assertSame(1, $linked, 'provider_id должен остаться ровно на одной карточке области');
        $this->assertSame(2, Client::where('user_id', $master->id)->count());
    }

    // ─── Инфраструктура независимых процессов ───

    /**
     * @param  list<array<string, mixed>>  $workers
     * @return array<string, array<string, mixed>>
     */
    private function runWorkers(array $workers): array
    {
        $configs = [];

        foreach ($workers as $worker) {
            $id = $worker['id'];
            $configPath = storage_path("framework/testing/concurrency_{$id}.json");
            $resultPath = storage_path("framework/testing/concurrency_{$id}.result.json");
            @unlink($resultPath);

            file_put_contents($configPath, json_encode(array_merge($worker, [
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
                $this->fail("Не удалось запустить worker {$id}");
            }

            $this->processes[] = $process;
            $configs[$id] = [
                'process' => $process,
                'pipes' => $pipes,
                'result' => $resultPath,
            ];
        }

        // Все процессы подняли соединения и ждут — открываем барьер.
        $this->waitFor(function () use ($workers) {
            $keys = array_map(fn (array $w) => 'ready:'.$w['id'], $workers);

            return DB::table(self::BARRIER_TABLE)->whereIn('key', $keys)->count() === count($workers);
        }, 30, 'workers not ready');

        DB::table(self::BARRIER_TABLE)->insert(['key' => 'go', 'value' => now()->toIso8601String()]);

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
                $this->fail("Worker {$id}: нет результата (exit={$exitCode}, stdout={$stdout}, stderr={$stderr})");
            }

            $decoded['exit_code'] = $exitCode;
            $results[$id] = $decoded;
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function assertWorkerSucceeded(string $id, array $result): void
    {
        $this->assertTrue(
            (bool) ($result['ok'] ?? false),
            "Worker {$id} упал: ".json_encode($result, JSON_UNESCAPED_UNICODE),
        );
        $this->assertSame(0, $result['exit_code'], "Worker {$id}: ".json_encode($result));
    }

    private function waitForProcessExit($process, int $timeoutSeconds, string $id): int
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $lastExit = -1;

        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);

            if (! $status['running']) {
                $lastExit = $status['exitcode'];

                return $lastExit;
            }

            usleep(10_000);
        }

        proc_terminate($process);
        $this->fail("Worker {$id} не завершился за {$timeoutSeconds}s (возможна взаимная блокировка)");
    }

    private function waitFor(callable $probe, int $timeoutSeconds, string $message): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if ($probe()) {
                return;
            }

            usleep(5_000);
        }

        $this->fail($message);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function makeMasterWithWorkspace(): array
    {
        $master = User::factory()->master()->create([
            'phone' => '79000000001',
            'is_service_provider' => true,
            'settings' => ['timezone' => 'Europe/Moscow'],
        ]);
        $ws = Workspace::create(['name' => 'WS Concurrency', 'owner_id' => $master->id]);
        $master->update(['workspace_id' => $ws->id]);

        return [$master, $ws];
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

// Автогенерируемый worker конкурентности: отдельный PHP-процесс с
// собственным соединением к PostgreSQL. Управляется ClientConcurrencyTest.

$config = json_decode(file_get_contents($argv[1] ?? ''), true);
if (! is_array($config)) {
    fwrite(STDERR, "bad config\n");
    exit(2);
}

require $config['base_path'].'/vendor/autoload.php';
$app = require $config['base_path'].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

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

    $merge = $app->make(App\Services\Client\ClientMergeService::class);

    switch ($config['op']) {
        case 'create_manual':
            try {
                $client = $merge->createManualClient(
                    $config['master_id'],
                    $config['phone'],
                    $config['name'] ?? 'Воркер',
                    $config['notes'] ?? null,
                    $config['workspace_id'] ?? null,
                );
                $outcome = ['result' => 'created', 'id' => (string) $client->id];
            } catch (App\Exceptions\DuplicateClientPhoneException) {
                $outcome = ['result' => 'duplicate'];
            } catch (App\Exceptions\AmbiguousClientMatchException) {
                $outcome = ['result' => 'ambiguous'];
            }
            break;

        case 'find_or_create':
            try {
                $client = $merge->findOrCreateByPhone(
                    $config['master_id'],
                    $config['phone'],
                    '',
                    $config['name'] ?? null,
                    $config['workspace_id'] ?? null,
                );
                $outcome = ['result' => $client->wasRecentlyCreated ? 'created' : 'reused', 'id' => (string) $client->id];
            } catch (App\Exceptions\AmbiguousClientMatchException) {
                $outcome = ['result' => 'ambiguous'];
            }
            break;

        case 'update_phone':
            try {
                $client = App\Models\Client::findOrFail($config['client_id']);
                $merge->updateManualClient($client, $config['phone'], $config['name'], $config['notes'] ?? null);
                $outcome = ['result' => 'updated'];
            } catch (App\Exceptions\DuplicateClientPhoneException) {
                $outcome = ['result' => 'duplicate'];
            } catch (App\Exceptions\AmbiguousClientMatchException) {
                $outcome = ['result' => 'ambiguous'];
            }
            break;

        case 'link':
            try {
                $client = App\Models\Client::findOrFail($config['client_id']);
                $merge->linkProvider($client, $config['provider'], $config['provider_id']);
                $outcome = ['result' => 'linked'];
            } catch (App\Exceptions\ProviderLinkConflictException) {
                $outcome = ['result' => 'conflict'];
            }
            break;

        default:
            file_put_contents($config['result'], json_encode(['ok' => false, 'error' => 'unknown op']));
            exit(2);
    }

    file_put_contents($config['result'], json_encode([
        'ok' => true,
        'outcome' => $outcome,
        'pid' => getmypid(),
        'pg_backend_pid' => (int) Illuminate\Support\Facades\DB::select('SELECT pg_backend_pid() AS pid')[0]->pid,
    ]));

    exit(0);
} catch (Throwable $e) {
    file_put_contents($config['result'], json_encode([
        'ok' => false,
        'error' => get_class($e).': '.$e->getMessage(),
    ]));
    exit(1);
}
PHP;
    }
}
