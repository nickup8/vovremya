<?php

namespace App\Enums;

enum AppointmentSource: string
{
    case Telegram = 'telegram';
    case Max = 'max';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Telegram => 'Telegram',
            self::Max => 'MAX',
            self::Admin => 'Админ-панель',
        };
    }
}
