<?php

namespace App\Services\Consent;

use App\Enums\MarketingConsentStatus;
use App\Models\Client;
use App\Models\User;

class MarketingConsentPolicy
{
    public function __construct(
        private readonly MarketingConsentResolver $resolver,
    ) {}

    public function canSend(User $master, Client $client, string $requiredVersion): bool
    {
        // Client must belong to the same workspace as the master
        if ($client->workspace_id !== null && $client->workspace_id !== $master->workspace_id) {
            return false;
        }

        $state = $this->resolver->resolve($client);

        if ($state->status !== MarketingConsentStatus::Granted) {
            return false;
        }

        $event = $state->event;

        if ($event === null) {
            return false;
        }

        // Event must have a non-null workspace snapshot matching master
        if ($event->workspace_id === null || $event->workspace_id !== $master->workspace_id) {
            return false;
        }

        // Version must match exactly
        if ($event->version !== $requiredVersion) {
            return false;
        }

        return true;
    }
}
