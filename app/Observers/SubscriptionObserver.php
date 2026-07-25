<?php

namespace App\Observers;

use App\Models\Subscription;
use Illuminate\Support\Facades\Cache;

class SubscriptionObserver
{
    public function saved(Subscription $subscription): void
    {
        $this->forgetTariffCache($subscription->workspace_id);
    }

    public function deleted(Subscription $subscription): void
    {
        $this->forgetTariffCache($subscription->workspace_id);
    }

    private function forgetTariffCache(string $workspaceId): void
    {
        try {
            Cache::forget("tariff:{$workspaceId}");
        } catch (\Throwable) {
            // Тихо пропускаем
        }
    }
}
