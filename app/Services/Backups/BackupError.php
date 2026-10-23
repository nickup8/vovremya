<?php

namespace App\Services\Backups;

use App\Exceptions\Backups\BackupException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Санитизация сообщений об ошибках резервирования: без секретов,
 * с ограниченной длиной, пригодная для UI, БД и уведомлений.
 */
final class BackupError
{
    /**
     * Безопасное текстовое описание исключения для записи в БД/UI.
     */
    public static function fromThrowable(Throwable $e): string
    {
        if ($e instanceof BackupException) {
            $message = $e->getMessage();
        } else {
            $message = 'Внутренняя ошибка ('.class_basename($e).').';
        }

        return self::sanitize($message);
    }

    /**
     * Ограниченный «хвост» stderr внешней команды без секретов.
     */
    public static function fromStderr(string $stderr): string
    {
        $stderr = trim($stderr);

        if ($stderr === '') {
            return '';
        }

        return self::sanitize(Str::limit($stderr, 400));
    }

    public static function sanitize(string $message): string
    {
        $message = (string) preg_replace('/password[=:]\s*\S+/iu', 'password=***', $message);
        $message = (string) preg_replace('/PGPASSWORD=\S+/iu', 'PGPASSWORD=***', $message);

        return Str::limit(trim($message), 500);
    }
}
