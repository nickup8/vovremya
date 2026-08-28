<?php

namespace App\Services;

use App\Enums\SlotInvalidationReason;
use App\Enums\SlotOpportunitySourceType;
use App\Enums\SlotRequestType;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class AutoFillMetricsService
{
    public function getMetrics(User $master, CarbonInterface $from, CarbonInterface $to): array
    {
        $masterId = $master->id;
        $workspaceId = $master->workspace_id;

        $requests = $this->requestMetrics($masterId, $workspaceId, $from, $to);
        $opportunities = $this->opportunityMetrics($masterId, $workspaceId, $from, $to);
        $offers = $this->offerFunnel($masterId, $workspaceId, $from, $to);
        $timing = $this->timingMetrics($masterId, $workspaceId, $from, $to);
        $failures = $this->failureMetrics($masterId, $workspaceId, $from, $to);
        $chains = $this->chainMetrics($masterId, $workspaceId, $from, $to);

        return array_merge($requests, $opportunities, $offers, $timing, $failures, $chains);
    }

    private function requestMetrics(string $masterId, string $workspaceId, CarbonInterface $from, CarbonInterface $to): array
    {
        $count = DB::table('slot_requests')
            ->where('master_id', $masterId)
            ->where('workspace_id', $workspaceId)
            ->where('type', SlotRequestType::Earlier->value)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->count();

        return ['requests_created' => $count];
    }

    private function opportunityMetrics(string $masterId, string $workspaceId, CarbonInterface $from, CarbonInterface $to): array
    {
        $sourceTypes = [
            SlotOpportunitySourceType::Cancellation->value,
            SlotOpportunitySourceType::Reschedule->value,
            SlotOpportunitySourceType::AutoFillReschedule->value,
        ];

        $autofillValue = SlotOpportunitySourceType::AutoFillReschedule->value;

        $rows = DB::table('slot_opportunities')
            ->where('master_id', $masterId)
            ->where('workspace_id', $workspaceId)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->whereIn('source_type', $sourceTypes)
            ->select(
                DB::raw('COUNT(*) as total'),
                DB::raw("COUNT(*) FILTER (WHERE source_type = '{$autofillValue}') as autofill_reschedule_count"),
                DB::raw("COUNT(*) FILTER (WHERE status = 'filled') as filled_count"),
            )
            ->first();

        return [
            'opportunities_created' => (int) $rows->total,
            'autofill_reschedule_count' => (int) $rows->autofill_reschedule_count,
            'filled_window_count' => (int) $rows->filled_count,
        ];
    }

    private function offerFunnel(string $masterId, string $workspaceId, CarbonInterface $from, CarbonInterface $to): array
    {
        $offers = DB::table('slot_offers')
            ->join('slot_requests', 'slot_offers.slot_request_id', '=', 'slot_requests.id')
            ->where('slot_requests.master_id', $masterId)
            ->where('slot_requests.workspace_id', $workspaceId)
            ->where('slot_offers.created_at', '>=', $from)
            ->where('slot_offers.created_at', '<', $to)
            ->select(
                DB::raw('COUNT(*) as offers_created'),
                DB::raw("COUNT(*) FILTER (WHERE slot_offers.sent_at IS NOT NULL) as offers_sent"),
                DB::raw("COUNT(*) FILTER (WHERE slot_offers.status = 'pending') as offers_pending"),
                DB::raw("COUNT(*) FILTER (WHERE slot_offers.status = 'accepted') as offers_accepted"),
                DB::raw("COUNT(*) FILTER (WHERE slot_offers.status = 'declined') as offers_declined"),
                DB::raw("COUNT(*) FILTER (WHERE slot_offers.status = 'expired') as offers_expired"),
                DB::raw("COUNT(*) FILTER (WHERE slot_offers.status = 'invalidated') as offers_invalidated"),
            )
            ->first();

        $created = (int) $offers->offers_created;
        $sent = (int) $offers->offers_sent;
        $accepted = (int) $offers->offers_accepted;
        $declined = (int) $offers->offers_declined;
        $expired = (int) $offers->offers_expired;

        $sendRate = $created > 0 ? ($sent / $created) * 100 : 0.0;

        $acceptDenominator = $accepted + $declined + $expired;
        $acceptanceRate = $acceptDenominator > 0 ? ($accepted / $acceptDenominator) * 100 : 0.0;

        return [
            'offers_created' => $created,
            'offers_sent' => $sent,
            'offers_pending' => (int) $offers->offers_pending,
            'offers_accepted' => $accepted,
            'offers_declined' => $declined,
            'offers_expired' => $expired,
            'offers_invalidated' => (int) $offers->offers_invalidated,
            'send_rate' => round($sendRate, 2),
            'acceptance_rate' => round($acceptanceRate, 2),
        ];
    }

    private function timingMetrics(string $masterId, string $workspaceId, CarbonInterface $from, CarbonInterface $to): array
    {
        // median_time_to_accept_seconds: accepted_at - sent_at
        $medianAccept = DB::table('slot_offers')
            ->join('slot_requests', 'slot_offers.slot_request_id', '=', 'slot_requests.id')
            ->where('slot_requests.master_id', $masterId)
            ->where('slot_requests.workspace_id', $workspaceId)
            ->where('slot_offers.created_at', '>=', $from)
            ->where('slot_offers.created_at', '<', $to)
            ->where('slot_offers.status', 'accepted')
            ->whereNotNull('slot_offers.sent_at')
            ->whereNotNull('slot_offers.accepted_at')
            ->whereColumn('slot_offers.accepted_at', '>=', 'slot_offers.sent_at')
            ->select(DB::raw('PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (slot_offers.accepted_at - slot_offers.sent_at))) as median_seconds'))
            ->value('median_seconds');

        // opportunity_to_offer_median_seconds: offer.created_at - opportunity.created_at
        $medianOppToOffer = DB::table('slot_offers')
            ->join('slot_requests', 'slot_offers.slot_request_id', '=', 'slot_requests.id')
            ->join('slot_opportunities', 'slot_offers.slot_opportunity_id', '=', 'slot_opportunities.id')
            ->where('slot_requests.master_id', $masterId)
            ->where('slot_requests.workspace_id', $workspaceId)
            ->where('slot_offers.created_at', '>=', $from)
            ->where('slot_offers.created_at', '<', $to)
            ->whereColumn('slot_offers.created_at', '>=', 'slot_opportunities.created_at')
            ->select(DB::raw('PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (slot_offers.created_at - slot_opportunities.created_at))) as median_seconds'))
            ->value('median_seconds');

        return [
            'median_time_to_accept_seconds' => $medianAccept !== null ? (int) round($medianAccept) : null,
            'opportunity_to_offer_median_seconds' => $medianOppToOffer !== null ? (int) round($medianOppToOffer) : null,
        ];
    }

    private function failureMetrics(string $masterId, string $workspaceId, CarbonInterface $from, CarbonInterface $to): array
    {
        // Offer invalidations by reason
        $offerReasons = DB::table('slot_offers')
            ->join('slot_requests', 'slot_offers.slot_request_id', '=', 'slot_requests.id')
            ->where('slot_requests.master_id', $masterId)
            ->where('slot_requests.workspace_id', $workspaceId)
            ->where('slot_offers.created_at', '>=', $from)
            ->where('slot_offers.created_at', '<', $to)
            ->where('slot_offers.status', 'invalidated')
            ->select(
                'slot_offers.invalidation_reason',
                DB::raw('COUNT(*) as cnt'),
            )
            ->groupBy('slot_offers.invalidation_reason')
            ->get();

        $offerReasonMap = [];
        foreach ($offerReasons as $row) {
            $key = $row->invalidation_reason ?? 'unknown';
            $offerReasonMap[$key] = (int) $row->cnt;
        }

        // Opportunity invalidations by reason
        $oppReasons = DB::table('slot_opportunities')
            ->where('master_id', $masterId)
            ->where('workspace_id', $workspaceId)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->where('status', 'invalidated')
            ->select(
                'invalidation_reason',
                DB::raw('COUNT(*) as cnt'),
            )
            ->groupBy('invalidation_reason')
            ->get();

        $oppInvalidated = 0;
        $oppReasonMap = [];
        foreach ($oppReasons as $row) {
            $count = (int) $row->cnt;
            $oppInvalidated += $count;
            $key = $row->invalidation_reason ?? 'unknown';
            $oppReasonMap[$key] = $count;
        }

        return [
            'invalidations_by_reason' => $offerReasonMap,
            'opportunities_invalidated' => $oppInvalidated,
            'opportunity_invalidations_by_reason' => $oppReasonMap,
        ];
    }

    private function chainMetrics(string $masterId, string $workspaceId, CarbonInterface $from, CarbonInterface $to): array
    {
        $stats = DB::table('slot_opportunities')
            ->where('master_id', $masterId)
            ->where('workspace_id', $workspaceId)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->select(
                DB::raw('COUNT(DISTINCT chain_id) as chain_count'),
                DB::raw('COUNT(*) as total_opps'),
            )
            ->first();

        $chainCount = (int) $stats->chain_count;
        $totalOpps = (int) $stats->total_opps;

        $avgPerChain = $chainCount > 0 ? round($totalOpps / $chainCount, 2) : 0.0;

        $maxPerChain = 0;
        if ($chainCount > 0) {
            $maxPerChain = (int) DB::table('slot_opportunities')
                ->where('master_id', $masterId)
                ->where('workspace_id', $workspaceId)
                ->where('created_at', '>=', $from)
                ->where('created_at', '<', $to)
                ->groupBy('chain_id')
                ->select(DB::raw('COUNT(*) as cnt'))
                ->orderByDesc('cnt')
                ->limit(1)
                ->value('cnt');
        }

        return [
            'chain_count' => $chainCount,
            'average_opportunities_per_chain' => $avgPerChain,
            'max_opportunities_per_chain' => $maxPerChain,
        ];
    }
}
