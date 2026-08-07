<?php

namespace App\Observers;

use App\Models\MasterService;
use Illuminate\Support\Facades\Cache;

class MasterServiceObserver
{
    public function saved(MasterService $masterService): void
    {
        $this->flushAvailabilityCache($masterService->master_id);
    }

    public function deleted(MasterService $masterService): void
    {
        $this->flushAvailabilityCache($masterService->master_id);
    }

    private function flushAvailabilityCache(string $masterId): void
    {
        try {
            Cache::tags(["availability:{$masterId}"])->flush();
        } catch (\Throwable) {
        }
    }
}
