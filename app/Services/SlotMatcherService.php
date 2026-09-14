<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\SlotInvalidationReason;
use App\Enums\SlotOfferStatus;
use App\Enums\SlotOpportunityStatus;
use App\Enums\SlotRequestStatus;
use App\Enums\SlotRequestType;
use App\Models\Appointment;
use App\Models\SlotOffer;
use App\Models\SlotOpportunity;
use App\Models\SlotRequest;
use App\Services\Booking\AvailabilityService;
use Illuminate\Support\Carbon;

class SlotMatcherService
{
    public function __construct(
        private AvailabilityService $availabilityService,
        private SlotRequestService $requestService,
        private SlotOpportunityService $opportunityService,
        private SlotOfferService $offerService,
        private AutofillChainCompletionService $completionService,
    ) {}

    public function matchOpportunity(SlotOpportunity $opportunity): ?SlotOffer
    {
        $opportunity = $opportunity->fresh();

        // 1. Status guard
        if ($opportunity->status !== SlotOpportunityStatus::Open) {
            // Filled → chain continuation in progress, don't finish
            // Expired/Invalidated → terminal, finish chain
            if (in_array($opportunity->status, [SlotOpportunityStatus::Expired, SlotOpportunityStatus::Invalidated], true)) {
                $this->completionService->finish($opportunity->chain_id, $opportunity->status->value);
            }
            return null;
        }

        // 2. Already has pending offer
        if ($opportunity->pendingOffer !== null) {
            return null;
        }

        // 3. Past opportunity → expire
        if ($opportunity->start_time->lte(Carbon::now())) {
            $this->opportunityService->expire($opportunity);
            $this->completionService->finish($opportunity->chain_id, 'expired');
            return null;
        }

        // 4. Master AutoFill enabled
        $master = $opportunity->master;
        if ($master === null || ! $master->isAutoFillEnabled()) {
            $this->completionService->finish($opportunity->chain_id, 'autofill_disabled');
            return null;
        }

        // 5. MasterService active
        $masterService = $opportunity->masterService;
        if ($masterService === null || ! $masterService->is_active) {
            $this->completionService->finish($opportunity->chain_id, 'service_inactive');
            return null;
        }

        // 6. Availability check (once, before candidate loop)
        if (! $this->availabilityService->isSlotAvailable(
            $master,
            Carbon::instance($opportunity->start_time),
            $opportunity->duration,
            null,
        )) {
            $this->opportunityService->invalidate($opportunity, SlotInvalidationReason::SlotUnavailable);
            $this->completionService->finish($opportunity->chain_id, 'slot_unavailable');
            return null;
        }

        // 7. Load candidates
        $candidates = $this->loadCandidates($opportunity);

        if ($candidates->isEmpty()) {
            $this->completionService->finish($opportunity->chain_id, 'no_candidates');
            return null;
        }

        // 8. Batch client conflict check
        $conflictingClientIds = $this->findConflictingClientIds($opportunity, $candidates);

        // 9. Filter, expire stale, rank
        $ranked = $this->filterAndRank($opportunity, $candidates, $conflictingClientIds);

        if ($ranked->isEmpty()) {
            $this->completionService->finish($opportunity->chain_id, 'no_eligible_candidates');
            return null;
        }

        // 10. Try to create offer for best candidate
        $offer = $this->tryCreateOffer($opportunity, $ranked);

        if ($offer !== null) {
            return $offer;
        }

        // tryCreateOffer returned null — check race
        $fresh = $opportunity->fresh();

        if ($fresh->pendingOffer !== null) {
            // Another matcher created an offer — don't finish
            return null;
        }

        if ($fresh->status === SlotOpportunityStatus::Filled) {
            // Successful accept/continuation already in progress
            return null;
        }

        if ($fresh->status === SlotOpportunityStatus::Open && $fresh->pendingOffer === null) {
            $this->completionService->finish($fresh->chain_id, 'candidates_exhausted');
        } elseif (in_array($fresh->status, [SlotOpportunityStatus::Expired, SlotOpportunityStatus::Invalidated], true)) {
            $this->completionService->finish($fresh->chain_id, $fresh->status->value);
        }

        return null;
    }

