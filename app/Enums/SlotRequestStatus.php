<?php

namespace App\Enums;

enum SlotRequestStatus: string
{
    case Active = 'active';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
