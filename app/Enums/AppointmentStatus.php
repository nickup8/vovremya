<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case Booked = 'booked';
    case PendingPayment = 'pending_payment';
    case Prepaid = 'prepaid';
    case NoShow = 'no_show';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    private const TRANSITIONS = [
        'booked' => ['no_show', 'paid', 'cancelled'],
        'pending_payment' => ['prepaid', 'cancelled'],
        'prepaid' => ['paid', 'no_show', 'cancelled'],
        'no_show' => ['booked', 'paid', 'cancelled'],
        'paid' => ['no_show'],
        'cancelled' => ['booked'],
    ];

    public static function allowedTransitions(): array
    {
        return array_map(
            fn (array $values) => array_map(
                fn (string $v) => self::from($v),
                $values,
            ),
            self::TRANSITIONS,
        );
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target->value, self::TRANSITIONS[$this->value] ?? [], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Booked => 'Записан',
            self::PendingPayment => 'Ожидает оплаты',
            self::Prepaid => 'Предоплата получена',
            self::NoShow => 'Неявка',
            self::Paid => 'Оплачен',
            self::Cancelled => 'Отменён',
        };
    }
}