    private function loadCandidates(SlotOpportunity $opportunity)
    {
        return SlotRequest::query()
            ->where('status', SlotRequestStatus::Active)
            ->where('type', SlotRequestType::Earlier)
            ->where('workspace_id', $opportunity->workspace_id)
            ->where('master_id', $opportunity->master_id)
            ->where('master_service_id', $opportunity->master_service_id)
            ->whereDoesntHave('offers', function ($q) use ($opportunity) {
                $q->where('slot_opportunity_id', $opportunity->id);
            })
            ->whereDoesntHave('offers', function ($q) {
                $q->where('status', SlotOfferStatus::Pending);
            })
            ->with(['appointment'])
            ->get();
    }

    private function findConflictingClientIds(SlotOpportunity $opportunity, $candidates)
    {
        $clientIds = $candidates
            ->filter(fn ($r) => $r->client_id !== null)
            ->pluck('client_id')
            ->unique()
            ->values();

        if ($clientIds->isEmpty()) {
            return collect();
        }

        $oppStart = $opportunity->start_time;
        $oppEnd = $oppStart->copy()->addMinutes($opportunity->duration);

        $blockingStatuses = [
            AppointmentStatus::Booked,
            AppointmentStatus::PendingPayment,
            AppointmentStatus::Prepaid,
            AppointmentStatus::Paid,
        ];

        $conflicting = Appointment::whereIn('client_id', $clientIds)
            ->whereIn('status', $blockingStatuses)
            ->where('start_time', '<', $oppEnd)
            ->whereRaw(
                "start_time + (COALESCE(duration, 60) * INTERVAL '1 minute') > ?",
                [$oppStart],
            )
            ->pluck('client_id')
            ->unique();

        return $conflicting;
    }

    private function filterAndRank(SlotOpportunity $opportunity, $candidates, $conflictingClientIds)
    {
        $now = Carbon::now();
        $eligible = collect();

        foreach ($candidates as $request) {
            // Stale request checks → expire and skip
            if ($this->isStaleRequest($request, $now)) {
                $this->requestService->expire($request);
                continue;
            }

            $sourceAppointment = $request->appointment;

            // Source duration check (persisted only)
            $sourceDuration = $sourceAppointment->duration;
            if ($sourceDuration === null || $sourceDuration <= 0) {
                $this->requestService->expire($request);
                continue;
            }

            // Duration exact match
            if ($sourceDuration !== $opportunity->duration) {
                continue;
            }

            // Strict earlier rule: opp end <= source start
            $oppEnd = $opportunity->start_time->copy()->addMinutes($opportunity->duration);
            if ($oppEnd->gt($sourceAppointment->start_time)) {
                continue;
            }

            // Date/time bounds in request timezone
            if (! $this->isWithinRequestBounds($request, $opportunity)) {
                continue;
            }

            // Client conflict
            if ($request->client_id !== null && $conflictingClientIds->contains($request->client_id)) {
                continue;
            }

            // Request expires_at check
            if ($request->expires_at === null || $request->expires_at->lte($now)) {
                $this->requestService->expire($request);
                continue;
            }

            $eligible->push($request);
        }

        // Rank: source start ASC, created_at ASC, id ASC
        return $eligible->sort(function ($a, $b) {
            $aStart = $a->appointment->start_time;
            $bStart = $b->appointment->start_time;

            $cmp = $aStart->timestamp - $bStart->timestamp;
            if ($cmp !== 0) {
                return $cmp;
            }

            $cmp = $a->created_at->timestamp - $b->created_at->timestamp;
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp($a->id, $b->id);
        })->values();
    }

