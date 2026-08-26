<?php

namespace App\Services\Consent;

use App\Enums\AppointmentSource;
use App\Enums\MarketingConsentGrantResult;
use App\Models\PendingMarketingConsent;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class MarketingConsentGrantService
{
    public function __construct(
        private MarketingConsentService $consentService,
        private MarketingConsentContextService $contextService,
    ) {}

    public function grantPending(
        string $pendingId,
        AppointmentSource $platform,
        string $providerId,
        DateTimeInterface $occurredAt,
        array $metadata = [],
    ): MarketingConsentGrantResult {
        return DB::transaction(function () use (
            $pendingId, $platform, $providerId, $occurredAt, $metadata,
        ) {
            $pending = PendingMarketingConsent::query()
                ->whereKey($pendingId)
                ->lockForUpdate()
                ->first();

            if ($pending === null) {
                throw new \DomainException('Pending marketing consent not found.');
            }

            if ($pending->isConsumed()) {
                return MarketingConsentGrantResult::AlreadyConsumed;
            }

            if ($pending->isExpired()) {
                throw new \DomainException('Pending marketing consent has expired.');
            }

            if ($pending->source !== $platform->value || $pending->channel !== $platform->value) {
                throw new \DomainException('Pending consent platform does not match.');
            }

            $this->contextService->validatePending($platform, $providerId, $pending);

            $clients = $this->contextService->clientsForWorkspace(
                $platform,
                $providerId,
                $pending->workspace_id,
            );

            if ($clients->isEmpty()) {
                throw new \DomainException('No fan-out targets found.');
            }

            $clients = $clients->sortBy('id')->values();
            $clients->loadMissing('master');

            $canonicalMetadata = array_merge($metadata, [
                'pending_marketing_consent_id' => $pending->id,
                'workspace_id' => $pending->workspace_id,
            ]);

            foreach ($clients as $client) {
                $this->consentService->grant(
                    client: $client,
                    master: $client->master,
                    version: $pending->legal_version,
                    consentText: $pending->rendered_consent_text,
                    source: $pending->source,
                    channel: $pending->channel,
                    occurredAt: $occurredAt,
                    idempotencyKey: "marketing-pending:{$pending->id}:{$client->id}",
                    metadata: $canonicalMetadata,
                );
            }

            $pending->consumed_at = $occurredAt;
            $pending->save();

            return MarketingConsentGrantResult::Granted;
        });
    }
}
