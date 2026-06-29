<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case PendingClient = 'pending_client';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';

    public static function allowedTransitions(): array
    {
        return [
            self::PendingClient->value => [self::Confirmed, self::Cancelled],
            self::Confirmed->value => [self::Completed, self::NoShow, self::Cancelled],
            self::Completed->value => [],
            self::NoShow->value => [],
            self::Cancelled->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::allowedTransitions()[$this->value] ?? [], true);
    }

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
