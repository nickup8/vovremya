<?php

namespace App\Services;

use App\Enums\SlotOpportunitySourceType;
use App\Enums\SlotOpportunityStatus;
use App\Models\SlotOpportunity;
use App\Services\Notification\MasterNotificationService;
use Illuminate\Support\Facades\Log;

class AutofillChainCompletionService
{
    public function finish(string $chainId, ?string $reason = null): void
    {
        $opportunities = SlotOpportunity::where('chain_id', $chainId)->get();

        if ($opportunities->isEmpty()) {
            Log::warning('[AutoFill] Chain completion: no opportunities found', [
                'chain_id' => $chainId,
            ]);
            return;
        }

        $root = $opportunities->first(fn ($o) => $o->source_type !== SlotOpportunitySourceType::AutoFillReschedule);

        if ($root === null) {
            Log::warning('[AutoFill] Chain completion: no root opportunity', [
                'chain_id' => $chainId,
            ]);
            return;
        }

        $filled = $opportunities->filter(fn ($o) => $o->status === SlotOpportunityStatus::Filled);

        if ($filled->isEmpty()) {
            return;
        }

        $master = $root->master;

        if ($master === null) {
            Log::warning('[AutoFill] Chain completion: master not found', [
                'chain_id' => $chainId,
                'root_id' => $root->id,
            ]);
            return;
        }

        $tz = $master->getTimezone();

        $rootDatetime = $root->start_time->timezone($tz)->format('d.m.Y \в H:i');

        $moves = [];

        foreach ($filled as $opp) {
            $offer = $opp->offers()
                ->where('status', \App\Enums\SlotOfferStatus::Accepted)
                ->with(['request.client', 'request.appointment'])
                ->first();

            if ($offer === null) {
                continue;
            }

            $clientName = $offer->request?->client?->name ?? __('bot.fallback.client_name');
            $oldSnapshot = $offer->request?->appointment_start_time_snapshot;

            if ($oldSnapshot === null) {
                continue;
            }

            $oldDatetime = $oldSnapshot->timezone($tz)->format('d.m H:i');
            $newDatetime = $opp->start_time->timezone($tz)->format('d.m H:i');

            $moves[] = "{$clientName}: {$oldDatetime} → {$newDatetime}";
        }

        if ($moves === []) {
            return;
        }

        // Atomic claim
        $updated = SlotOpportunity::whereKey($root->id)
            ->whereNull('master_notification_attempted_at')
            ->update(['master_notification_attempted_at' => now()]);

        if ($updated === 0) {
            return;
        }

        $text = __('bot.autofill_chain_summary', [
            'root_datetime' => $rootDatetime,
            'count' => count($moves),
            'moves' => implode("\n", $moves),
        ]);

        try {
            app(MasterNotificationService::class)->sendToMaster($master, $text);
        } catch (\Throwable $e) {
            Log::error('[AutoFill] Chain completion notification failed', [
                'chain_id' => $chainId,
                'root_id' => $root->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
