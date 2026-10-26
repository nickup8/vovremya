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

/**
 * The cabinet receipt list was removed from the payment page: the receipt
 * link now reaches the owner through the notification with the «Открыть чек»
 * action (see ReceiptNotificationDeliveryTest). These tests pin the removal
 * itself plus the access rules that must survive it.
 */
class CabinetReceiptsTest extends TestCase
{
    use RefreshDatabase;

    private const URL_A = 'https://lknpd.nalog.ru/api/v1/receipt/5248061993/aaa111/print';

    private TariffPlan $proPlan;

    private User $owner;

    private Workspace $workspace;

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

    private function createAttachedReceipt(string $url): PaymentReceipt
    {
        $billingSub = BillingSubscription::firstOrCreate(
            [
                'workspace_id' => $this->workspace->id,
                'tariff_plan_id' => $this->proPlan->id,
            ],
            ['status' => BillingSubscriptionStatus::Active],
        );

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now()->subMonth(),
            'period_end' => now(),
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
            'confirmed_at' => now(),
        ]);

        return PaymentReceipt::create([
            'payment_attempt_id' => $attempt->id,
            'status' => PaymentReceiptStatus::Attached,
            'receipt_url' => $url,
            'attached_at' => now(),
        ]);
    }

    public function test_billing_page_has_no_receipts_list_prop(): void
    {
        $this->createAttachedReceipt(self::URL_A);

        $this->actingAs($this->owner)
            ->get(route('admin.billing'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/billing')
                ->missing('receipts'));
    }

    public function test_billing_page_renders_without_receipts_prop_at_all(): void
    {
        $this->actingAs($this->owner)
            ->get(route('admin.billing'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/billing')
                ->missing('receipts')
                ->has('plans')
                ->has('current'));
    }

    public function test_payment_return_params_survive_without_receipts_loading(): void
    {
        $this->createAttachedReceipt(self::URL_A);

        $this->actingAs($this->owner)
            ->get(route('admin.billing', [
                'payment_attempt' => 'prov_unknown',
                'payment_check' => 'closed',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/billing')
                ->missing('receipts'));
    }

    public function test_non_owner_role_cannot_see_billing_page(): void
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
