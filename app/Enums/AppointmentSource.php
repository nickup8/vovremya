<?php

namespace App\Enums;

enum AppointmentSource: string
{
    case Telegram = 'telegram';
    case Max = 'max';
    case Admin = 'admin';
    case Vk = 'vk';

    public function label(): string
    {
        return match ($this) {
            self::Telegram => 'Telegram',
            self::Max => 'MAX',
            self::Admin => 'Админ-панель',
            self::Vk => 'VK',
        };
    }
}
