<?php

namespace App\Console\Commands;

use App\Models\PendingMarketingConsent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupPendingMarketingConsents extends Command
{
    protected $signature = 'pending-marketing:cleanup';

    protected $description = 'Delete expired pending marketing consent rows';

    public function handle(): int
    {
        $deleted = PendingMarketingConsent::query()
            ->where('expires_at', '<', now())
            ->delete();

        if ($deleted > 0) {
            Log::info("Cleaned up {$deleted} expired pending marketing consents");
        }

        $this->info("Cleaned up {$deleted} expired pending marketing consents.");

        return self::SUCCESS;
    }
}
