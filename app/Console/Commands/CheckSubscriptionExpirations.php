<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Notification\MasterNotificationService;
use Illuminate\Console\Command;

class CheckSubscriptionExpirations extends Command
{
    protected $signature = 'subscriptions:check-expirations';

    protected $description = 'Downgrade expired subscriptions to free tariff';

    public function __construct(
        private MasterNotificationService $notificationService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $expiredUsers = User::where('tariff', '!=', 'free')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expiredUsers as $user) {
            $user->update([
                'tariff' => 'free',
                'expires_at' => null,
            ]);

            if ($user->telegram_id || $user->max_id) {
                $this->notificationService->sendSubscriptionExpired($user);
            }

            $this->info("Downgraded user {$user->id} ({$user->email}) to free tariff.");
        }

        $this->info("Processed {$expiredUsers->count()} expired subscriptions.");

        return self::SUCCESS;
    }
}
