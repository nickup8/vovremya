<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentEnvironment;
use App\Enums\PaymentReceiptStatus;
use App\Enums\UserRole;
use App\Jobs\SendReceiptUrlNotificationJob;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\PaymentAttempt;
use App\Models\PaymentReceipt;
use App\Models\ReceiptChannelDelivery;
use App\Models\ReceiptDeliveryEvent;
use App\Models\SuperAdminAuditLog;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\SystemNotification;
use App\Services\MaxApiClient;
use App\Services\Payment\PaymentReceiptService;
use App\Services\VkApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\ConnectorInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceiptNotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const URL_A = 'https://lknpd.nalog.ru/api/v1/receipt/5248061993/aaa111/print';

    private const URL_B = 'https://lknpd.nalog.ru/api/v1/receipt/5248061993/bbb222/print';

    private TariffPlan $proPlan;

    private Workspace $workspace;

    private User $owner;

    private User $admin;

    private int $cycleSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.receipts.seller_inn' => '5248061993',
            'billing.legacy_mock_webhook_secret' => 'test_secret_123',
        ]);

        $this->proPlan = TariffPlan::create([
            'code' => 'pro', 'name' => 'Профи', 'price_monthly' => 490,
            'max_appointments_per_month' => null, 'max_masters' => 1,
            'features' => ['unlimited_appointments'], 'is_active' => true,
        ]);

        $this->owner = User::factory()->master()->create(['max_id' => null]);
        $this->workspace = Workspace::create([
            'name' => 'ws-'.$this->owner->id,
            'owner_id' => $this->owner->id,
        ]);
        $this->workspace->ensureSlug();
        $this->owner->update(['workspace_id' => $this->workspace->id]);

        $this->admin = User::factory()->master()->create(['is_super_admin' => true]);
    }

    // ── In-app notification action ──

    public function test_first_attach_notifies_owner_with_action_link(): void
    {
        Queue::fake();
        $receipt = $this->awaitingReceipt();

        app(PaymentReceiptService::class)->attachUrl($receipt, self::URL_A, $this->admin);

        $notification = $this->owner->notifications()->latest('created_at')->firstOrFail();
        $this->assertSame('Чек об оплате подписки', $notification->data['title']);
        $this->assertSame('Чек готов. Откройте его по ссылке ниже', $notification->data['body']);
        $this->assertSame(self::URL_A, $notification->data['action']['url']);
        $this->assertSame('Открыть чек', $notification->data['action']['label']);
        $this->assertSame($receipt->id, $notification->data['receipt_id']);
    }

    public function test_url_change_notifies_owner_with_new_action_link(): void
    {
        Queue::fake();
        $receipt = $this->awaitingReceipt();
        $service = app(PaymentReceiptService::class);

        $service->attachUrl($receipt, self::URL_A, $this->admin);
        $service->attachUrl($receipt, self::URL_B, $this->admin);

        $notifications = $this->owner->notifications()->get();
        $this->assertCount(2, $notifications);

        $update = $notifications->first(fn ($n) => ($n->data['title'] ?? null) === 'Ссылка на чек обновлена');
        $this->assertNotNull($update);
        $this->assertSame('Откройте чек по обновлённой ссылке', $update->data['body']);
        $this->assertSame(self::URL_B, $update->data['action']['url']);
        $this->assertSame('Открыть чек', $update->data['action']['label']);

        $first = $notifications->first(fn ($n) => ($n->data['title'] ?? null) === 'Чек об оплате подписки');
        $this->assertNotNull($first);
        $this->assertSame(self::URL_A, $first->data['action']['url']);
    }

    public function test_resaving_same_url_adds_no_notification(): void
    {
        Queue::fake();
        $receipt = $this->awaitingReceipt();
        $service = app(PaymentReceiptService::class);

        $service->attachUrl($receipt, self::URL_A, $this->admin);
        $service->attachUrl($receipt, self::URL_A, $this->admin);

        $this->assertSame(1, $this->owner->notifications()->count());
    }

    public function test_only_workspace_owner_is_notified(): void
    {
        Queue::fake();
        $member = User::factory()->master()->create([
            'role' => UserRole::Master,
            'workspace_id' => $this->workspace->id,
        ]);

        $receipt = $this->awaitingReceipt();
        app(PaymentReceiptService::class)->attachUrl($receipt, self::URL_A, $this->admin);

        $this->assertSame(1, $this->owner->notifications()->count());
        $this->assertSame(0, $member->notifications()->count());
        $this->assertSame(0, $this->admin->notifications()->count());
    }

    // ── Inertia middleware props ──

    public function test_action_fields_reach_inertia_notification_props(): void
    {
        $this->withNotification($this->owner, [
            'kind' => 'system',
            'title' => 'Чек об оплате подписки',
            'body' => 'Чек готов. Откройте его по ссылке ниже',
            'action' => ['url' => self::URL_A, 'label' => 'Открыть чек'],
        ]);

        $this->actingAs($this->owner)
            ->get(route('admin.billing'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('notifications.items.0.title', 'Чек об оплате подписки')
                ->where('notifications.items.0.action_url', self::URL_A)
                ->where('notifications.items.0.action_label', 'Открыть чек'));
    }

    public function test_notification_without_action_keeps_working_with_null_action(): void
    {
        $this->withNotification($this->owner, [
            'kind' => 'system',
            'title' => 'Обычное',
            'body' => 'Просто текст',
        ]);

        $this->actingAs($this->owner)
            ->get(route('admin.billing'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('notifications.items.0.title', 'Обычное')
                ->where('notifications.items.0.body', 'Просто текст')
                ->whereNull('notifications.items.0.action_url')
                ->whereNull('notifications.items.0.action_label'));
    }

    public function test_unsafe_action_url_is_not_shared_to_frontend(): void
    {
        // Hand-crafted data (as if corrupted): the middleware must drop the
        // action while the notification itself stays visible.
        $this->withNotification($this->owner, [
            'kind' => 'system',
            'title' => 'Опасное',
            'body' => 'Текст',
            'action' => ['url' => 'javascript:alert(1)', 'label' => 'Открыть чек'],
        ]);

        $this->actingAs($this->owner)
            ->get(route('admin.billing'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('notifications.items.0.title', 'Опасное')
                ->whereNull('notifications.items.0.action_url'));
    }

    public function test_reading_notification_keeps_action_link(): void
    {
        $notification = $this->withNotification($this->owner, [
            'kind' => 'system',
            'title' => 'Чек об оплате подписки',
            'body' => 'Чек готов',
            'action' => ['url' => self::URL_A, 'label' => 'Открыть чек'],
        ]);

        $this->actingAs($this->owner)
            ->post(route('admin.notifications.read', $notification->id))
            ->assertRedirect();

        $fresh = $this->owner->notifications()->findOrFail($notification->id);
        $this->assertNotNull($fresh->read_at);
        $this->assertSame(self::URL_A, $fresh->data['action']['url']);
    }

    // ── Job dispatch (outbox intent + enqueue) ──

    public function test_attach_dispatches_messenger_job_after_commit(): void
    {
        Queue::fake();
        $receipt = $this->awaitingReceipt();

        app(PaymentReceiptService::class)->attachUrl($receipt, self::URL_A, $this->admin);

        Queue::assertPushed(SendReceiptUrlNotificationJob::class, 1);

        $event = ReceiptDeliveryEvent::query()->sole();
        $this->assertSame($receipt->getKey(), $event->payment_receipt_id);
        $this->assertSame(1, $event->url_version);
        $this->assertSame(self::URL_A, $event->receipt_url);
        $this->assertNotNull($event->dispatched_at, 'after a successful enqueue the intent is marked dispatched');
        Queue::assertPushed(
            SendReceiptUrlNotificationJob::class,
            fn (SendReceiptUrlNotificationJob $job) => $job->eventId === $event->getKey(),
        );
    }

    public function test_same_url_save_dispatches_no_new_job(): void
    {
        Queue::fake();
        $receipt = $this->awaitingReceipt();
        $service = app(PaymentReceiptService::class);

        $service->attachUrl($receipt, self::URL_A, $this->admin);
        $service->attachUrl($receipt, self::URL_A, $this->admin);

        Queue::assertPushed(SendReceiptUrlNotificationJob::class, 1);
        $this->assertSame(1, ReceiptDeliveryEvent::count(), 're-saving the same URL is a no-op: no new event');
    }

    public function test_url_change_dispatches_another_job(): void
    {
        Queue::fake();
        $receipt = $this->awaitingReceipt();
        $service = app(PaymentReceiptService::class);

        $service->attachUrl($receipt, self::URL_A, $this->admin);
        $service->attachUrl($receipt, self::URL_B, $this->admin);

        Queue::assertPushed(SendReceiptUrlNotificationJob::class, 2);
        $this->assertSame([1, 2], ReceiptDeliveryEvent::query()->orderBy('url_version')->pluck('url_version')->all());
    }

    public function test_rollback_dispatches_nothing_and_sends_no_notification(): void
    {
        Queue::fake();
        $receipt = $this->awaitingReceipt();

        try {
            DB::transaction(function () use ($receipt) {
                app(PaymentReceiptService::class)->attachUrl($receipt, self::URL_A, $this->admin);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        Queue::assertNothingPushed();
        // The database notification row AND the delivery intent were written
        // inside the transaction and rolled back with it — rollback removes
        // the very reason to ever send something.
        $this->assertSame(0, $this->owner->notifications()->count());
        $this->assertSame(0, ReceiptDeliveryEvent::count());
        $this->assertSame(0, ReceiptChannelDelivery::count());
        $this->assertSame(PaymentReceiptStatus::AwaitingManual, $receipt->refresh()->status);
        $this->assertSame(0, SuperAdminAuditLog::count());
    }

    /**
     * Review #2 (сбой постановки): when the queue push fails after the
     * change committed, the durable outbox intent must survive and a later
     * recovery run must enqueue it — nothing is silently lost, and the
     * committed change is not rolled back by an unrelated queue outage.
     */
    public function test_enqueue_failure_keeps_intent_recoverable(): void
    {
        $this->fakeMessengerConfig();
        Http::preventStrayRequests();

        // Queue outage: the connection itself fails while being resolved.
        // A PHP-level failure keeps the surrounding test transaction intact,
        // unlike a poisoned SQL statement.
        config([
            'queue.default' => 'broken',
            'queue.connections.broken' => ['driver' => 'broken'],
        ]);
        Queue::extend('broken', fn () => new class implements ConnectorInterface
        {
            public function connect(array $config)
            {
                throw new \RuntimeException('queue connection unavailable');
            }
        });

        $receipt = $this->awaitingReceipt();

        app(PaymentReceiptService::class)->attachUrl($receipt, self::URL_A, $this->admin);

        // The change itself committed: URL, notification, audit, intent.
        $this->assertSame(PaymentReceiptStatus::Attached, $receipt->refresh()->status);
        $this->assertSame(self::URL_A, $receipt->receipt_url);
        $this->assertSame(1, $this->owner->notifications()->count());
        $this->assertSame(1, SuperAdminAuditLog::count(), 'the audit row commits with the change');

        $event = ReceiptDeliveryEvent::query()->sole();
        $this->assertNull($event->dispatched_at, 'failed enqueue must leave a recoverable intent');
        $this->assertSame(0, DB::table('jobs')->count());

        // Queue healthy again → recovery enqueues exactly the lost intent.
        config(['queue.default' => 'database']);

        $this->artisan('receipts:recover-delivery-events --min-age=0')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertStringContainsString(
            $event->getKey(),
            (string) DB::table('jobs')->value('payload'),
        );
        $this->assertNotNull($event->refresh()->dispatched_at);
    }

    public function test_recovery_never_touches_already_enqueued_events(): void
    {
        Queue::fake();
        $receipt = $this->awaitingReceipt();
        app(PaymentReceiptService::class)->attachUrl($receipt, self::URL_A, $this->admin);

        Queue::assertPushed(SendReceiptUrlNotificationJob::class, 1);

        $this->artisan('receipts:recover-delivery-events --min-age=0')
            ->assertExitCode(0);

        Queue::assertPushed(SendReceiptUrlNotificationJob::class, 1);
    }

    // ── Messenger job: channels, retries, delivery accounting ──

    public function test_job_sends_to_enabled_channels_with_full_url(): void
    {
        [$maxUrl, $vkUrl] = $this->fakeMessengerConfig();
        Http::fake([
            'max.test/*' => Http::response(['message' => ['body' => ['mid' => 'mid-1']]]),
            'api.vk.com/*' => Http::response(['response' => 42]),
        ]);

        $receipt = $this->attachedReceipt();
        $this->owner->update([
            'max_id' => 'max-user-1', 'max_notifications' => true,
            'vk_id' => '12345', 'vk_notifications' => true,
        ]);

        $this->runJob($receipt);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), $maxUrl)
            && str_contains((string) $request['text'], self::URL_A));
        Http::assertSent(fn ($request) => str_contains($request->url(), $vkUrl)
            && str_contains((string) $request['message'], self::URL_A));

        $this->assertSame(2, ReceiptChannelDelivery::where('payment_receipt_id', $receipt->id)->count());
    }

    public function test_job_retry_does_not_resend_already_delivered_channel(): void
    {
        $this->fakeMessengerConfig();
        Http::fake([
            'max.test/*' => Http::response(['message' => ['body' => ['mid' => 'mid-1']]]),
            'api.vk.com/*' => Http::response(['response' => 42]),
        ]);

        $receipt = $this->attachedReceipt();
        $this->owner->update([
            'max_id' => 'max-user-1', 'max_notifications' => true,
            'vk_id' => '12345', 'vk_notifications' => true,
        ]);

        $this->runJob($receipt);
        $this->runJob($receipt);

        Http::assertSentCount(2); // one per channel total, not four
        $this->assertSame(2, ReceiptChannelDelivery::count());
    }

    public function test_channel_failure_keeps_other_channel_and_fails_job(): void
    {
        $this->fakeMessengerConfig();

        $maxUp = false;
        Http::fake(function ($request) use (&$maxUp) {
            if (str_contains($request->url(), 'max.test')) {
                return $maxUp
                    ? Http::response(['message' => ['body' => ['mid' => 'mid-2']]])
                    : Http::response(['error' => 'boom'], 500);
            }

            if (str_contains($request->url(), 'api.vk.com')) {
                return Http::response(['response' => 42]);
            }

            return Http::response([], 404);
        });

        $receipt = $this->attachedReceipt();
        $this->owner->update([
            'max_id' => 'max-user-1', 'max_notifications' => true,
            'vk_id' => '12345', 'vk_notifications' => true,
        ]);

        $failed = false;
        try {
            $this->runJob($receipt);
        } catch (\RuntimeException) {
            $failed = true;
        }
        $this->assertTrue($failed, 'job must fail so queue retries apply');

        // VK delivered and recorded; MAX failure is NOT a delivery.
        $this->assertSame(1, ReceiptChannelDelivery::where('channel', 'vk')->count());
        $this->assertSame(0, ReceiptChannelDelivery::where('channel', 'max')->count());
        Http::assertSentCount(2);

        // Retry: MAX is attempted again (now healthy), VK is skipped.
        $maxUp = true;
        $this->runJob($receipt);

        $this->assertSame(1, ReceiptChannelDelivery::where('channel', 'max')->count());
        $this->assertSame(1, ReceiptChannelDelivery::where('channel', 'vk')->count());
        Http::assertSentCount(3); // only MAX on the retry
    }

    public function test_disabled_or_missing_channels_are_skipped_without_error(): void
    {
        $this->fakeMessengerConfig();
        Http::preventStrayRequests();

        $receipt = $this->attachedReceipt();
        // Default factory state: max_id null, max_notifications true but no id;
        // vk_notifications false.
        $this->owner->update(['max_id' => null, 'vk_id' => null, 'vk_notifications' => false]);

        $this->runJob($receipt); // must not throw

        Http::assertNothingSent();
        $this->assertSame(0, ReceiptChannelDelivery::count());
    }

    public function test_notification_flag_off_suppresses_channel(): void
    {
        $this->fakeMessengerConfig();
        Http::preventStrayRequests();

        $receipt = $this->attachedReceipt();
        $this->owner->update([
            'max_id' => 'max-user-1', 'max_notifications' => false,
            'vk_id' => '12345', 'vk_notifications' => false,
        ]);

        $this->runJob($receipt);

        Http::assertNothingSent();
        $this->runJob($receipt);
        Http::assertNothingSent();
    }

    public function test_unattached_receipt_is_noop(): void
    {
        $this->fakeMessengerConfig();
        Http::preventStrayRequests();

        $receipt = $this->awaitingReceipt(); // no URL yet
        $this->owner->update(['max_id' => 'max-user-1', 'max_notifications' => true]);

        // A defensive intent for a receipt that never reached "attached".
        ReceiptDeliveryEvent::create([
            'payment_receipt_id' => $receipt->getKey(),
            'url_version' => $receipt->refresh()->url_version,
            'receipt_url' => self::URL_A,
        ]);

        $this->runJob($receipt);

        Http::assertNothingSent();
        $this->assertSame(0, ReceiptChannelDelivery::count());
    }

    public function test_missing_receipt_is_noop(): void
    {
        $this->fakeMessengerConfig();
        Http::preventStrayRequests();
        $this->owner->update(['max_id' => 'max-user-1', 'max_notifications' => true]);

        (new SendReceiptUrlNotificationJob((string) Str::uuid()))->handle(
            app(MaxApiClient::class),
            app(VkApiClient::class),
        );

        Http::assertNothingSent();
    }

    // ── Review defects reproduced (expected RED on the current code) ──

    /**
     * Review #1: dedupe by sha256(URL) suppresses a real A → B → A change.
     * Returning to a previous URL is a new event and must be messaged again.
     */
    public function test_returning_to_previous_url_delivers_again(): void
    {
        Queue::fake();
        $this->fakeMessengerConfig();

        $sentTexts = [];
        Http::fake(function ($request) use (&$sentTexts) {
            if (str_contains($request->url(), 'max.test')) {
                $sentTexts[] = (string) $request['text'];

                return Http::response(['message' => ['body' => ['mid' => 'mid-'.count($sentTexts)]]]);
            }

            return Http::response([], 404);
        });

        $receipt = $this->awaitingReceipt();
        $this->owner->update([
            'max_id' => 'max-user-1', 'max_notifications' => true, 'vk_notifications' => false,
        ]);
        $service = app(PaymentReceiptService::class);

        $service->attachUrl($receipt, self::URL_A, $this->admin);
        $this->runJob($receipt);
        $service->attachUrl($receipt, self::URL_B, $this->admin);
        $this->runJob($receipt);
        $service->attachUrl($receipt, self::URL_A, $this->admin);
        $this->runJob($receipt);

        $this->assertCount(3, $sentTexts, 'A → B → A is two real changes plus the first attach: three messages');
        $this->assertSame(
            2,
            count(array_filter($sentTexts, fn ($t) => str_contains($t, self::URL_A))),
            'URL_A must be messaged on the first attach and again when it returns',
        );
        $this->assertSame(
            1,
            count(array_filter($sentTexts, fn ($t) => str_contains($t, self::URL_B))),
        );
        $this->assertSame(3, ReceiptChannelDelivery::count(), 'each event needs its own delivery record');
    }

    /**
     * Review #3: two workers delivering the SAME event to the SAME channel
     * must not both reach HTTP — the winner is decided before the request.
     */
    public function test_two_overlapping_deliveries_of_one_event_send_once(): void
    {
        $this->fakeMessengerConfig();

        $receipt = $this->attachedReceipt();
        $this->owner->update([
            'max_id' => 'max-user-1', 'max_notifications' => true, 'vk_notifications' => false,
        ]);

        $eventId = $this->currentEvent($receipt)->getKey();
        $first = new SendReceiptUrlNotificationJob($eventId);
        $second = new SendReceiptUrlNotificationJob($eventId);

        $overlapStarted = false;
        Http::fake(function ($request) use (&$overlapStarted, $second) {
            if (! str_contains($request->url(), 'max.test')) {
                return Http::response([], 404);
            }

            if (! $overlapStarted) {
                $overlapStarted = true;
                // The second worker picks the same event up while the first
                // is still inside its HTTP call.
                $second->handle(app(MaxApiClient::class), app(VkApiClient::class));
            }

            return Http::response(['message' => ['body' => ['mid' => 'mid-1']]]);
        });

        $first->handle(app(MaxApiClient::class), app(VkApiClient::class));

        Http::assertSentCount(1, 'only the claiming worker may reach HTTP');
        $this->assertSame(1, ReceiptChannelDelivery::count());
    }

    /**
     * Review #4: a failure writing the MAX result must not stop VK from
     * being processed, and the failed MAX send must not be recorded as a
     * delivery.
     */
    public function test_max_result_write_failure_does_not_block_vk(): void
    {
        $this->fakeMessengerConfig();
        Http::fake([
            'max.test/*' => Http::response(['message' => ['body' => ['mid' => 'mid-1']]]),
            'api.vk.com/*' => Http::response(['response' => 42]),
        ]);

        $receipt = $this->attachedReceipt();
        $this->owner->update([
            'max_id' => 'max-user-1', 'max_notifications' => true,
            'vk_id' => '12345', 'vk_notifications' => true,
        ]);

        $failMaxResultWrite = function ($model): void {
            if ($model->channel === 'max'
                && $model->delivered_at !== null
                && ($model->status ?? null) !== 'pending') {
                throw new \RuntimeException('simulated MAX result write failure');
            }
        };
        ReceiptChannelDelivery::creating($failMaxResultWrite);
        ReceiptChannelDelivery::updating($failMaxResultWrite);

        $failed = false;
        try {
            $this->runJob($receipt);
        } catch (\RuntimeException) {
            $failed = true;
        }
        $this->assertTrue($failed, 'the MAX failure must still fail the job so retries apply');

        Http::assertSentCount(2, 'VK must be processed even though the MAX result write failed');
        $this->assertTrue(
            ReceiptChannelDelivery::where('channel', 'vk')->exists(),
            'VK delivery must be recorded independently',
        );
        $this->assertTrue(
            ReceiptChannelDelivery::where('channel', 'max')->get()
                ->every(fn ($r) => $r->delivered_at === null),
            'the failed MAX delivery must not be marked successful',
        );
    }

    /**
     * Review #3: a job for a superseded event (URL replaced before it ran)
     * is stale and must be skipped; the job of the CURRENT event delivers
     * the current URL.
     */
    public function test_stale_event_after_url_replacement_is_skipped(): void
    {
        Queue::fake();
        $this->fakeMessengerConfig();
        Http::fake([
            'max.test/*' => Http::response(['message' => ['body' => ['mid' => 'mid-1']]]),
            'api.vk.com/*' => Http::response([], 404),
        ]);

        $receipt = $this->awaitingReceipt();
        $this->owner->update(['max_id' => 'max-user-1', 'max_notifications' => true, 'vk_notifications' => false]);
        $service = app(PaymentReceiptService::class);

        $service->attachUrl($receipt, self::URL_A, $this->admin);
        $staleEventId = $this->currentEvent($receipt)->getKey();
        $service->attachUrl($receipt, self::URL_B, $this->admin);

        (new SendReceiptUrlNotificationJob($staleEventId))
            ->handle(app(MaxApiClient::class), app(VkApiClient::class));
        Http::assertNothingSent();

        $this->runJob($receipt);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], self::URL_B));
        Http::assertNotSent(fn ($request) => str_contains((string) $request['text'], self::URL_A));
    }

    // ── Helpers ──

    private function awaitingReceipt(): PaymentReceipt
    {
        return PaymentReceipt::create([
            'payment_attempt_id' => $this->makeAttempt()->id,
            'status' => PaymentReceiptStatus::AwaitingManual,
        ]);
    }

    private function attachedReceipt(): PaymentReceipt
    {
        $receipt = $this->awaitingReceipt();
        $receipt->update([
            'status' => PaymentReceiptStatus::Attached,
            'receipt_url' => self::URL_A,
            'url_version' => 1,
            'attached_at' => now(),
        ]);
        $receipt->refresh();

        // The delivery intent an attach would have produced (event v1).
        ReceiptDeliveryEvent::create([
            'payment_receipt_id' => $receipt->getKey(),
            'url_version' => 1,
            'receipt_url' => self::URL_A,
            'dispatched_at' => now(),
        ]);

        return $receipt;
    }

    /**
     * The outbox event for the receipt's CURRENT url_version — what the
     * queue would run after a successful dispatch.
     */
    private function currentEvent(PaymentReceipt $receipt): ReceiptDeliveryEvent
    {
        $receipt->refresh();

        return ReceiptDeliveryEvent::query()
            ->where('payment_receipt_id', $receipt->getKey())
            ->where('url_version', $receipt->url_version)
            ->firstOrFail();
    }

    private function makeAttempt(): PaymentAttempt
    {
        $billingSub = BillingSubscription::firstOrCreate(
            [
                'workspace_id' => $this->workspace->id,
                'tariff_plan_id' => $this->proPlan->id,
            ],
            ['status' => BillingSubscriptionStatus::Active],
        );

        $seq = ++$this->cycleSeq;

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now()->subMonths($seq),
            'period_end' => now()->subMonths($seq - 1),
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Payment,
        ]);

        return PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'tbank',
            'payment_env' => PaymentEnvironment::Live,
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'core_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => 'prov_'.bin2hex(random_bytes(8)),
            'status' => PaymentAttemptStatus::Succeeded,
            'initiated_at' => now(),
            'finished_at' => now(),
            'confirmed_at' => now(),
        ]);
    }

    /**
     * @return array{string, string} base URLs of the faked MAX/VK endpoints
     */
    private function fakeMessengerConfig(): array
    {
        config([
            'services.max.api_url' => 'https://max.test/api/v1',
            'services.max.bot_token' => 'test-token',
            'services.vk.bot_token' => 'vk-token',
            'services.vk.api_version' => '5.199',
        ]);

        return ['https://max.test/api/v1/messages', 'https://api.vk.com/method/messages.send'];
    }

    private function runJob(PaymentReceipt $receipt): void
    {
        (new SendReceiptUrlNotificationJob($this->currentEvent($receipt)->getKey()))->handle(
            app(MaxApiClient::class),
            app(VkApiClient::class),
        );
    }

    private function withNotification(User $user, array $data): \App\Models\Notification
    {
        return $user->notifications()->create([
            'id' => Str::uuid(),
            'type' => SystemNotification::class,
            'data' => $data,
        ]);
    }
}
