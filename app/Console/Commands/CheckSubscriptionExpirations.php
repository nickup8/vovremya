<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\NotificationLog;
use App\Models\Subscription;
use App\Notifications\PaymentReminderNotification;
use App\Services\Notification\MasterNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckSubscriptionExpirations extends Command
{
    protected $signature = 'subscriptions:check-expirations';

    protected $description = 'Mark expired subscriptions as expired and notify owners';

    public function __construct(
        private MasterNotificationService $notificationService,
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

                    // In-app notification (independent dedup)
                    if ($owner->is_blocked) {
                        $this->info("Skipping in-app notification for blocked master {$owner->id}");
                    } else {
                        $inAppPeriodKey = $expiresDate.'_'.$days;
                        if (! NotificationLog::hasBeenSent($workspace->id, 'subscription_expiring_in_app', $inAppPeriodKey)) {
                            try {
                                $owner->notify(new PaymentReminderNotification($days));
                                NotificationLog::markSent($workspace->id, 'subscription_expiring_in_app', $inAppPeriodKey);
                            } catch (\Exception $e) {
                                Log::error('In-app payment reminder failed', [
                                    'subscription_id' => $subscription->id,
                                    'workspace_id' => $workspace->id,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }
                    }

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
