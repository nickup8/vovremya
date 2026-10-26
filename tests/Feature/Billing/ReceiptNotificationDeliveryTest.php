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
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\ConnectorInterface;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

    // ── Claim ownership: takeover vs. the previous claimer's late writes ──

    /**
     * Review fix #1 — A captured the claim, its lease expired, B took the
     * claim over with a fresh claim_token. Everything A still does afterwards
     * (a late success on MAX, a late release on VK) must be a CAS miss: B's
     * claim survives untouched on both channels.
     */
    public function test_late_success_and_release_from_superseded_worker_leave_new_claim_intact(): void
    {
        $this->fakeMessengerConfig();

        $receipt = $this->attachedReceipt();
        $this->owner->update([
            'max_id' => 'max-user-1', 'max_notifications' => true,
            'vk_id' => '12345', 'vk_notifications' => true,
        ]);

        $eventId = $this->currentEvent($receipt)->getKey();
        $workerA = new SendReceiptUrlNotificationJob($eventId);

        $takenOver = [];
        Http::fake(function ($request) use (&$takenOver, $receipt) {
            if (str_contains($request->url(), 'max.test')) {
                // A is inside HTTP when its lease runs out: B captures the
                // claim, then A's send is accepted anyway (late success).
                $takenOver['max'] = $this->supersedeClaim($receipt, 'max');

                return Http::response(['message' => ['body' => ['mid' => 'mid-a']]]);
            }

            if (str_contains($request->url(), 'api.vk.com')) {
                // Same takeover on VK, but A's send fails afterwards — so A
                // will try to release a claim it no longer owns.
                $takenOver['vk'] = $this->supersedeClaim($receipt, 'vk');

                return Http::response(['error' => 'boom'], 500);
            }

            return Http::response([], 404);
        });

        $failed = false;
        try {
            $workerA->handle(app(MaxApiClient::class), app(VkApiClient::class));
        } catch (\RuntimeException) {
            $failed = true;
        }
        $this->assertTrue($failed, 'A\'s failed VK send must still fail the job');

        // Late SUCCESS on MAX: the write is conditioned on A's own token, so
        // it must not become the recorded result of B's claim.
        $max = ReceiptChannelDelivery::query()
            ->where('payment_receipt_id', $receipt->getKey())
            ->where('channel', 'max')
            ->sole();
        $this->assertSame($takenOver['max'], $max->claim_token, 'A must not rewrite B\'s claim token');
        $this->assertSame(ReceiptChannelDelivery::STATUS_PENDING, $max->status, 'A must not stamp its send over B\'s live claim');
        $this->assertNull($max->provider_message_id, 'A\'s message id must not be recorded as a success');
        $this->assertNotNull($max->lease_expires_at, 'B\'s lease must stay in place');

        // Late RELEASE on VK: the delete is conditioned the same way, so B's
        // claim is not dropped either.
        $vk = ReceiptChannelDelivery::query()
            ->where('payment_receipt_id', $receipt->getKey())
            ->where('channel', 'vk')
            ->sole();
        $this->assertSame($takenOver['vk'], $vk->claim_token, 'A must not rewrite B\'s claim token');
        $this->assertSame(ReceiptChannelDelivery::STATUS_PENDING, $vk->status, 'A\'s release must not remove B\'s claim');
        $this->assertNotNull($vk->lease_expires_at);

        Http::assertSentCount(2, 'A sent on both channels; the superseded claimer never re-sends');
    }

    /**
     * Review fix #1 — the intersection of a result read and a takeover: A
     * comes back from HTTP to record its send after B has already taken the
     * claim over AND recorded its own delivery. A's CAS must miss, leaving
     * B's recorded result (token, message id, timestamp) byte-for-byte as B
     * wrote it, and A must not treat its own send as a confirmed success.
     */
    public function test_result_read_racing_takeover_leaves_new_owners_delivery_intact(): void
    {
        $this->fakeMessengerConfig();

        $receipt = $this->attachedReceipt();
        $this->owner->update([
            'max_id' => 'max-user-1', 'max_notifications' => true,
            'vk_notifications' => false,
        ]);

        $eventId = $this->currentEvent($receipt)->getKey();
        $workerA = new SendReceiptUrlNotificationJob($eventId);

        $bResult = null;
        $depth = 0;
        Http::fake(function ($request) use (&$bResult, &$depth, $receipt, $eventId) {
            if (! str_contains($request->url(), 'max.test')) {
                return Http::response([], 404);
            }

            $depth++;

            if ($depth === 1) {
                // A's lease expires mid-flight: B takes the claim over, sends
                // (the nested request below) and records its result before A
                // returns to its own record write.
                ReceiptChannelDelivery::query()
                    ->where('payment_receipt_id', $receipt->getKey())
                    ->where('channel', 'max')
                    ->update(['lease_expires_at' => now()->subSecond()]);

                (new SendReceiptUrlNotificationJob($eventId))
                    ->handle(app(MaxApiClient::class), app(VkApiClient::class));

                $row = ReceiptChannelDelivery::query()
                    ->where('payment_receipt_id', $receipt->getKey())
                    ->where('channel', 'max')
                    ->sole();

                $bResult = [
                    'claim_token' => $row->claim_token,
                    'provider_message_id' => $row->provider_message_id,
                    'delivered_at' => $row->delivered_at?->getTimestamp(),
                ];

                // A's own send is accepted too — its late write is the race.
                return Http::response(['message' => ['body' => ['mid' => 'mid-a']]]);
            }

            return Http::response(['message' => ['body' => ['mid' => 'mid-b']]]);
        });

        $thrown = null;
        try {
            $workerA->handle(app(MaxApiClient::class), app(VkApiClient::class));
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNull($thrown, 'a missed CAS is not A\'s failure — the new owner is delivering');
        Http::assertSentCount(2, 'A and B each reached HTTP once');

        $row = ReceiptChannelDelivery::query()
            ->where('payment_receipt_id', $receipt->getKey())
            ->where('channel', 'max')
            ->sole();
        $this->assertSame($bResult['claim_token'], $row->claim_token, 'A must not rewrite the claim token');
        $this->assertSame(ReceiptChannelDelivery::STATUS_DELIVERED, $row->status);
        $this->assertSame('mid-b', $row->provider_message_id, 'A\'s message id must not replace B\'s recorded result');
        $this->assertSame($bResult['delivered_at'], $row->delivered_at?->getTimestamp());
        $this->assertNotSame('mid-a', $row->provider_message_id);
    }

    // ── Busy waiting vs. the real-error budget (real queue lifecycle) ──

    /**
     * Review fix #2 — a held claim costs queue attempts (one release per
     * re-check) but must never spend the job's failure budget: attempts grow
     * past $tries while the job stays alive, each release waits out the
     * observed lease instead of ticking every 15s, and once the lease expires
     * the very same job row continues and delivers.
     */
    public function test_busy_claim_grows_attempts_but_keeps_delivery_until_lease_expires(): void
    {
        config(['queue.default' => 'database']);
        $this->fakeMessengerConfig();
        Http::fake(['max.test/*' => Http::response(['message' => ['body' => ['mid' => 'mid-1']]])]);

        $receipt = $this->attachedReceipt();
        $this->owner->update([
            'max_id' => 'max-user-1', 'max_notifications' => true,
            'vk_notifications' => false,
        ]);

        $this->seedForeignClaim($receipt, 'max', now()->addSeconds(60));

        SendReceiptUrlNotificationJob::dispatch($this->currentEvent($receipt)->getKey());

        $deadline = (int) json_decode((string) DB::table('jobs')->value('payload'), true)['retryUntil'];
        $this->assertGreaterThan(now()->getTimestamp(), $deadline, 'the job must carry a waiting deadline');

        $worker = $this->makeWorker();
        $options = $this->workerOptions();

        $failedAttempts = 0;
        Event::listen(JobExceptionOccurred::class, function () use (&$failedAttempts) {
            $failedAttempts++;
        });

        // Attempt 1: another worker holds the claim → released, not failed,
        // and the delay covers the lease we observed (not a 15s tick).
        $this->runWorkerJob($worker, $options);
        $this->assertSame(0, $failedAttempts, 'a busy claim must not fail the attempt');
        Http::assertSentCount(0);
        $job = DB::table('jobs')->sole();
        $this->assertSame(1, (int) $job->attempts);
        $this->assertGreaterThanOrEqual(
            60,
            (int) $job->available_at - now()->getTimestamp(),
            'the re-check must wait out the remaining lease',
        );

        // Re-checks 2..4: whoever takes the claim over keeps renewing it, so
        // every cycle costs one attempt. attempts ends above $tries = 3 —
        // with an attempts-based cap the job would already be dead here.
        for ($cycle = 2; $cycle <= 4; $cycle++) {
            Carbon::setTestNow(now()->addSeconds(70));
            $this->seedForeignClaim($receipt, 'max', now()->addSeconds(60));

            $this->runWorkerJob($worker, $options);

            $this->assertSame(0, $failedAttempts, 'busy re-checks must not fail the job');
            Http::assertSentCount(0);
            $job = DB::table('jobs')->sole();
            $this->assertSame($cycle, (int) $job->attempts, 'every busy release grows the attempts counter');
        }

        $this->assertGreaterThan(3, (int) $job->attempts, 'attempts grew past $tries without failing the job');
        $this->assertSame(
            $deadline,
            (int) json_decode((string) DB::table('jobs')->value('payload'), true)['retryUntil'],
            'the waiting deadline is preserved across releases instead of being reset',
        );

        // The lease finally expires with no successor: the same job row
        // continues, claims and delivers.
        Carbon::setTestNow(now()->addSeconds(70));
        $this->runWorkerJob($worker, $options);

        $this->assertSame(0, $failedAttempts, 'the delivery run itself must not fail');
        Http::assertSentCount(1);
        $this->assertSame(0, DB::table('jobs')->count(), 'a delivered job is gone from the queue');
        $delivery = ReceiptChannelDelivery::query()
            ->where('payment_receipt_id', $receipt->getKey())
            ->where('channel', 'max')
            ->sole();
        $this->assertSame(ReceiptChannelDelivery::STATUS_DELIVERED, $delivery->status);
        $this->assertSame('mid-1', $delivery->provider_message_id);
        $this->assertGreaterThan(now()->getTimestamp(), $deadline, 'delivery happened inside the deadline');
    }

    /**
     * Review fix #2 — real API failures keep their own finite budget: every
     * failed run throws once, and the queue's exception counter fails the job
     * on the third one. The job is then terminal — no fourth send.
     */
    public function test_real_send_errors_have_a_finite_budget_of_their_own(): void
    {
        config(['queue.default' => 'database']);
        $this->fakeMessengerConfig();
        Http::fake(['max.test/*' => Http::response(['error' => 'boom'], 500)]);

        $receipt = $this->attachedReceipt();
        $this->owner->update([
            'max_id' => 'max-user-1', 'max_notifications' => true,
            'vk_notifications' => false,
        ]);

        $failedAttempts = 0;
        $failedJobs = 0;
        Event::listen(JobExceptionOccurred::class, function () use (&$failedAttempts) {
            $failedAttempts++;
        });
        Event::listen(JobFailed::class, function () use (&$failedJobs) {
            $failedJobs++;
        });

        SendReceiptUrlNotificationJob::dispatch($this->currentEvent($receipt)->getKey());

        $worker = $this->makeWorker();
        $options = $this->workerOptions();

        // Three real failures, spaced by the job's own backoff (5s, 15s).
        $expectedFailures = 0;
        foreach ([0, 5, 15] as $backoff) {
            if ($backoff > 0) {
                Carbon::setTestNow(now()->addSeconds($backoff));
            }

            $this->runWorkerJob($worker, $options);

            $expectedFailures++;
            $this->assertSame(
                $expectedFailures,
                $failedAttempts,
                'every run must fail on the API error and be released for a retry',
            );
            if ($expectedFailures < 3) {
                $this->assertSame(0, $failedJobs, 'the job must survive the first two real failures');
                $this->assertSame(1, DB::table('jobs')->count(), 'a retryable job stays queued');
            }
        }

        $this->assertSame(3, $failedAttempts, 'exactly three attempts were spent on real errors');
        $this->assertSame(1, $failedJobs, 'the third real failure must fail the job terminally');
        $this->assertSame(0, DB::table('jobs')->count(), 'a failed job is not re-queued');
        Http::assertSentCount(3, 'the error budget is exactly three sends');

        Carbon::setTestNow(now()->addSeconds(60));
        $this->runWorkerJob($worker, $options);
        $this->assertSame(3, $failedAttempts, 'nothing is left to run');
        Http::assertSentCount(3);
    }

    // ── Channel isolation ──

    /**
     * Review fix #3 — a failing MAX pass (API error) whose claim release
     * ALSO blows up must not swallow either error: VK is still processed and
     * recorded, and the job reports the primary MAX failure rather than the
     * secondary cleanup one.
     */
    public function test_max_error_with_failing_release_still_processes_vk(): void
    {
        $this->fakeMessengerConfig();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'max.test')) {
                return Http::response(['error' => 'boom'], 500);
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

        // The MAX claim release itself fails once, before it runs.
        $releaseAttempted = false;
        DB::beforeExecuting(function ($query, $bindings) use (&$releaseAttempted) {
            if (! $releaseAttempted
                && str_contains($query, 'delete from "receipt_channel_deliveries"')
                && in_array('max', $bindings, true)) {
                $releaseAttempted = true;

                throw new \RuntimeException('simulated releaseClaim failure');
            }
        });

        $thrown = null;
        try {
            $this->runJob($receipt);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'the MAX failure must still fail the job');
        $this->assertStringContainsString('channel(s): max', $thrown->getMessage());
        $this->assertStringNotContainsString(
            'simulated releaseClaim',
            $thrown->getMessage(),
            'the cleanup error must not replace the primary outcome',
        );
        $this->assertTrue($releaseAttempted, 'the release must actually have been attempted');

        Http::assertSentCount(2, 'VK must be processed although the MAX release failed');
        $this->assertTrue(
            ReceiptChannelDelivery::query()
                ->where('payment_receipt_id', $receipt->getKey())
                ->where('channel', 'vk')
                ->where('status', ReceiptChannelDelivery::STATUS_DELIVERED)
                ->exists(),
            'VK delivery must be recorded independently',
        );
        $this->assertSame(
            0,
            ReceiptChannelDelivery::query()
                ->where('channel', 'max')
                ->where('status', ReceiptChannelDelivery::STATUS_DELIVERED)
                ->count(),
            'the failed MAX send must not be recorded as delivered',
        );
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

    /**
     * Another worker's capture of a claim whose lease has just expired: the
     * row keeps existing but now carries B's fresh claim_token and a live
     * lease, exactly as the takeover in the job writes it.
     *
     * @return string B's claim_token
     */
    private function supersedeClaim(PaymentReceipt $receipt, string $channel): string
    {
        $receipt->refresh();
        $claimToken = (string) Str::uuid();

        ReceiptChannelDelivery::query()
            ->where('payment_receipt_id', $receipt->getKey())
            ->where('channel', $channel)
            ->where('url_version', $receipt->url_version)
            ->update([
                'claim_token' => $claimToken,
                'status' => ReceiptChannelDelivery::STATUS_PENDING,
                'lease_expires_at' => now()->addSeconds(60),
            ]);

        return $claimToken;
    }

    /**
     * A live claim held by ANOTHER worker on (receipt, channel) — the state
     * that makes our own claim attempt report "busy".
     *
     * @return string the foreign claim_token
     */
    private function seedForeignClaim(PaymentReceipt $receipt, string $channel, \DateTimeInterface $leaseUntil): string
    {
        $receipt->refresh();
        $claimToken = (string) Str::uuid();

        ReceiptChannelDelivery::updateOrCreate(
            [
                'payment_receipt_id' => $receipt->getKey(),
                'channel' => $channel,
                'url_version' => $receipt->url_version,
            ],
            [
                'receipt_url' => $receipt->receipt_url,
                'status' => ReceiptChannelDelivery::STATUS_PENDING,
                'claim_token' => $claimToken,
                'lease_expires_at' => $leaseUntil,
                'provider_message_id' => null,
                'delivered_at' => null,
            ],
        );

        return $claimToken;
    }

    /**
     * A worker with the same knobs `queue:work` runs with — including the
     * cache its exception counter (the real-error budget) lives in.
     */
    private function makeWorker(): Worker
    {
        $worker = new Worker(
            app(QueueManager::class),
            app(Dispatcher::class),
            app(ExceptionHandler::class),
            fn (): bool => false,
        );

        $worker->setCache(Cache::store());

        return $worker;
    }

    private function workerOptions(): WorkerOptions
    {
        return new WorkerOptions(
            name: 'default',
            backoff: 0,
            memory: 128,
            timeout: 60,
            sleep: 0,
            maxTries: 3,
        );
    }

    /**
     * One real queue attempt: pop + the worker's own bookkeeping (attempts
     * increment, release-after-exception, failure checks). Like `queue:work`,
     * the worker reports and swallows the job's exception, so outcomes are
     * observed through JobExceptionOccurred / JobFailed instead.
     */
    private function runWorkerJob(Worker $worker, WorkerOptions $options): void
    {
        $worker->runNextJob('database', 'default', $options);
    }
}
