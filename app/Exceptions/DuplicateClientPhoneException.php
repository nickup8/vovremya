<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * В области поиска уже есть карточка с этим нормализованным телефоном.
 * Повторное создание/смена телефона на такой же — отказ валидации без изменений.
 */
class DuplicateClientPhoneException extends RuntimeException
{
    public static function create(): self
    {
        return new self('Клиент с таким номером телефона уже существует.');
    }
}
