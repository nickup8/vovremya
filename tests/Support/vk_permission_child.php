<?php

/**
 * Дочерний процесс регрессии «grant × markFailed» (R01).
 *
 * Выполняет РОВНО ОДИН реальный POST /api/miniapp/vk-permission на
 * собственном соединении с PostgreSQL — отдельный процесс = отдельный
 * backend, вложенный HTTP-вызов в транзакции теста здесь не используется
 * и не доказывал бы межтранзакционное поведение.
 *
 * До POST скрипт сверяет свою ФАКТИЧЕСКУЮ подключённость (сервер, порт,
 * БД, схема) с ожидаемой тестовой конфигурацией подключения родителя
 * (options['db']): при несовпадении печатает DB_MISMATCH и завершается
 * кодом 2, не выполняя POST и не внося никаких изменений. Различия
 * backend PID для согласования недостаточно.
 *
 * Печатает в stdout:
 *   DB:<host>:<port>:<database>:<schema> — сверенная подключённость (до POST);
 *   DB_MISMATCH:expected=… actual=…      — только при несовпадении;
 *   PID:<pg_backend_pid>                 — backend-ид второго соединения;
 *   DISPATCH:<eventId>                   — КАЖДЫЙ job, поставленный
 *                                          контроллером (перехват фейком);
 *   STATUS:<http-код>                    — итог запроса.
 *
 * Окружение задаёт родитель (proc_open): VK_* — подпись как в тестовой
 * конфигурации. Перехват постановок — Queue::fake() дочернего процесса:
 * job не выполняется, но каждый dispatch (eventId) становится видимым
 * родителю — счётчик Queue::fake() родителя такой dispatch не видит.
 */

use App\Jobs\SendAppointmentNotificationJob;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';

/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// Перехват постановок контроллера: fake не выполняет jobs и хранит их
// в памяти процесса — eventId каждого будет напечатан после запроса.
Queue::fake();

$options = json_decode((string) ($argv[1] ?? ''), true);

if (! is_array($options) || empty($options['appointment_id']) || empty($options['authorization'])) {
    fwrite(STDERR, "missing options\n");
    exit(64);
}

$actual = DB::selectOne(
    "select coalesce(inet_server_addr()::text, '') as host,"
    .' cast(coalesce(inet_server_port(), 0) as int) as port,'
    .' current_database() as database,'
    .' current_schema() as schema'
);

$actualTarget = [
    'host' => (string) $actual->host,
    'port' => (int) $actual->port,
    'database' => (string) $actual->database,
    'schema' => (string) $actual->schema,
];

$expectedTarget = [
    'host' => (string) ($options['db']['host'] ?? ''),
    'port' => (int) ($options['db']['port'] ?? 0),
    'database' => (string) ($options['db']['database'] ?? ''),
    'schema' => (string) ($options['db']['schema'] ?? ''),
];

if ($expectedTarget['host'] === '' || $actualTarget !== $expectedTarget) {
    echo 'DB_MISMATCH:expected='.json_encode($expectedTarget)
        .' actual='.json_encode($actualTarget)."\n";
    flush();

    // Без изменений: ни POST, ни каких-либо записей.
    exit(2);
}

echo 'DB:'.$actualTarget['host'].':'.$actualTarget['port']
    .':'.$actualTarget['database'].':'.$actualTarget['schema']."\n";
flush();

$backendPid = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
echo 'PID:'.$backendPid."\n";
flush();

$request = Request::create(
    'http://localhost/api/miniapp/vk-permission',
    'POST',
    [
        'appointment_id' => (string) $options['appointment_id'],
        'granted' => true,
    ],
    [],
    [],
    [
        'HTTP_Authorization' => (string) $options['authorization'],
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ],
);

$response = $kernel->handle($request);
$code = $response->getStatusCode();
$kernel->terminate($request, $response);

foreach (Queue::pushed(SendAppointmentNotificationJob::class) as $dispatched) {
    echo 'DISPATCH:'.$dispatched->eventId."\n";
}

echo 'STATUS:'.$code."\n";
flush();

exit($code === 200 ? 0 : 1);
