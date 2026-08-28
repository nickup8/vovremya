<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\SlotInvalidationReason;
use App\Enums\SlotOfferStatus;
use App\Enums\SlotOpportunityStatus;
use App\Enums\SlotRequestStatus;
use App\Enums\SlotRequestType;
use App\Jobs\MatchSlotOpportunityJob;
use App\Models\SlotOffer;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SlotOfferAcceptanceService
{
    public function __construct(
        private BookingService $bookingService,
        private SlotOfferService $offerService,
        private SlotRequestService $requestService,
        private SlotOpportunityService $opportunityService,
        private AvailabilityService $availabilityService,
    ) {}

    public function acceptEarlier(SlotOffer $offer): array
    {
        return DB::transaction(function () use ($offer) {
            // Lock and revalidate
            $offer = SlotOffer::where('id', $offer->id)->lockForUpdate()->first();

            if ($offer === null) {
                return ['success' => false, 'error' => 'offer_not_found'];
            }

            // Already accepted — idempotent
            if ($offer->status === SlotOfferStatus::Accepted) {
                return ['success' => true, 'offer' => $offer, 'idempotent' => true];
            }

            if ($offer->status !== SlotOfferStatus::Pending) {
                return ['success' => false, 'error' => 'not_pending', 'status' => $offer->status->value];
            }

            if (now()->gte($offer->expires_at)) {
                $this->offerService->expire($offer);
                return ['success' => false, 'error' => 'expired'];
            }

            $request = $offer->request;
            $opportunity = $offer->opportunity;

            if ($request === null || $opportunity === null) {
                return ['success' => false, 'error' => 'missing_relations'];
            }

            // Lock request and opportunity
            $request = \App\Models\SlotRequest::where('id', $request->id)->lockForUpdate()->first();
            $opportunity = \App\Models\SlotOpportunity::where('id', $opportunity->id)->lockForUpdate()->first();

            // Request validation
            if ($request->status !== SlotRequestStatus::Active) {
                return $this->invalidateAndReturn($offer, $request, $opportunity, 'request_not_active', SlotInvalidationReason::StaleRequest);
            }

            if ($request->type !== SlotRequestType::Earlier) {
                return ['success' => false, 'error' => 'not_earlier_type'];
            }

            // Opportunity validation
            if ($opportunity->status !== SlotOpportunityStatus::Open) {
                return ['success' => false, 'error' => 'opportunity_not_open'];
            }

            if ($opportunity->start_time->lte(Carbon::now())) {
                return ['success' => false, 'error' => 'opportunity_past'];
            }

            // Source appointment validation
            $appointment = $request->appointment;

            if ($appointment === null) {
                return $this->invalidateAndExpire($offer, $request, $opportunity, 'source_appointment_missing', SlotInvalidationReason::StaleRequest);
            }

            $appointment = \App\Models\Appointment::where('id', $appointment->id)->lockForUpdate()->first();

            if ($appointment === null) {
                return $this->invalidateAndExpire($offer, $request, $opportunity, 'source_appointment_missing', SlotInvalidationReason::StaleRequest);
            }

            if ($appointment->status !== AppointmentStatus::Booked) {
                return $this->invalidateAndExpire($offer, $request, $opportunity, 'source_not_booked', SlotInvalidationReason::SourceChanged);
            }

            if ($appointment->client_id !== $request->client_id) {
                return $this->invalidateAndExpire($offer, $request, $opportunity, 'client_mismatch', SlotInvalidationReason::StaleRequest);
            }

            $appointmentWorkspaceId = $appointment->master?->workspace_id;
            if ($appointmentWorkspaceId !== $request->workspace_id) {
                return $this->invalidateAndExpire($offer, $request, $opportunity, 'workspace_mismatch', SlotInvalidationReason::StaleRequest);
            }

            if ($appointment->master_id !== $request->master_id) {
                return $this->invalidateAndExpire($offer, $request, $opportunity, 'master_mismatch', SlotInvalidationReason::StaleRequest);
            }

            if ($appointment->master_service_id !== $request->master_service_id) {
                return $this->invalidateAndExpire($offer, $request, $opportunity, 'master_service_mismatch', SlotInvalidationReason::StaleRequest);
            }

            if ($request->appointment_start_time_snapshot === null) {
                return $this->invalidateAndExpire($offer, $request, $opportunity, 'snapshot_missing', SlotInvalidationReason::StaleRequest);
            }

            if ($appointment->start_time->format('Y-m-d H:i:s') !== $request->appointment_start_time_snapshot->format('Y-m-d H:i:s')) {
                return $this->invalidateAndExpire($offer, $request, $opportunity, 'snapshot_drift', SlotInvalidationReason::SourceChanged);
            }

            // Duration validation (persisted only)
            $sourceDuration = $appointment->duration;

            if ($sourceDuration === null || $sourceDuration <= 0) {
                return $this->invalidateAndExpire($offer, $request, $opportunity, 'invalid_duration', SlotInvalidationReason::StaleRequest);
            }

            if ($sourceDuration !== $opportunity->duration) {
                return $this->invalidateAndExpire($offer, $request, $opportunity, 'duration_mismatch', SlotInvalidationReason::StaleRequest);
            }

            // Strict earlier rule
            $oppEnd = $opportunity->start_time->copy()->addMinutes($opportunity->duration);
            if ($oppEnd->gt($appointment->start_time)) {
                return $this->invalidateAndExpire($offer, $request, $opportunity, 'not_earlier', SlotInvalidationReason::StaleRequest);
            }

            // MasterService active
            $masterService = $appointment->masterService;
            if ($masterService === null || ! $masterService->is_active) {
                return $this->invalidateAndExpire($offer, $request, $opportunity, 'service_inactive', SlotInvalidationReason::EligibilityChanged);
            }

            // Client conflict check
            $master = $appointment->master;
            $oppStart = $opportunity->start_time;
            $oppEndCarbon = $oppStart->copy()->addMinutes($opportunity->duration);

            $blockingStatuses = [
                AppointmentStatus::Booked,
                AppointmentStatus::PendingPayment,
                AppointmentStatus::Prepaid,
                AppointmentStatus::Paid,
            ];

            $conflict = \App\Models\Appointment::where('client_id', $request->client_id)
                ->whereIn('status', $blockingStatuses)
                ->where('id', '!=', $appointment->id)
                ->where('start_time', '<', $oppEndCarbon)
                ->whereRaw(
                    "start_time + (COALESCE(duration, 60) * INTERVAL '1 minute') > ?",
                    [$oppStart],
                )
                ->exists();

            if ($conflict) {
                return ['success' => false, 'error' => 'client_conflict'];
            }

            // Availability check
            if (! $this->availabilityService->isSlotAvailable(
                $master,
                \Illuminate\Support\Carbon::instance($oppStart),
                $opportunity->duration,
                $appointment->id,
            )) {
                $this->offerService->invalidate($offer, SlotInvalidationReason::SlotUnavailable);
                $this->opportunityService->invalidate($opportunity, SlotInvalidationReason::SlotUnavailable);
                return ['success' => false, 'error' => 'slot_unavailable'];
            }

            // Timezone-safe conversion for BookingService
            $tz = $master->getTimezone();
            $localStart = $oppStart->copy()->timezone($tz);
            $newDate = $localStart->format('Y-m-d');
            $newTime = $localStart->format('H:i');

            // Execute reschedule
            $result = $this->bookingService->rescheduleAppointment(
                $appointment,
                $newDate,
                $newTime,
                ignoreWarnings: true,
                confirmOutsideHours: true,
                newMasterId: null,
                autofillChainId: $opportunity->chain_id,
            );

            if (! ($result['success'] ?? false)) {
                // slot_taken or other failure
                if (($result['error'] ?? '') === 'slot_taken') {
                    $this->offerService->invalidate($offer, SlotInvalidationReason::SlotTaken);
                    $this->opportunityService->invalidate($opportunity, SlotInvalidationReason::SlotTaken);
                    return ['success' => false, 'error' => 'slot_taken'];
                }

                return ['success' => false, 'error' => $result['error'] ?? 'unknown', 'message' => $result['message'] ?? ''];
            }

            // Success: update lifecycle
            $this->offerService->accept($offer);
            $this->requestService->fulfill($request);
            $this->opportunityService->fill($opportunity, $appointment->id);

            return [
                'success' => true,
                'offer' => $offer->fresh(),
                'appointment' => $result['appointment'],
            ];
        });
    }

    private function invalidateAndReturn(
        SlotOffer $offer,
        ?\App\Models\SlotRequest $request,
        ?\App\Models\SlotOpportunity $opportunity,
        string $error,
        SlotInvalidationReason $reason,
    ): array {
        $this->offerService->invalidate($offer, $reason);

        if ($request !== null && $request->status === SlotRequestStatus::Active) {
            $this->requestService->expire($request);
        }

        // Opportunity stays Open — rematch will handle it
        if ($opportunity !== null) {
            $opportunityId = $opportunity->id;
            DB::afterCommit(fn () => MatchSlotOpportunityJob::dispatch($opportunityId));
        }

        return ['success' => false, 'error' => $error];
    }

    private function invalidateAndExpire(
        SlotOffer $offer,
        ?\App\Models\SlotRequest $request,
        ?\App\Models\SlotOpportunity $opportunity,
        string $error,
        SlotInvalidationReason $reason,
    ): array {
        $this->offerService->invalidate($offer, $reason);

        if ($request !== null && $request->status === SlotRequestStatus::Active) {
            $this->requestService->expire($request);
        }

        // Opportunity stays Open
        if ($opportunity !== null) {
            $opportunityId = $opportunity->id;
            DB::afterCommit(fn () => MatchSlotOpportunityJob::dispatch($opportunityId));
        }

        return ['success' => false, 'error' => $error];
    }
}
