<?php

namespace App\Exceptions;

use App\Enums\AppointmentStatus;

class InvalidStatusTransitionException extends \DomainException
{
    public function __construct(AppointmentStatus $from, AppointmentStatus $to)
    {
        parent::__construct(
            "Transition from [{$from->value}] to [{$to->value}] is not allowed."
        );
    }
}
