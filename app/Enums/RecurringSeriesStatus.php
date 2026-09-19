<?php

namespace App\Enums;

enum RecurringSeriesStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Cancelled = 'cancelled';
}
