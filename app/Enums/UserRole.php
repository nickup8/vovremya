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

    public function canManageBilling(): bool
    {
        return $this === self::Owner;
    }

    public function canInviteAdmins(): bool
    {
        return $this === self::Owner;
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
