<?php

namespace App\Services\Consent;

use App\Enums\ConsentAction;
use App\Enums\ConsentType;
use App\Enums\MarketingConsentStatus;
use App\Models\Client;
use App\Models\ClientConsent;

class MarketingConsentResolver
{
    public function resolve(Client $client): ConsentState
    {
        $latest = ClientConsent::query()
            ->where('client_id', $client->id)
            ->where('type', ConsentType::Marketing)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            return new ConsentState(
                status: MarketingConsentStatus::Absent,
                event: null,
            );
        }

        return new ConsentState(
            status: $latest->action === ConsentAction::Granted
                ? MarketingConsentStatus::Granted
                : MarketingConsentStatus::Revoked,
            event: $latest,
        );
    }
}
