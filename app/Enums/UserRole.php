<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Master = 'master';

    public function canManageTeam(): bool
    {
        return in_array($this, [self::Owner, self::Admin]);
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Владелец',
            self::Admin => 'Администратор',
            self::Master => 'Мастер',
        };
    }
}
