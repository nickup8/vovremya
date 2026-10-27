<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * После ожидания блокировки карточка больше не соответствует
 * подтверждённому номеру или разрешённой области (телефон изменили
 * конкурентно). Подтверждение отклоняется без привязок и изменений.
 */
class StaleClientConfirmationException extends RuntimeException
{
    public static function create(): self
    {
        return new self('Данные клиента изменились, подтверждение устарело.');
    }
}
