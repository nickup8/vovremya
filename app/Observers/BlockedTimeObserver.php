<?php

namespace App\Observers;

use App\Models\BlockedTime;
use Illuminate\Support\Facades\Cache;

class BlockedTimeObserver
{
    public function saved(BlockedTime $blockedTime): void
    {
        $this->flushAvailabilityCache($blockedTime->user_id);
    }

    public function deleted(BlockedTime $blockedTime): void
    {
        $this->flushAvailabilityCache($blockedTime->user_id);
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
