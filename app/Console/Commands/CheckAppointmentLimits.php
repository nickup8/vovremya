<?php

namespace App\Console\Commands;

use App\Models\NotificationLog;
use App\Models\Workspace;
use App\Services\Billing\TariffLimitService;
use App\Services\Notification\MasterNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckAppointmentLimits extends Command
{
    protected $signature = 'subscriptions:check-limits';

    protected $description = 'Notify workspace owner about remaining appointment limits (10, 5, 0)';

    public function __construct(
        private TariffLimitService $tariffLimitService,
        private MasterNotificationService $notificationService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $thresholds = [0, 5, 10];

        $workspaces = Workspace::whereHas('subscriptions', function ($q) {
            $q->where('status', 'active')->where('expires_at', '>', now());
        })->get();

        foreach ($workspaces as $workspace) {
            try {
                $subscription = $workspace->activeSubscription();
                if (! $subscription || ! $subscription->tariffPlan) {
                    continue;
                }

                $limit = $this->tariffLimitService->getMonthlyLimit($workspace, $subscription);
                if ($limit === PHP_INT_MAX) {
                    continue;
                }

                $remaining = $this->tariffLimitService->getRemainingCount($workspace, $subscription);
                $cycleStart = $this->tariffLimitService->getCycleStart($workspace);
                $cycleDate = $cycleStart->format('Y-m-d');

                $owner = $workspace->owner;
                if (! $owner) {
                    continue;
                }

                foreach ($thresholds as $threshold) {
                    if ($remaining <= $threshold) {
                        $periodKey = $cycleDate.'_'.$threshold;

                        if (NotificationLog::hasBeenSent($workspace->id, 'limit_warning', $periodKey)) {
                            continue;
                        }

                        $text = match ($threshold) {
                            0 => __('bot.master.limit_reached'),
                            5, 10 => __('bot.master.limit_warning', ['count' => $threshold]),
                            default => null,
                        };

                        if ($text) {
                            $this->notificationService->sendToMaster($owner, $text);
                            NotificationLog::markSent($workspace->id, 'limit_warning', $periodKey);
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::error('CheckAppointmentLimits failed', [
                    'workspace_id' => $workspace->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }
}
