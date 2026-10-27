<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * В области поиска нашлось несколько клиентов с одним нормализованным телефоном.
 * Выбор одной карточки произвольным образом запрещён — нужен ручной разбор/слияние.
 */
class AmbiguousClientMatchException extends RuntimeException
{
    public static function forPhone(string $normalizedPhone): self
    {
        return new self('Найдено несколько карточек клиентов с одним номером. Выбор автоматически невозможен.');
    }
}
