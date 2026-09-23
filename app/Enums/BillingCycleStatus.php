<?php

namespace App\Enums;

enum BillingCycleStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Expired = 'expired';
    case Canceled = 'canceled';
    case Refunded = 'refunded';
}
