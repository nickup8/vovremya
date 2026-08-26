<?php

namespace App\Enums;

enum SlotOpportunitySourceType: string
{
    case Cancellation = 'cancellation';
    case Reschedule = 'reschedule';
    case AutoFillReschedule = 'autofill_reschedule';
}
