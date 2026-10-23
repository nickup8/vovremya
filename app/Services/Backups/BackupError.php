<?php

namespace App\Services\Backups;

use App\Exceptions\Backups\BackupException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Санитизация сообщений об ошибках резервирования: без секретов,
 * с ограниченной длиной, пригодная для UI, БД, failed_jobs и уведомлений.
 */
final class BackupError
{
    /**
     * Безопасное текстовое описание исключения для записи в БД/UI
     * и для rethrow (без цепочки previous исходного исключения).
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
        // Значения из конфигурации (пароль БД, S3-секрет) вычищаются
        // буквально — маркер в тексте исключения не должен утечь.
        foreach (self::configuredSecrets() as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '***', $message);
            }
        }

        $message = (string) preg_replace('/password[=:]\s*\S+/iu', 'password=***', $message);
        $message = (string) preg_replace('/PGPASSWORD=\S+/iu', 'PGPASSWORD=***', $message);
        $message = (string) preg_replace('/secret[=:]\s*\S+/iu', 'secret=***', $message);

        return Str::limit(trim($message), 500);
    }

    /**
     * @return list<string>
     */
    private static function configuredSecrets(): array
    {
        $default = (string) config('database.default');
        $candidates = [
            (string) config("database.connections.{$default}.password"),
            (string) config('filesystems.disks.backups_s3.secret'),
            (string) config('filesystems.disks.backups_s3.key'),
        ];

        return array_values(array_filter($candidates, static fn (string $value): bool => strlen($value) >= 6));
    }
}
