<?php

namespace App\Observers;

use App\Models\RecurringBlockedTimeException;
use Illuminate\Support\Facades\Cache;

class RecurringBlockedTimeExceptionObserver
{
    public function saved(RecurringBlockedTimeException $exception): void
    {
        $this->flushCache($exception);
    }

    public function deleted(RecurringBlockedTimeException $exception): void
    {
        $this->flushCache($exception);
    }

    private function flushCache(RecurringBlockedTimeException $exception): void
    {
        $series = $exception->series;
        if (! $series) {
            return;
        }

        try {
            Cache::tags(["availability:{$series->user_id}"])->flush();
        } catch (\Throwable) {
            // Cache::tags() не поддерживается драйвером
        }
    }
}
