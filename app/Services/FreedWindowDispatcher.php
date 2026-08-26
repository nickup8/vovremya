<?php

namespace App\Services;

use App\DTOs\AppointmentWindowFreed;
use App\Jobs\CreateSlotOpportunityJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FreedWindowDispatcher
{
    public function dispatchAfterCommit(AppointmentWindowFreed $window): void
    {
        DB::afterCommit(function () use ($window) {
            try {
                CreateSlotOpportunityJob::dispatch($window);
            } catch (\Throwable $e) {
                Log::error('[AutoFill] Failed to dispatch CreateSlotOpportunityJob', [
                    'origin_event_id' => $window->originEventId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
