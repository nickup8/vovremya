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
     * Точное совпадение: SQLSTATE 23505 и имя индекса users_master_slug_unique
     * в исходном сообщении PostgreSQL (русская локаль — $index/$columns пусты).
     * Имя не ищется в тексте SQL-вставки, добавленном QueryException.
     */
    public static function isMasterSlugUniqueViolation(QueryException $exception): bool
    {
        $driverMessage = $exception->getPrevious()?->getMessage();

        if ($driverMessage === null || $driverMessage === '') {
            $driverMessage = Str::before($exception->getMessage(), ' (Connection:');
        }

        $sqlState = $exception->errorInfo[0] ?? (string) $exception->getCode();

        $hasSqlState23505 = $sqlState === '23505'
            || str_contains($driverMessage, '23505');

        return $hasSqlState23505
            && str_contains($driverMessage, 'users_master_slug_unique');
    }
}
