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

                if ($remaining <= 0) {
                    $type = 'limit_0';
                    $text = __('bot.master.limit_reached');
                } elseif ($remaining <= 5) {
                    $type = 'limit_5';
                    $text = __('bot.master.limit_warning', ['count' => $remaining]);
                } elseif ($remaining <= 10) {
                    $type = 'limit_10';
                    $text = __('bot.master.limit_warning', ['count' => $remaining]);
                } else {
                    continue;
                }

                if (NotificationLog::hasBeenSent($workspace->id, $type, $cycleDate)) {
                    continue;
                }

                $this->notificationService->sendToMaster($owner, $text);
                NotificationLog::markSent($workspace->id, $type, $cycleDate);
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
