<?php

namespace App\Services\Consent;

use App\Enums\AppointmentSource;
use App\Models\Client;
use App\Models\PendingMarketingConsent;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MarketingConsentContextService
{
    public function findGrantableContexts(
        AppointmentSource $platform,
        string $providerId,
    ): Collection {
        $column = $this->providerColumn($platform);
        $now = now();

        // Query 1: set-based grantable workspace IDs via JOIN LATERAL
        // Picks the latest active subscription per workspace, then checks
        // that its tariff plan includes client_reactivation.
        $workspaceIds = DB::table('clients as c')
            ->join('workspaces as w', 'w.id', '=', 'c.workspace_id')
            ->joinLateral(
                DB::table('subscriptions as s')
                    ->select('s.tariff_plan_id')
                    ->whereColumn('s.workspace_id', 'w.id')
                    ->where('s.status', 'active')
                    ->where('s.expires_at', '>', $now)
                    ->orderByDesc('s.expires_at')
                    ->limit(1),
                'active_sub',
            )
            ->join('tariff_plans as tp', 'tp.id', '=', 'active_sub.tariff_plan_id')
            ->where("c.{$column}", $providerId)
            ->whereNotNull('c.workspace_id')
            ->whereRaw("tp.features::jsonb @> ?::jsonb", ['["client_reactivation"]'])
            ->distinct()
            ->pluck('w.id')
            ->all();

        if (empty($workspaceIds)) {
            return collect();
        }

        // Query 2: batch load workspace models
        $workspaces = Workspace::query()
            ->whereIn('id', $workspaceIds)
            ->get()
            ->keyBy('id');

        // Query 3: batch load all provider clients for these workspaces
        $clients = Client::query()
            ->where($column, $providerId)
            ->whereIn('workspace_id', $workspaceIds)
            ->orderBy('workspace_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return $clients
            ->groupBy('workspace_id')
            ->map(function (Collection $group) use ($workspaces) {
                $workspaceId = $group->first()->workspace_id;
                $workspace = $workspaces->get($workspaceId);

                return new MarketingConsentContext(
                    workspace: $workspace,
                    representativeClient: $group->first(),
                );
            })
            ->values();
    }

    public function validateSelectedClient(
        AppointmentSource $platform,
        string $providerId,
        string $clientId,
    ): Client {
        $column = $this->providerColumn($platform);

        $client = Client::query()
            ->where('id', $clientId)
            ->where($column, $providerId)
            ->first();

        if (! $client) {
            throw new \DomainException('Client not found or provider mismatch.');
        }

        if ($client->workspace_id === null) {
            throw new \DomainException('Client has no workspace.');
        }

        $workspace = Workspace::find($client->workspace_id);

        if (! $workspace) {
            throw new \DomainException('Workspace not found.');
        }

        if (! $workspace->hasFeature('client_reactivation')) {
            throw new \DomainException('Workspace does not have client_reactivation.');
        }

        return $client->setRelation('workspace', $workspace);
    }

    public function clientsForWorkspace(
        AppointmentSource $platform,
        string $providerId,
        string $workspaceId,
    ): Collection {
        $column = $this->providerColumn($platform);

        return Client::query()
            ->where($column, $providerId)
            ->where('workspace_id', $workspaceId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function validatePending(
        AppointmentSource $platform,
        string $providerId,
        PendingMarketingConsent $pending,
    ): Client {
        $client = $this->validateSelectedClient($platform, $providerId, $pending->client_id);

        if ($client->workspace_id !== $pending->workspace_id) {
            throw new \DomainException('Client workspace does not match pending consent workspace.');
        }

        return $client;
    }

    private function providerColumn(AppointmentSource $platform): string
    {
        return match ($platform) {
            AppointmentSource::Telegram => 'telegram_id',
            AppointmentSource::Max => 'max_id',
            default => throw new \InvalidArgumentException("Unsupported platform: {$platform->value}"),
        };
    }
}
