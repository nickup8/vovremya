<?php

namespace App\Enums;

enum BillingCycleOrigin: string
{
    case Payment = 'payment';
    case Renewal = 'renewal';
    case AdminGrant = 'admin_grant';
    case LegacyGrant = 'legacy_grant';
}
