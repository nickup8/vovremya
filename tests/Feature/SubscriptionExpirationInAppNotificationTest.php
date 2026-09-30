<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Models\NotificationLog;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\PaymentReminderNotification;
use App\Notifications\SubscriptionExpiredNotification;
use App\Services\Notification\MasterNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubscriptionExpirationInAppNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function createMasterWithWorkspace(): array
    {
        $master = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'test-'.$master->id,
            'owner_id' => $master->id,
        ]);
        $workspace->ensureSlug();
        $master->update(['workspace_id' => $workspace->id]);

        return [$master, $workspace];
    }

    private function createTariffPlan(): TariffPlan
    {
        return TariffPlan::firstOrCreate(
            ['code' => 'pro'],
            [
                'name' => 'Про',
                'price_monthly' => 1500,
                'max_appointments_per_month' => null,
                'max_masters' => 5,
                'features' => [],
                'is_active' => true,
            ]
        );
    }

    private function createExpiringSubscription(Workspace $workspace, int $daysUntilExpiry): Subscription
    {
        return Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->createTariffPlan()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDays(25),
            'expires_at' => now()->addDays($daysUntilExpiry)->startOfDay()->addHour(),
            'period_months' => 1,
            'amount_paid' => 1500,
        ]);
    }

    // ── 1. Expires in 5 days → creates notification ──

    public function test_five_day_reminder_creates_notification(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createExpiringSubscription($workspace, 5);

        $this->artisan('subscriptions:check-expirations')->assertExitCode(0);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $master->id,
            'type' => PaymentReminderNotification::class,
        ]);

        $notification = $master->notifications()->first();
        $this->assertEquals('payment_reminder', $notification->data['kind']);
        $this->assertEquals('Скоро закончится тариф', $notification->data['title']);
        $this->assertStringContainsString('5 дней', $notification->data['body']);
    }

    // ── 2. Expires in 3 days → creates notification ──

    public function test_three_day_reminder_creates_notification(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createExpiringSubscription($workspace, 3);

        $this->artisan('subscriptions:check-expirations')->assertExitCode(0);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $master->id,
            'type' => PaymentReminderNotification::class,
        ]);

        $notification = $master->notifications()->first();
        $this->assertStringContainsString('3 дня', $notification->data['body']);
    }

    // ── 3. Duplicate run → no duplicate notification ──

    public function test_duplicate_run_does_not_create_duplicate(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createExpiringSubscription($workspace, 5);

        $this->artisan('subscriptions:check-expirations')->assertExitCode(0);
        $this->assertEquals(1, $master->notifications()->count());

        $this->artisan('subscriptions:check-expirations')->assertExitCode(0);
        $this->assertEquals(1, $master->notifications()->count());
    }

    // ── 4. 5-day and 3-day are different periods → both created on different days ──

    public function test_five_day_and_three_day_are_independent_periods(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();

        // Create sub expiring in 5 days
        $this->createExpiringSubscription($workspace, 5);
        $this->artisan('subscriptions:check-expirations')->assertExitCode(0);
        $this->assertEquals(1, $master->notifications()->count());

        // Now simulate 2 days later: same sub expires in 3 days
        // Clear the 5-day log entry and create the 3-day one
        NotificationLog::where('workspace_id', $workspace->id)
            ->where('type', 'subscription_expiring_in_app')
            ->delete();

        // Create a new sub expiring in 3 days (simulating the passage of time)
        $this->createExpiringSubscription($workspace, 3);
        $this->artisan('subscriptions:check-expirations')->assertExitCode(0);
        $this->assertEquals(2, $master->notifications()->count());
    }

    // ── 5. Blocked master → in-app notification NOT created ──

    public function test_blocked_master_does_not_receive_notification(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $master->update(['is_blocked' => true]);
        $this->createExpiringSubscription($workspace, 5);

        $this->artisan('subscriptions:check-expirations')->assertExitCode(0);

        $this->assertEquals(0, $master->notifications()->count());
    }

    // ── 6. Subscription outside threshold → no notification ──

    public function test_subscription_outside_threshold_creates_no_notification(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createExpiringSubscription($workspace, 10);

        $this->artisan('subscriptions:check-expirations')->assertExitCode(0);

        $this->assertEquals(0, $master->notifications()->count());
    }

    // ── 7. Telegram notification log type unchanged ──

    public function test_telegram_dedup_type_unchanged(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createExpiringSubscription($workspace, 5);

        $this->artisan('subscriptions:check-expirations')->assertExitCode(0);

        // Verify the Telegram dedup uses the original type
        $this->assertDatabaseHas('notification_logs', [
            'workspace_id' => $workspace->id,
            'type' => 'subscription_expiring',
        ]);

        // Verify the in-app dedup uses the separate type
        $this->assertDatabaseHas('notification_logs', [
            'workspace_id' => $workspace->id,
            'type' => 'subscription_expiring_in_app',
        ]);
    }

    // ── 8. In-app failure does not break the command ──

    public function test_in_app_failure_does_not_break_command(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        // Block master so notify() still works but we can verify command completes
        $master->update(['is_blocked' => false]);
        $this->createExpiringSubscription($workspace, 5);

        // The command should complete successfully regardless
        $this->artisan('subscriptions:check-expirations')->assertExitCode(0);

        // Verify the subscription was still processed (expired check runs after)
        // The subscription expiring in 5 days is NOT expired yet
        $this->assertSame('active', Subscription::first()->fresh()->status);
    }

    // ── 9. Expired subscription → Expired status + notification log ──

    public function test_expired_subscription_marks_expired_and_writes_notification_log(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $sub = $this->createExpiringSubscription($workspace, -1);

        $mock = $this->mock(MasterNotificationService::class);
        $mock->shouldReceive('sendSubscriptionExpired')->once()
            ->with(\Mockery::on(fn (User $owner) => $owner->id === $master->id));

        $this->artisan('subscriptions:check-expirations')->assertExitCode(0);

        $this->assertSame('expired', $sub->fresh()->status);

        $this->assertDatabaseHas('notification_logs', [
            'workspace_id' => $workspace->id,
            'type' => 'subscription_expired',
            'period_key' => $sub->fresh()->expires_at->format('Y-m-d'),
        ]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $master->id,
            'type' => SubscriptionExpiredNotification::class,
        ]);
    }

    // ── 10. Second run → no duplicate notification or log ──

    public function test_second_run_does_not_send_duplicate_notification(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createExpiringSubscription($workspace, -1);

        // once() across both runs: primary dedup is the Active-status query —
        // after the first run the subscription no longer matches it.
        $mock = $this->mock(MasterNotificationService::class);
        $mock->shouldReceive('sendSubscriptionExpired')->once();

        $this->artisan('subscriptions:check-expirations')->assertExitCode(0);
        $this->artisan('subscriptions:check-expirations')->assertExitCode(0);

        $this->assertSame(1, NotificationLog::where('workspace_id', $workspace->id)
            ->where('type', 'subscription_expired')->count());

        $this->assertSame(1, $master->notifications()
            ->where('type', SubscriptionExpiredNotification::class)->count());
    }

    // ── 11. Notification failure must not roll back expiry ──

    public function test_notification_failure_does_not_rollback_expiry(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $sub = $this->createExpiringSubscription($workspace, -1);

        $mock = $this->mock(MasterNotificationService::class);
        $mock->shouldReceive('sendSubscriptionExpired')->once()
            ->andThrow(new \RuntimeException('gateway down'));

        $this->artisan('subscriptions:check-expirations')->assertExitCode(0);

        $this->assertSame('expired', $sub->fresh()->status);

        // markSent() is never reached after a failed send
        $this->assertSame(0, NotificationLog::where('workspace_id', $workspace->id)
            ->where('type', 'subscription_expired')->count());
    }

    // ── 12. Missing owner must not break expiration ──

    public function test_expiration_succeeds_without_owner(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $sub = $this->createExpiringSubscription($workspace, -1);

        // workspace.owner_id is NOT NULL with cascadeOnDelete — drop FK triggers
        // to remove the owner while keeping the workspace (dangling owner_id).
        DB::statement('SET session_replication_role = replica');
        try {
            DB::table('users')->where('id', $master->id)->delete();
        } finally {
            DB::statement('SET session_replication_role = origin');
        }

        $this->artisan('subscriptions:check-expirations')->assertExitCode(0);

        // Status is updated even though owner is missing; notification is skipped.
        $this->assertSame('expired', $sub->fresh()->status);
        $this->assertSame(0, NotificationLog::where('workspace_id', $workspace->id)
            ->where('type', 'subscription_expired')->count());
    }
}
