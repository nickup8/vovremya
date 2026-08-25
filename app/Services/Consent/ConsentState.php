<?php

namespace App\Services\Consent;

use App\Enums\MarketingConsentStatus;
use App\Models\ClientConsent;

readonly class ConsentState
{
    public function __construct(
        public MarketingConsentStatus $status,
        public ?ClientConsent $event,
    ) {}
}
