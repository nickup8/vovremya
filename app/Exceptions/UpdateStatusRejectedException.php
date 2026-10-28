<?php

namespace App\Exceptions;

/**
 * Отклонение изменения записи в updateStatus: ошибка валидации, которая
 * должна откатить всю транзакцию сохранения и вернуть ответ без
 * частично применённых изменений.
 */
class UpdateStatusRejectedException extends \RuntimeException
{
    /**
     * @param  string  $errorKey  ключ ошибки (status, time, invalid_status…)
     * @param  string  $detail  сообщение для пользователя
     * @param  bool  $asJson  всегда JSON-ответ (формат invalid_status и т.п.)
     * @param  int  $httpStatus  статус JSON-ответа
     * @param  array<string, mixed>  $extra  дополнительные поля JSON-ответа
     * @param  bool  $errorsOnly  всегда back()->withErrors, без JSON
     */
    public function __construct(
        public readonly string $errorKey,
        public readonly string $detail,
        public readonly bool $asJson = false,
        public readonly int $httpStatus = 422,
        public readonly array $extra = [],
        public readonly bool $errorsOnly = false,
    ) {
        parent::__construct($detail);
    }
}
