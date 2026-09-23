<?php

namespace App\Enums;

enum BillingSubscriptionStatus: string
{
    case PendingInitial = 'pending_initial';
    case Active = 'active';
    case PastDue = 'past_due';
    case Expired = 'expired';
    case Canceled = 'canceled';
}
