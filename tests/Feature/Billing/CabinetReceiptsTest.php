<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentEnvironment;
use App\Enums\PaymentReceiptStatus;
use App\Enums\UserRole;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\PaymentAttempt;
use App\Models\PaymentReceipt;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CabinetReceiptsTest extends TestCase
{
    use RefreshDatabase;

    private const URL_A = 'https://lknpd.nalog.ru/api/v1/receipt/5248061993/aaa111/print';

    private const URL_B = 'https://lknpd.nalog.ru/api/v1/receipt/5248061993/bbb222/print';

    private TariffPlan $proPlan;

    private User $owner;

    private Workspace $workspace;

    private int $receiptSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.core_entitlement' => false,
            'billing.legacy_mock_webhook_secret' => 'test_secret_123',
            'billing.receipts.seller_inn' => '5248061993',
        ]);

        $this->proPlan = TariffPlan::create([
            'code' => 'pro', 'name' => 'Профи', 'price_monthly' => 490,
            'max_appointments_per_month' => null, 'max_masters' => 1,
            'features' => ['unlimited_appointments'], 'is_active' => true,
        ]);

        $this->owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->workspace = Workspace::create([
            'name' => 'ws-'.$this->owner->id,
            'owner_id' => $this->owner->id,
        ]);
        $this->workspace->ensureSlug();
        $this->owner->update(['workspace_id' => $this->workspace->id]);
    }

    private function createAttachedReceipt(Workspace $ws, string $url, ?string $confirmedAt = null): PaymentReceipt
    {
        $billingSub = BillingSubscription::firstOrCreate(
            [
                'workspace_id' => $ws->id,
                'tariff_plan_id' => $this->proPlan->id,
            ],
            ['status' => BillingSubscriptionStatus::Active],
        );

        $seq = ++$this->receiptSeq;

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $ws->id,
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
            'payment_env' => PaymentEnvironment::Live,
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'core_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => 'prov_'.bin2hex(random_bytes(8)),
            'status' => PaymentAttemptStatus::Succeeded,
            'initiated_at' => now(),
            'finished_at' => now(),
            'confirmed_at' => $confirmedAt ?? now(),
        ]);

        return PaymentReceipt::create([
            'payment_attempt_id' => $attempt->id,
            'status' => PaymentReceiptStatus::Attached,
            'receipt_url' => $url,
            'attached_at' => now(),
        ]);
    }

    public function test_owner_sees_attached_receipts_with_amount_period_and_link(): void
    {
        $this->createAttachedReceipt($this->workspace, self::URL_A);

        $this->actingAs($this->owner)
            ->get(route('admin.billing'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/billing')
                ->has('receipts.data', 1)
                ->where('receipts.data.0.receipt_url', self::URL_A)
                ->where('receipts.data.0.amount', 490)
                ->where('receipts.data.0.status_label', 'Доступен в кабинете')
                ->where('receipts.data.0.tariff_name', 'Профи')
                ->has('receipts.data.0.confirmed_at'));
    }

    public function test_awaiting_manual_receipts_are_not_shown_to_customer(): void
    {
        $receipt = $this->createAttachedReceipt($this->workspace, self::URL_A);
        $receipt->update(['status' => PaymentReceiptStatus::AwaitingManual, 'receipt_url' => null]);

        $this->actingAs($this->owner)
            ->get(route('admin.billing'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('receipts.data', 0));
    }

    public function test_other_workspace_receipts_are_isolated(): void
    {
        $otherOwner = User::factory()->create(['role' => UserRole::Owner]);
        $otherWs = Workspace::create([
            'name' => 'ws-'.$otherOwner->id,
            'owner_id' => $otherOwner->id,
        ]);
        $otherWs->ensureSlug();
        $otherOwner->update(['workspace_id' => $otherWs->id]);

        $this->createAttachedReceipt($this->workspace, self::URL_A);
        $this->createAttachedReceipt($otherWs, self::URL_B);

        $this->actingAs($this->owner)
            ->get(route('admin.billing'))
            ->assertInertia(fn ($page) => $page
                ->has('receipts.data', 1)
                ->where('receipts.data.0.receipt_url', self::URL_A));

        $this->actingAs($otherOwner)
            ->get(route('admin.billing'))
            ->assertInertia(fn ($page) => $page
                ->has('receipts.data', 1)
                ->where('receipts.data.0.receipt_url', self::URL_B));
    }

    public function test_receipts_props_are_small_page_not_full_history(): void
    {
        foreach (range(1, 7) as $i) {
            $this->createAttachedReceipt(
                $this->workspace,
                'https://lknpd.nalog.ru/api/v1/receipt/5248061993/receipt'.$i.'/print',
            );
        }

        $this->actingAs($this->owner)
            ->get(route('admin.billing'))
            ->assertInertia(fn ($page) => $page
                ->has('receipts.data', 5)
                ->where('receipts.total', 7)
                ->where('receipts.last_page', 2));
    }

    public function test_non_owner_role_cannot_see_receipts_page(): void
    {
        // canManageBilling is Owner-only; a non-owner member of the
        // workspace must not reach the billing cabinet at all.
        $member = User::factory()->create([
            'role' => UserRole::Master,
            'workspace_id' => $this->workspace->id,
        ]);

        $this->actingAs($member)->get(route('admin.billing'))->assertForbidden();
    }
}
