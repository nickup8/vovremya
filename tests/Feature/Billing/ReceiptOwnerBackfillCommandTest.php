<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentEnvironment;
use App\Enums\PaymentReceiptStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\Notification;
use App\Models\PaymentAttempt;
use App\Models\PaymentReceipt;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\SystemNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReceiptOwnerBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    private const URL_A = 'https://lknpd.nalog.ru/api/v1/receipt/5248061993/aaa111/print';

    private TariffPlan $proPlan;

    private User $owner;

    private Workspace $workspace;

    private int $cycleSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.receipts.seller_inn' => '5248061993']);

        $this->proPlan = TariffPlan::create([
            'code' => 'pro', 'name' => 'Профи', 'price_monthly' => 490,
            'max_appointments_per_month' => null, 'max_masters' => 1,
            'features' => ['unlimited_appointments'], 'is_active' => true,
        ]);

        $this->owner = User::factory()->master()->create();
        $this->workspace = Workspace::create([
            'name' => 'ws-'.$this->owner->id,
            'owner_id' => $this->owner->id,
        ]);
        $this->workspace->ensureSlug();
        $this->owner->update(['workspace_id' => $this->workspace->id]);
    }

    public function test_creates_action_notification_for_attached_live_receipt(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $receipt = $this->makeReceipt(PaymentReceiptStatus::Attached, self::URL_A, PaymentEnvironment::Live);

        $this->artisan('receipts:backfill-owner-notifications')
            ->assertExitCode(0);

        $notification = $this->owner->notifications()->latest('created_at')->firstOrFail();
        $this->assertSame(SystemNotification::class, $notification->type);
        $this->assertSame('Чек об оплате подписки', $notification->data['title']);
        $this->assertSame(self::URL_A, $notification->data['action']['url']);
        $this->assertSame('Открыть чек', $notification->data['action']['label']);
        $this->assertSame($receipt->id, $notification->data['receipt_id']);

        // No messenger sends for old receipts, no delivery jobs.
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_rerun_is_idempotent_by_receipt_id(): void
    {
        Queue::fake();
        $this->makeReceipt(PaymentReceiptStatus::Attached, self::URL_A, PaymentEnvironment::Live);

        $this->artisan('receipts:backfill-owner-notifications')->assertExitCode(0);
        $this->artisan('receipts:backfill-owner-notifications')->assertExitCode(0);

        $this->assertSame(1, $this->owner->notifications()->count());
    }

    public function test_dry_run_creates_nothing_but_reports(): void
    {
        Queue::fake();
        $this->makeReceipt(PaymentReceiptStatus::Attached, self::URL_A, PaymentEnvironment::Live);

        $this->artisan('receipts:backfill-owner-notifications --dry-run')
            ->expectsOutputToContain('dry-run')
            ->assertExitCode(0);

        $this->assertSame(0, $this->owner->notifications()->count());
    }

    public function test_dedupes_by_receipt_id_not_title(): void
    {
        // A pre-existing notification shares the title but carries no
        // receipt_id — it must not block the backfill for this receipt.
        $this->owner->notify(new SystemNotification('Чек об оплате подписки', 'Старый текст'));

        $this->makeReceipt(PaymentReceiptStatus::Attached, self::URL_A, PaymentEnvironment::Live);

        $this->artisan('receipts:backfill-owner-notifications')->assertExitCode(0);

        $this->assertSame(2, $this->owner->notifications()->count());

        // Old text notification untouched.
        $old = $this->owner->notifications()->get()
            ->first(fn ($n) => ($n->data['body'] ?? null) === 'Старый текст');
        $this->assertNotNull($old);
        $this->assertNull($old->data['receipt_id'] ?? null);

        // Re-run: receipt already notified via receipt_id, no new rows.
        $this->artisan('receipts:backfill-owner-notifications')->assertExitCode(0);
        $this->assertSame(2, $this->owner->notifications()->count());
    }

    public function test_excludes_awaiting_demo_and_foreign_env_receipts(): void
    {
        Queue::fake();
        Http::preventStrayRequests();

        $this->makeReceipt(PaymentReceiptStatus::AwaitingManual, null, PaymentEnvironment::Live);
        $this->makeReceipt(PaymentReceiptStatus::Attached, self::URL_A, PaymentEnvironment::Demo);
        $this->makeReceipt(PaymentReceiptStatus::Attached, self::URL_A, null);

        $this->artisan('receipts:backfill-owner-notifications')->assertExitCode(0);

        $this->assertSame(0, $this->owner->notifications()->count());
    }

    public function test_existing_owner_notifications_are_not_deleted_or_rewritten(): void
    {
        $this->owner->notify(new SystemNotification('Старое уведомление', 'Прежний текст'));
        $before = $this->owner->notifications()->latest('created_at')->firstOrFail();

        $this->makeReceipt(PaymentReceiptStatus::Attached, self::URL_A, PaymentEnvironment::Live);

        $this->artisan('receipts:backfill-owner-notifications')->assertExitCode(0);

        $old = Notification::find($before->id);
        $this->assertNotNull($old);
        $this->assertSame('Прежний текст', $old->data['body']);
        $this->assertSame(2, $this->owner->notifications()->count());
    }

    // ── Helpers ──

    private function makeReceipt(
        PaymentReceiptStatus $status,
        ?string $url,
        ?PaymentEnvironment $env,
    ): PaymentReceipt {
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

        $attempt = PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'tbank',
            'payment_env' => $env,
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

        return PaymentReceipt::create([
            'payment_attempt_id' => $attempt->id,
            'status' => $status,
            'receipt_url' => $url,
            'attached_at' => $status === PaymentReceiptStatus::Attached ? now() : null,
        ]);
    }
}
