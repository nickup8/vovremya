<?php

/**
 * Дочерний процесс регрессии «grant × markFailed» (R01).
 *
 * Выполняет РОВНО ОДИН реальный POST /api/miniapp/vk-permission на
 * собственном соединении с PostgreSQL — отдельный процесс = отдельный
 * backend, вложенный HTTP-вызов в транзакции теста здесь не используется
 * и не доказывал бы межтранзакционное поведение.
 *
 * Печатает в stdout:
 *   PID:<pg_backend_pid> — backend-ид второго соединения (до запроса);
 *   STATUS:<http-код>    — итог запроса.
 *
 * Окружение задаёт родитель (proc_open): VK_* — подпись как в тестовой
 * конфигурации. dispatch в контроллере уходит в null-соединение,
 * зарегистрированное в скрипте после bootstrap: job НЕ выполняется в
 * дочернем процессе и не попадает в Queue::fake() родителя
 * (продолжение — ответственный retry в тесте).
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';

/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// Dispatch контроллера в дочернем процессе не должен ни выполняться,
// ни попадать в Queue::fake() родителя: queue-конфиг приложения не
// описывает null-соединение, задаём его явно (повторный bootstrap на
// handle из-за hasBeenBootstrapped не происходит — конфиг сохраняется).
config([
    'queue.default' => 'null',
    'queue.connections.null' => ['driver' => 'null'],
]);

$options = json_decode((string) ($argv[1] ?? ''), true);

if (! is_array($options) || empty($options['appointment_id']) || empty($options['authorization'])) {
    fwrite(STDERR, "missing options\n");
    exit(64);
}

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

echo 'STATUS:'.$code."\n";
flush();

exit($code === 200 ? 0 : 1);
