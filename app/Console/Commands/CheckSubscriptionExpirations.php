<?php

namespace App\Console\Commands;

use App\Enums\BillingCycleStatus;
use App\Enums\SubscriptionStatus;
use App\Models\BillingCycle;
use App\Models\NotificationLog;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Notifications\PaymentReminderNotification;
use App\Services\Billing\EntitlementService;
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
        $this->expireLegacySubscriptions();

        return self::SUCCESS;
    }

    /**
     * Mark expired legacy subscriptions (mirror maintenance).
     * This only updates the legacy mirror — it does NOT affect entitlement decisions.
     */
    private function expireLegacySubscriptions(): void
    {
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
    }

    /**
     * Send reminders based on flag-aware authority.
     *
     * Core mode: Core entitlement end date
     * Legacy mode: subscriptions.expires_at
     */
    private function notifyUpcomingExpirations(): void
    {
        if (config('billing.core_entitlement')) {
            $this->notifyUpcomingExpirationsCore();
        } else {
            $this->notifyUpcomingExpirationsLegacy();
        }
    }

    private function notifyUpcomingExpirationsCore(): void
    {
        $entitlementService = app(EntitlementService::class);

        $activeWorkspaceIds = BillingCycle::query()
            ->where('period_end', '>', now())
            ->where('status', BillingCycleStatus::Paid)
            ->distinct()
            ->pluck('workspace_id');

        $workspaces = Workspace::whereIn('id', $activeWorkspaceIds)->get();

        foreach ($workspaces as $workspace) {
            try {
                $plan = $entitlementService->currentPlan($workspace);
                if (! $plan || ! $plan->expiresAt) {
                    continue;
                }

                $this->sendRemindersIfNeeded($workspace, $plan->expiresAt);
            } catch (\Exception $e) {
                Log::error('Notify upcoming expiration failed', [
                    'workspace_id' => $workspace->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function notifyUpcomingExpirationsLegacy(): void
    {
        $subscriptions = Subscription::query()
            ->where('status', SubscriptionStatus::Active)
            ->where('expires_at', '>', now())
            ->get();

        foreach ($subscriptions as $subscription) {
            try {
                $workspace = $subscription->workspace;
                if (! $workspace) {
                    continue;
                }

                $this->sendRemindersIfNeeded($workspace, $subscription->expires_at);
            } catch (\Exception $e) {
                Log::error('Notify upcoming expiration failed', [
                    'subscription_id' => $subscription->id,
                    'workspace_id' => $subscription->workspace_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function sendRemindersIfNeeded(Workspace $workspace, \Carbon\CarbonInterface $expiresAt): void
    {
        $owner = $workspace->owner;
        if (! $owner) {
            return;
        }

        $daysThresholds = [5, 3];

        foreach ($daysThresholds as $days) {
            $targetDate = now()->addDays($days)->startOfDay();

            if (! $expiresAt->startOfDay()->eq($targetDate)) {
                continue;
            }

            $expiresDate = $expiresAt->format('Y-m-d');
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
                            'workspace_id' => $workspace->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            $this->info("Sent subscription_expiring_{$days} to workspace {$workspace->id}");
        }
    }
}
