<?php

/*
|--------------------------------------------------------------------------
| PHPUnit Process-Wide Test Lock
|--------------------------------------------------------------------------
|
| Serializes access to the shared PostgreSQL test database so that only
| one PHPUnit process runs migrate:fresh + tests at a time. Uses flock()
| on a temp-directory file — automatically released when the PHP process
| terminates (normal exit, test failure, exception, or OS kill).
|
*/

require __DIR__.'/../vendor/autoload.php';

$lockName = 'vovremya-tests-'.md5(__DIR__.getcwd()).'.lock';
$lockPath  = sys_get_temp_dir().DIRECTORY_SEPARATOR.$lockName;

$lockHandle = fopen($lockPath, 'c');
if ($lockHandle === false) {
    fwrite(STDERR, "Cannot open lock file: {$lockPath}\n");
    exit(1);
}

// Try non-blocking first so we can print a message once.
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Другой тестовый процесс использует общую test DB. Ожидание...\n");

    // Blocking acquire — wait until the other process finishes.
    if (!flock($lockHandle, LOCK_EX)) {
        fwrite(STDERR, "Cannot acquire lock: {$lockPath}\n");
        exit(1);
    }
}

// Lock acquired — will be held until this PHP process terminates.
// fopen() handle stays open; flock() is released automatically when the
// file descriptor is closed at process shutdown (or on fclose below).
register_shutdown_function(static function () use ($lockHandle) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
});
