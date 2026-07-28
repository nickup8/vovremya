<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
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
}
