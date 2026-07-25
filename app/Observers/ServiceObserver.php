<?php

namespace App\Observers;

use App\Models\Service;
use Illuminate\Support\Facades\Cache;

class ServiceObserver
{
    public function saved(Service $service): void
    {
        $this->flushAvailabilityCache($service->user_id);
    }

    public function deleted(Service $service): void
    {
        $this->flushAvailabilityCache($service->user_id);
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
