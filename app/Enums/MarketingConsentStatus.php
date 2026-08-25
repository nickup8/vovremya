<?php

namespace App\Enums;

enum MarketingConsentStatus: string
{
    case Absent = 'absent';
    case Granted = 'granted';
    case Revoked = 'revoked';
}
