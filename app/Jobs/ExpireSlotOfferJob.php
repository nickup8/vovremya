<?php

namespace App\Jobs;

use App\Enums\SlotOfferStatus;
use App\Models\SlotOffer;
use App\Services\SlotOfferService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExpireSlotOfferJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(
        public string $slotOfferId,
    ) {}

    public function handle(SlotOfferService $offerService): void
    {
        $offer = SlotOffer::find($this->slotOfferId);

        if ($offer === null) {
            return;
        }

        // Only expire pending offers
        if ($offer->status !== SlotOfferStatus::Pending) {
            return;
        }

        // Safety: don't expire before deadline
        if (now()->lt($offer->expires_at)) {
            // Re-dispatch to arrive after deadline
            $this->release($offer->expires_at);
            return;
        }

        $offerService->expire($offer);

        Log::info('[AutoFill] Offer expired, scheduling rematch', [
            'offer_id' => $offer->id,
            'opportunity_id' => $offer->slot_opportunity_id,
        ]);

        // Rematch the same opportunity
        MatchSlotOpportunityJob::dispatch($offer->slot_opportunity_id);
    }
}
