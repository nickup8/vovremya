<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Общий механизм повторного создания мастера при гонке за master_slug:
 * каждая попытка заново генерирует slug и выполняет User::create внутри
 * транзакции (или savepoint, если внешняя транзакция уже открыта);
 * перехват — только после отката попытки.
 */
class MasterSlugCreateRetry
{
    /**
     * Максимум попыток всего (включая первую).
     */
    public const MAX_ATTEMPTS = 3;

    /**
     * @template TReturn
     *
     * @param  \Closure(): string  $generateSlug
     * @param  \Closure(string): TReturn  $attempt  создание пользователя (и связанных записей) по переданному slug
     * @return TReturn
     *
     * @throws QueryException при исчерпании попыток или не- Slug-конфликте
     */
    public function run(\Closure $generateSlug, \Closure $attempt): mixed
    {
        $lastException = null;

        for ($try = 1; $try <= self::MAX_ATTEMPTS; $try++) {
            try {
                return DB::transaction(static fn () => $attempt($generateSlug()));
            } catch (QueryException $e) {
                if (! self::isMasterSlugUniqueViolation($e)) {
                    throw $e;
                }

                $lastException = $e;
            }
        }

        throw $lastException;
    }

    /**
     * Точное совпадение: SQLSTATE 23505 из метаданных ошибки (errorInfo[0])
     * и точное имя индекса users_master_slug_unique в основной строке
     * исходного сообщения PostgreSQL (русская локаль — $index/$columns пусты).
     * DETAIL/HINT и текст SQL-вставки, добавленный QueryException, не ищутся.
     */
    public static function isMasterSlugUniqueViolation(QueryException $exception): bool
    {
        if (($exception->errorInfo[0] ?? null) !== '23505') {
            return false;
        }

        $originalMessage = $exception->getPrevious()?->getMessage();

        if ($originalMessage === null || $originalMessage === '') {
            $originalMessage = Str::before($exception->getMessage(), ' (Connection:');
        }

        $mainLine = Str::before($originalMessage, "\n");

        return str_contains($mainLine, '"users_master_slug_unique"');
    }
}
