<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupPendingSubscriptions extends Command
{
    protected $signature = 'subscriptions:cleanup-pending';

    protected $description = 'Mark abandoned pending subscriptions as failed (older than configured hours)';

    public function handle(): int
    {
        $hours = (int) config('booking.cleanup_pending_subscription_hours', 2);
        $threshold = now()->subHours($hours);

        $count = Subscription::query()
            ->where('status', 'pending')
            ->where('created_at', '<', $threshold)
            ->update(['status' => 'failed']);

        if ($count > 0) {
            Log::info("Cleaned up {$count} abandoned pending subscriptions (older than {$hours}h)");
        }

        $this->info("Cleaned up {$count} pending subscriptions.");

        return self::SUCCESS;
    }
}
