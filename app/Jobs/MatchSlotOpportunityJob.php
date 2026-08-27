<?php

namespace App\Jobs;

use App\Models\SlotOpportunity;
use App\Services\SlotMatcherService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MatchSlotOpportunityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(
        public string $slotOpportunityId,
    ) {}

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(SlotMatcherService $matcher): void
    {
        $opportunity = SlotOpportunity::find($this->slotOpportunityId);

        if ($opportunity === null) {
            Log::info('[AutoFill] Match job: opportunity not found', [
                'opportunity_id' => $this->slotOpportunityId,
            ]);
            return;
        }

        $offer = $matcher->matchOpportunity($opportunity);

        if ($offer === null) {
            return;
        }

        // Schedule delayed expiry for the pending offer
        ExpireSlotOfferJob::dispatch($offer->id)
            ->delay($offer->expires_at);
    }
}