    private function isStaleRequest(SlotRequest $request, Carbon $now): bool
    {
        if ($request->appointment_id === null) {
            return true;
        }

        $appointment = $request->appointment;

        if ($appointment === null) {
            return true;
        }

        if ($appointment->client_id !== $request->client_id) {
            return true;
        }

        if ($appointment->status !== AppointmentStatus::Booked) {
            return true;
        }

        if ($appointment->start_time->lte($now)) {
            return true;
        }

        $appointmentWorkspaceId = $appointment->master?->workspace_id;
        if ($appointmentWorkspaceId !== $request->workspace_id) {
            return true;
        }

        if ($appointment->master_id !== $request->master_id) {
            return true;
        }

        if ($appointment->master_service_id !== $request->master_service_id) {
            return true;
        }

        if ($request->appointment_start_time_snapshot === null) {
            return true;
        }

        if ($appointment->start_time->format('Y-m-d H:i:s') !== $request->appointment_start_time_snapshot->format('Y-m-d H:i:s')) {
            return true;
        }

        return false;
    }

    private function isWithinRequestBounds(SlotRequest $request, SlotOpportunity $opportunity): bool
    {
        $tz = $request->timezone;

        $oppLocalStart = $opportunity->start_time->copy()->timezone($tz);
        $oppLocalEnd = $oppLocalStart->copy()->addMinutes($opportunity->duration);

        // Same local calendar date
        if ($oppLocalStart->format('Y-m-d') !== $oppLocalEnd->format('Y-m-d')) {
            return false;
        }

        $localDate = $oppLocalStart->format('Y-m-d');

        // Date bounds
        if ($localDate < $request->date_from->format('Y-m-d')) {
            return false;
        }
        if ($localDate > $request->date_to->format('Y-m-d')) {
            return false;
        }

        // Time bounds
        $allowedStart = Carbon::parse("{$localDate} {$request->time_from}", $tz);
        $allowedEnd = Carbon::parse("{$localDate} {$request->time_to}", $tz);

        if ($oppLocalStart->lt($allowedStart)) {
            return false;
        }
        if ($oppLocalEnd->gt($allowedEnd)) {
            return false;
        }

        return true;
    }

    private function tryCreateOffer(SlotOpportunity $opportunity, $ranked): ?SlotOffer
    {
        $now = Carbon::now();
        $ttlMinutes = (int) config('booking.autofill.offer_ttl_minutes', 10);

        foreach ($ranked as $request) {
            // Recalculate request validity (may have changed during iteration)
            if ($request->expires_at === null || $request->expires_at->lte($now)) {
                $this->requestService->expire($request);
                continue;
            }

            // Calculate expires_at
            $ttlDeadline = $now->copy()->addMinutes($ttlMinutes);
            $expiresAt = collect([
                $ttlDeadline,
                $opportunity->start_time,
                $request->expires_at,
            ])->min();

            if ($expiresAt <= $now) {
                if ($request->expires_at->lte($now)) {
                    $this->requestService->expire($request);
                }
                continue;
            }

            try {
                return $this->offerService->createPending($request, $opportunity, $expiresAt);
            } catch (\DomainException $e) {
                // Handle known concurrency conflicts
                $message = $e->getMessage();

                // A. Opportunity now has pending offer → another matcher won
                $opportunity = $opportunity->fresh();
                if ($opportunity->status !== SlotOpportunityStatus::Open || $opportunity->pendingOffer !== null) {
                    return null;
                }

                // B. Request now has pending offer → skip to next candidate
                $request = $request->fresh();
                if ($request->pendingOffer !== null) {
                    continue;
                }

                // C. Exact pair already exists → skip to next candidate
                if (str_contains($message, 'already exists')) {
                    continue;
                }

                // D. Unknown domain exception → rethrow
                throw $e;
            }
        }

        return null;
    }
}
