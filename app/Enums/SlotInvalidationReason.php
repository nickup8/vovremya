<?php

namespace App\Enums;

enum SlotInvalidationReason: string
{
    case MissingRelations = 'missing_relations';
    case UnsupportedDeliveryChannel = 'unsupported_delivery_channel';
    case MissingMaxIdentity = 'missing_max_identity';
    case DeliveryFailed = 'delivery_failed';
    case StaleRequest = 'stale_request';
    case SlotUnavailable = 'slot_unavailable';
    case SlotTaken = 'slot_taken';
    case EligibilityChanged = 'eligibility_changed';
    case SourceChanged = 'source_changed';
    case Other = 'other';
}
