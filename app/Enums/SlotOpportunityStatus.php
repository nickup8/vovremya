<?php

namespace App\Enums;

enum SlotOpportunityStatus: string
{
    case Open = 'open';
    case Filled = 'filled';
    case Expired = 'expired';
    case Invalidated = 'invalidated';
}
