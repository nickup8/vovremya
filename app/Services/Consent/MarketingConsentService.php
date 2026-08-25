<?php

namespace App\Services\Consent;

use App\Enums\ConsentAction;
use App\Enums\ConsentType;
use App\Exceptions\ConsentIdempotencyCollisionException;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class MarketingConsentService
{
    public function grant(
        Client $client,
        User $master,
        string $version,
        string $consentText,
        string $source,
        string $channel,
        DateTimeInterface $occurredAt,
        string $idempotencyKey,
        array $metadata = [],
    ): ClientConsent {
        $this->guardRequired($version, 'version');
        $this->guardRequired($consentText, 'consent_text');
        $this->guardRequired($source, 'source');
        $this->guardRequired($channel, 'channel');
        $this->guardRequired($idempotencyKey, 'idempotency_key');

        return $this->record(
            client: $client,
            master: $master,
            action: ConsentAction::Granted,
            source: $source,
            channel: $channel,
            occurredAt: $occurredAt,
            idempotencyKey: $idempotencyKey,
            metadata: $metadata,
            version: $version,
            consentText: $consentText,
        );
    }

    public function revoke(
        Client $client,
        User $master,
        string $source,
        string $channel,
        DateTimeInterface $occurredAt,
        string $idempotencyKey,
        array $metadata = [],
    ): ClientConsent {
        $this->guardRequired($source, 'source');
        $this->guardRequired($channel, 'channel');
        $this->guardRequired($idempotencyKey, 'idempotency_key');

        return $this->record(
            client: $client,
            master: $master,
            action: ConsentAction::Revoked,
            source: $source,
            channel: $channel,
            occurredAt: $occurredAt,
            idempotencyKey: $idempotencyKey,
            metadata: $metadata,
        );
    }

    private function record(
        Client $client,
        User $master,
        ConsentAction $action,
        string $source,
        string $channel,
        DateTimeInterface $occurredAt,
        string $idempotencyKey,
        array $metadata,
        ?string $version = null,
        ?string $consentText = null,
    ): ClientConsent {
        $this->assertMasterClientContext($client, $master);

        $metadata = array_merge($metadata, ['idempotency_key' => $idempotencyKey]);

        return DB::transaction(function () use (
            $client, $master, $action, $source, $channel, $occurredAt,
            $idempotencyKey, $metadata, $version, $consentText,
        ) {
            // Lock client row to serialize concurrent consent writes for this client
            DB::table('clients')->where('id', $client->id)->lockForUpdate()->first();

            $existing = ClientConsent::query()
                ->where('client_id', $client->id)
                ->where('type', ConsentType::Marketing)
                ->where('metadata->idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                if ($existing->action !== $action) {
                    throw new ConsentIdempotencyCollisionException(
                        $idempotencyKey,
                        $existing->action->value,
                        $action->value,
                    );
                }

                return $existing;
            }

            return ClientConsent::create([
                'client_id' => $client->id,
                'workspace_id' => $client->workspace_id,
                'master_id' => $master->id,
                'type' => ConsentType::Marketing,
                'action' => $action,
                'version' => $version,
                'source' => $source,
                'channel' => $channel,
                'consent_text' => $consentText,
                'metadata' => $metadata,
                'occurred_at' => $occurredAt,
            ]);
        });
    }

    private function assertMasterClientContext(Client $client, User $master): void
    {
        if ($client->workspace_id !== null && $client->workspace_id !== $master->workspace_id) {
            throw new \DomainException('Master does not belong to the same workspace as client.');
        }
    }

    private function guardRequired(string $value, string $field): void
    {
        if ($value === '') {
            throw new \InvalidArgumentException("{$field} is required.");
        }
    }
}
