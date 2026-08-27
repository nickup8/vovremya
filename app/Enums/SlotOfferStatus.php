<?php

namespace App\Enums;

enum SlotOfferStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';
    case Invalidated = 'invalidated';
}
