<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case PendingClient = 'pending_client';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PendingClient => 'Ожидает подтверждения',
            self::Confirmed => 'Подтверждено',
            self::Completed => 'Оплачено',
            self::NoShow => 'No-Show',
            self::Cancelled => 'Отменена',
        };
    }
}
