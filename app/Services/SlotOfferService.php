<?php

namespace App\Services;

use App\Enums\SlotOfferStatus;
use App\Enums\SlotOpportunityStatus;
use App\Enums\SlotRequestStatus;
use App\Models\SlotOffer;
use App\Models\SlotOpportunity;
use App\Models\SlotRequest;
use DateTimeInterface;
use Illuminate\Database\QueryException;

class SlotOfferService
{
    public function createPending(
        SlotRequest $request,
        SlotOpportunity $opportunity,
        DateTimeInterface $expiresAt,
    ): SlotOffer {
        $this->validateCreateGuards($request, $opportunity, $expiresAt);

        // Exact-pair idempotency: check for existing offer
        $existing = SlotOffer::where('slot_request_id', $request->id)
            ->where('slot_opportunity_id', $opportunity->id)
            ->first();

        if ($existing !== null) {
            return $this->handleExistingOffer($existing, $expiresAt);
        }

        try {
            return SlotOffer::create([
                'slot_request_id' => $request->id,
                'slot_opportunity_id' => $opportunity->id,
                'status' => SlotOfferStatus::Pending,
                'expires_at' => $expiresAt,
            ]);
        } catch (QueryException $e) {
            if ($e->getPrevious()?->getCode() !== '23505') {
                throw $e;
            }

            // UNIQUE violation — concurrent insert won
            $existing = SlotOffer::where('slot_request_id', $request->id)
                ->where('slot_opportunity_id', $opportunity->id)
                ->first();

            if ($existing === null) {
                throw $e;
            }

            return $this->handleExistingOffer($existing, $expiresAt);
        }
    }

    public function accept(SlotOffer $offer): SlotOffer
    {
        if ($offer->status === SlotOfferStatus::Accepted) {
            return $offer;
        }

        if ($offer->status !== SlotOfferStatus::Pending) {
            throw new \DomainException(
                "Cannot accept offer with status [{$offer->status->value}]. Only pending offers can be accepted."
            );
        }

        $offer->update([
            'status' => SlotOfferStatus::Accepted,
            'accepted_at' => now(),
        ]);

        return $offer->refresh();
    }

    public function decline(SlotOffer $offer): SlotOffer
    {
        if ($offer->status === SlotOfferStatus::Declined) {
            return $offer;
        }

        if ($offer->status !== SlotOfferStatus::Pending) {
            throw new \DomainException(
                "Cannot decline offer with status [{$offer->status->value}]. Only pending offers can be declined."
            );
        }

        $offer->update([
            'status' => SlotOfferStatus::Declined,
            'declined_at' => now(),
        ]);

        return $offer->refresh();
    }

    public function expire(SlotOffer $offer): SlotOffer
    {
        if ($offer->status === SlotOfferStatus::Expired) {
            return $offer;
        }

        if ($offer->status !== SlotOfferStatus::Pending) {
            throw new \DomainException(
                "Cannot expire offer with status [{$offer->status->value}]. Only pending offers can be expired."
            );
        }

        if (now()->lt($offer->expires_at)) {
            throw new \DomainException('Cannot expire offer before its expires_at timestamp.');
        }

        $offer->update([
            'status' => SlotOfferStatus::Expired,
            'expired_at' => now(),
        ]);

        return $offer->refresh();
    }

    public function invalidate(SlotOffer $offer): SlotOffer
    {
        if ($offer->status === SlotOfferStatus::Invalidated) {
            return $offer;
        }

        if ($offer->status !== SlotOfferStatus::Pending) {
            throw new \DomainException(
                "Cannot invalidate offer with status [{$offer->status->value}]. Only pending offers can be invalidated."
            );
        }

        $offer->update([
            'status' => SlotOfferStatus::Invalidated,
            'invalidated_at' => now(),
        ]);

        return $offer->refresh();
    }

    private function validateCreateGuards(
        SlotRequest $request,
        SlotOpportunity $opportunity,
        DateTimeInterface $expiresAt,
    ): void {
        if ($request->status !== SlotRequestStatus::Active) {
            throw new \DomainException("Request status [{$request->status->value}] is not active.");
        }

        if ($opportunity->status !== SlotOpportunityStatus::Open) {
            throw new \DomainException("Opportunity status [{$opportunity->status->value}] is not open.");
        }

        if ($request->workspace_id !== $opportunity->workspace_id) {
            throw new \DomainException('Request and opportunity belong to different workspaces.');
        }

        if ($request->master_id !== $opportunity->master_id) {
            throw new \DomainException('Request and opportunity belong to different masters.');
        }

        if ($request->master_service_id !== $opportunity->master_service_id) {
            throw new \DomainException('Request and opportunity reference different master services.');
        }

        if ($opportunity->start_time->isPast()) {
            throw new \DomainException('Opportunity start_time is in the past.');
        }

        if ($expiresAt <= now()) {
            throw new \DomainException('expires_at must be in the future.');
        }

        if ($expiresAt > $opportunity->start_time) {
            throw new \DomainException('expires_at must not be after opportunity start_time.');
        }
    }

    private function handleExistingOffer(
        SlotOffer $existing,
        DateTimeInterface $expiresAt,
    ): SlotOffer {
        if ($existing->status === SlotOfferStatus::Pending) {
            if ($existing->expires_at->format('Y-m-d H:i:s') !== $expiresAt->format('Y-m-d H:i:s')) {
                throw new \DomainException(
                    'Exact pair already exists as pending with a different expires_at.'
                );
            }

            return $existing;
        }

        // Declined, Expired, Invalidated, Accepted — cannot reoffer exact pair
        throw new \DomainException(
            "Exact pair already exists with status [{$existing->status->value}]. Cannot reoffer."
        );
    }
}
