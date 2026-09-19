<?php

namespace App\Enums;

enum RecurrenceType: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Ежедневно',
            self::Weekly => 'Еженедельно',
        };
    }
}
