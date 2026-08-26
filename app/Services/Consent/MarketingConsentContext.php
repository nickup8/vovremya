<?php

namespace App\Services\Consent;

use App\Models\Client;
use App\Models\Workspace;

readonly class MarketingConsentContext
{
    public function __construct(
        public Workspace $workspace,
        public Client $representativeClient,
    ) {}
}
