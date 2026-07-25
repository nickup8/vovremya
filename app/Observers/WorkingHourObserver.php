<?php

namespace App\Observers;

use App\Models\WorkingHour;
use Illuminate\Support\Facades\Cache;

class WorkingHourObserver
{
    public function saved(WorkingHour $workingHour): void
    {
        $this->flushAvailabilityCache($workingHour->user_id);
    }

    public function deleted(WorkingHour $workingHour): void
    {
        $this->flushAvailabilityCache($workingHour->user_id);
    }

    private function flushAvailabilityCache(string $masterId): void
    {
        try {
            Cache::tags(["availability:{$masterId}"])->flush();
        } catch (\Throwable) {
            // Cache::tags() не поддерживается драйвером — пропускаем
        }
    }
}
