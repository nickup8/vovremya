<?php

namespace App\Observers;

use App\Models\RecurringBlockedTimeSeries;
use Illuminate\Support\Facades\Cache;

class RecurringBlockedTimeSeriesObserver
{
    public function saved(RecurringBlockedTimeSeries $series): void
    {
        $this->flushAvailabilityCache($series->user_id);
    }

    public function deleted(RecurringBlockedTimeSeries $series): void
    {
        $this->flushAvailabilityCache($series->user_id);
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
