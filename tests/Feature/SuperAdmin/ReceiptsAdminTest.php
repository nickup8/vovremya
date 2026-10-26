<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentEnvironment;
use App\Enums\PaymentReceiptStatus;
use App\Enums\PlatformPermission;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\PaymentAttempt;
use App\Models\PaymentReceipt;
use App\Models\PlatformAdminAccess;
use App\Models\SuperAdminAuditLog;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReceiptsAdminTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_URL = 'https://lknpd.nalog.ru/api/v1/receipt/5248061993/200glz8o7h/print';

    private TariffPlan $proPlan;

    private Workspace $workspace;

    private PaymentAttempt $attempt;

    private PaymentReceipt $receipt;

    private User $owner;

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

        $billingSub = BillingSubscription::create([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::Active,
        ]);

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now(),
            'period_end' => now()->addMonth(),
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Payment,
        ]);

        $this->attempt = PaymentAttempt::create([
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

        $this->receipt = PaymentReceipt::create([
            'payment_attempt_id' => $this->attempt->id,
            'status' => PaymentReceiptStatus::AwaitingManual,
        ]);
    }

    private function createLimitedAdmin(array $permissions): User
    {
        $user = User::factory()->master()->create(['is_super_admin' => false]);
        PlatformAdminAccess::create([
            'user_id' => $user->id,
            'permissions' => $permissions,
            'is_active' => true,
        ]);

        return $user;
    }

    private function createRoot(): User
    {
        return User::factory()->master()->create(['is_super_admin' => true]);
    }

    // ── Access ──

    public function test_root_sees_receipts_section(): void
    {
        $this->actingAs($this->createRoot())
            ->get(route('super_admin.receipts'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SuperAdmin/Receipts')
                ->where('tab', 'awaiting')
                ->has('receipts.data', 1)
                ->where('canUpdate', true));
    }

    public function test_limited_admin_without_permission_is_rejected(): void
    {
        $admin = $this->createLimitedAdmin([PlatformPermission::DashboardView->value]);

        $this->actingAs($admin)->get(route('super_admin.receipts'))->assertForbidden();
        $this->actingAs($admin)->put(route('super_admin.receipts.attach', $this->receipt), [
            'receipt_url' => self::VALID_URL,
        ])->assertForbidden();
    }

    public function test_limited_admin_with_view_only_sees_but_cannot_update(): void
    {
        $admin = $this->createLimitedAdmin([PlatformPermission::ReceiptsView->value]);

        $this->actingAs($admin)->get(route('super_admin.receipts'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canUpdate', false));

        $this->actingAs($admin)->put(route('super_admin.receipts.attach', $this->receipt), [
            'receipt_url' => self::VALID_URL,
        ])->assertForbidden();
    }

    public function test_new_permissions_are_not_auto_granted_to_existing_admins(): void
    {
        $admin = $this->createLimitedAdmin([PlatformPermission::UsersView->value]);

        $this->assertFalse($admin->platformAdminAccess->hasPermission(PlatformPermission::ReceiptsView));
        $this->assertFalse($admin->platformAdminAccess->hasPermission(PlatformPermission::ReceiptsUpdate));
    }

    public function test_receipts_update_dependency_adds_view(): void
    {
        $admin = $this->createRoot();

        $this->actingAs($admin)->post(route('super_admin.admins.store'), [
            'user_id' => User::factory()->master()->create()->id,
            'permissions' => [PlatformPermission::ReceiptsUpdate->value],
        ])->assertRedirect();

        $access = PlatformAdminAccess::orderByDesc('created_at')->first();
        $this->assertContains(PlatformPermission::ReceiptsView->value, $access->permissions);
        $this->assertContains(PlatformPermission::ReceiptsUpdate->value, $access->permissions);
    }

    // ── Attach ──

    public function test_root_attaches_valid_url_with_audit_in_one_flow(): void
    {
        Http::preventStrayRequests();

        $root = $this->createRoot();

        $this->actingAs($root)
            ->put(route('super_admin.receipts.attach', $this->receipt), [
                'receipt_url' => self::VALID_URL,
            ])
            ->assertRedirect();

        $this->receipt->refresh();
        $this->assertSame(PaymentReceiptStatus::Attached, $this->receipt->status);
        $this->assertSame(self::VALID_URL, $this->receipt->receipt_url);
        $this->assertSame($root->id, $this->receipt->attached_by);
        $this->assertNotNull($this->receipt->attached_at);

        $audit = SuperAdminAuditLog::where('action', 'receipt.attached')->first();
        $this->assertNotNull($audit);
        $this->assertSame($this->receipt->id, $audit->target_id);
    }

    public function test_attaching_sends_database_notification_to_owner(): void
    {
        $root = $this->createRoot();

        $this->actingAs($root)
            ->put(route('super_admin.receipts.attach', $this->receipt), [
                'receipt_url' => self::VALID_URL,
            ])
            ->assertRedirect();

        $notification = $this->owner->notifications()->latest('created_at')->first();
        $this->assertNotNull($notification);
        $this->assertSame('Чек об оплате подписки', $notification->data['title'] ?? null);
        $this->assertSame('Чек готов. Откройте его по ссылке ниже', $notification->data['body'] ?? null);
        $this->assertSame(self::VALID_URL, $notification->data['action']['url'] ?? null);
        $this->assertSame('Открыть чек', $notification->data['action']['label'] ?? null);
        $this->assertSame($this->receipt->id, $notification->data['receipt_id'] ?? null);
    }

    public function test_resaving_same_url_creates_no_new_audit_or_notification(): void
    {
        $root = $this->createRoot();

        $this->actingAs($root)->put(route('super_admin.receipts.attach', $this->receipt), [
            'receipt_url' => self::VALID_URL,
        ])->assertRedirect();

        $auditCount = SuperAdminAuditLog::where('action', 'receipt.attached')->count();
        $notificationCount = $this->owner->notifications()->count();

        $this->actingAs($root)->put(route('super_admin.receipts.attach', $this->receipt), [
            'receipt_url' => self::VALID_URL,
        ])->assertRedirect();

        $this->assertSame($auditCount, SuperAdminAuditLog::where('action', 'receipt.attached')->count());
        $this->assertSame($notificationCount, $this->owner->notifications()->count());
        $this->assertSame(0, SuperAdminAuditLog::where('action', 'receipt.url_changed')->count());
    }

    public function test_changing_url_audits_and_notifies_update(): void
    {
        $root = $this->createRoot();

        $this->actingAs($root)->put(route('super_admin.receipts.attach', $this->receipt), [
            'receipt_url' => self::VALID_URL,
        ])->assertRedirect();

        $newUrl = 'https://lknpd.nalog.ru/api/v1/receipt/5248061993/zzz999yyy/print';

        $this->actingAs($root)->put(route('super_admin.receipts.attach', $this->receipt), [
            'receipt_url' => $newUrl,
        ])->assertRedirect();

        $this->assertSame($newUrl, $this->receipt->refresh()->receipt_url);
        $this->assertSame(1, SuperAdminAuditLog::where('action', 'receipt.url_changed')->count());

        $notifications = $this->owner->notifications()->get();
        $this->assertCount(2, $notifications);

        $update = $notifications->first(fn ($n) => ($n->data['title'] ?? null) === 'Ссылка на чек обновлена');
        $this->assertNotNull($update);
        $this->assertSame('Откройте чек по обновлённой ссылке', $update->data['body'] ?? null);
        $this->assertSame($newUrl, $update->data['action']['url'] ?? null);

        $first = $notifications->first(fn ($n) => ($n->data['title'] ?? null) === 'Чек об оплате подписки');
        $this->assertNotNull($first);
        $this->assertSame(self::VALID_URL, $first->data['action']['url'] ?? null);
    }

    public function test_invalid_urls_are_rejected(): void
    {
        $root = $this->createRoot();

        $invalid = [
            'http://lknpd.nalog.ru/api/v1/receipt/5248061993/abc/print',
            'https://evil.com/api/v1/receipt/5248061993/abc/print',
            'https://lknpd.nalog.ru/api/v1/receipt/7707083893/abc/print',
            'https://lknpd.nalog.ru:443/api/v1/receipt/5248061993/abc/print',
            'https://lknpd.nalog.ru/api/v1/receipt/5248061993/abc/print?x=1',
            'https://lknpd.nalog.ru/api/v1/receipt/5248061993/abc/print#f',
            'https://lknpd.nalog.ru/api/v1/receipt/5248061993/ab/c/print',
            'not-a-url',
        ];

        foreach ($invalid as $url) {
            $this->actingAs($root)->put(route('super_admin.receipts.attach', $this->receipt), [
                'receipt_url' => $url,
            ])->assertSessionHasErrors('receipt_url');

            $this->assertSame(PaymentReceiptStatus::AwaitingManual, $this->receipt->refresh()->status);
            $this->assertNull($this->receipt->receipt_url);
        }
    }

    public function test_attach_forbidden_without_configured_seller_inn(): void
    {
        config(['billing.receipts.seller_inn' => null]);

        $this->actingAs($this->createRoot())
            ->put(route('super_admin.receipts.attach', $this->receipt), [
                'receipt_url' => self::VALID_URL,
            ])
            ->assertSessionHasErrors('receipt_url');

        $this->assertNull($this->receipt->refresh()->receipt_url);
    }

    // ── Tabs ──

    public function test_tabs_filter_correctly(): void
    {
        $root = $this->createRoot();

        $this->actingAs($root)->get(route('super_admin.receipts', ['tab' => 'awaiting']))
            ->assertInertia(fn ($page) => $page->has('receipts.data', 1));

        $this->receipt->update([
            'status' => PaymentReceiptStatus::Attached,
            'receipt_url' => self::VALID_URL,
            'attached_at' => now(),
        ]);

        $this->actingAs($root)->get(route('super_admin.receipts', ['tab' => 'attached']))
            ->assertInertia(fn ($page) => $page->has('receipts.data', 1));

        $this->actingAs($root)->get(route('super_admin.receipts', ['tab' => 'awaiting']))
            ->assertInertia(fn ($page) => $page->has('receipts.data', 0));

        $this->receipt->update(['refund_review_required' => true]);

        $this->actingAs($root)->get(route('super_admin.receipts', ['tab' => 'refund_review']))
            ->assertInertia(fn ($page) => $page->has('receipts.data', 1));
    }

    // ── Refund review ──

    public function test_mark_refund_reviewed_clears_flag_with_audit(): void
    {
        $this->receipt->update(['refund_review_required' => true, 'status' => PaymentReceiptStatus::Attached]);
        $root = $this->createRoot();

        $this->actingAs($root)
            ->post(route('super_admin.receipts.refund_reviewed', $this->receipt))
            ->assertRedirect();

        $this->receipt->refresh();
        $this->assertFalse($this->receipt->refund_review_required);
        $this->assertNotNull($this->receipt->refund_reviewed_at);
        $this->assertSame($root->id, $this->receipt->refund_reviewed_by);
        $this->assertSame(1, SuperAdminAuditLog::where('action', 'receipt.refund_reviewed')->count());
    }

    public function test_mark_refund_reviewed_twice_is_noop(): void
    {
        $this->receipt->update(['refund_review_required' => true, 'status' => PaymentReceiptStatus::Attached]);
        $root = $this->createRoot();

        $this->actingAs($root)
            ->post(route('super_admin.receipts.refund_reviewed', $this->receipt))
            ->assertRedirect();

        $this->receipt->refresh();
        $reviewedAt = $this->receipt->refund_reviewed_at;
        $reviewedBy = $this->receipt->refund_reviewed_by;

        $this->travel(5)->minutes();
        $this->actingAs($this->createRoot())
            ->post(route('super_admin.receipts.refund_reviewed', $this->receipt))
            ->assertRedirect();
        $this->travelBack();

        $this->receipt->refresh();
        $this->assertFalse($this->receipt->refund_review_required);
        $this->assertTrue($reviewedAt->equalTo($this->receipt->refund_reviewed_at));
        $this->assertSame($reviewedBy, $this->receipt->refund_reviewed_by);
        $this->assertSame(1, SuperAdminAuditLog::where('action', 'receipt.refund_reviewed')->count());
    }
}
