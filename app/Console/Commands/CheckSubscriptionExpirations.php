<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\Notification\MasterNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckSubscriptionExpirations extends Command
{
    protected $signature = 'subscriptions:check-expirations';

    protected $description = 'Mark expired subscriptions as expired';

    public function __construct(
        private MasterNotificationService $notificationService,
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

                // TODO: обсудить бизнес-логику — нужно ли откатывать доступ workspace
                // на тариф «Старт» или ограничивать функционал при истечении подписки.

                $workspace = $subscription->workspace;

                if ($workspace) {
                    $users = $workspace->users()
                        ->where(function ($q) {
                            $q->whereNotNull('telegram_id')
                                ->orWhereNotNull('max_id');
                        })
                        ->get();

                    foreach ($users as $user) {
                        $this->notificationService->sendSubscriptionExpired($user);
                    }
                }

                $this->info("Expired subscription {$subscription->id} (workspace: {$subscription->workspace_id}).");
            } catch (\Exception $e) {
                Log::error('Sub expiration failed', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Processed {$expiredSubscriptions->count()} expired subscriptions.");

        return self::SUCCESS;
    }
}
