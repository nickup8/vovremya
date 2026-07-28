<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\NotificationLog;
use App\Models\Subscription;
use App\Services\Notification\MasterNotificationService;
use App\Services\WorkspaceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckSubscriptionExpirations extends Command
{
    protected $signature = 'subscriptions:check-expirations';

    protected $description = 'Mark expired subscriptions as expired and dissolve studio workspace';

    public function __construct(
        private MasterNotificationService $notificationService,
        private WorkspaceService $workspaceService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->notifyUpcomingExpirations();

        $expiredSubscriptions = Subscription::where('status', SubscriptionStatus::Active)
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expiredSubscriptions as $subscription) {
            try {
                $subscription->update(['status' => SubscriptionStatus::Expired]);

                $workspace = $subscription->workspace;

                if ($workspace) {
                    $this->workspaceService->dissolveStudio($workspace, $this->notificationService);
                }

                $this->info("Expired subscription {$subscription->id} (workspace: {$subscription->workspace_id}).");
            } catch (\Exception $e) {
                Log::error('Sub expiration failed', [
                    'subscription_id' => $subscription->id,
                    'workspace_id' => $subscription->workspace_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Processed {$expiredSubscriptions->count()} expired subscriptions.");

        return self::SUCCESS;
    }

    private function notifyUpcomingExpirations(): void
    {
        $daysThresholds = [5, 3];

        foreach ($daysThresholds as $days) {
            $targetDate = now()->addDays($days)->startOfDay();

            $subscriptions = Subscription::where('status', SubscriptionStatus::Active)
                ->whereDate('expires_at', $targetDate->toDateString())
                ->get();

            foreach ($subscriptions as $subscription) {
                try {
                    $workspace = $subscription->workspace;
                    if (! $workspace) {
                        continue;
                    }

                    $owner = $workspace->owner;
                    if (! $owner) {
                        continue;
                    }

                    $expiresDate = $subscription->expires_at->format('Y-m-d');
                    $periodKey = $expiresDate.'_'.$days;

                    if (NotificationLog::hasBeenSent($workspace->id, 'subscription_expiring', $periodKey)) {
                        continue;
                    }

                    $dayText = $days === 3 ? '3 дня' : '5 дней';
                    $text = __('bot.master.subscription_expiring', ['days' => $dayText]);

                    $this->notificationService->sendToMaster($owner, $text);
                    NotificationLog::markSent($workspace->id, 'subscription_expiring', $periodKey);

                    $this->info("Sent subscription_expiring_{$days} to workspace {$workspace->id}");
                } catch (\Exception $e) {
                    Log::error('Notify upcoming expiration failed', [
                        'subscription_id' => $subscription->id,
                        'workspace_id' => $subscription->workspace_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
