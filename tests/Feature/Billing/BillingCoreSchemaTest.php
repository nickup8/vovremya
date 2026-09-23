<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\DiscountRule;
use App\Models\PaymentAttempt;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingCoreSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function createWorkspace(): Workspace
    {
        $user = \App\Models\User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'ws-'.$user->id,
            'owner_id' => $user->id,
        ]);
        $workspace->ensureSlug();
        $user->update(['workspace_id' => $workspace->id]);

        return $workspace;
    }

    private function createProPlan(): TariffPlan
    {
        return TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'max_appointments_per_month' => null,
            'max_masters' => 1,
            'features' => ['unlimited_appointments'],
            'is_active' => true,
        ]);
    }

    // ── BillingSubscription unique workspace/plan ──

    public function test_billing_subscription_unique_workspace_plan(): void
    {
        $workspace = $this->createWorkspace();
        $plan = $this->createProPlan();

        BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'status' => BillingSubscriptionStatus::PendingInitial,
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'status' => BillingSubscriptionStatus::Active,
        ]);
    }

    // ── BillingCycle unique subscription/period ──

    public function test_billing_cycle_unique_subscription_period(): void
    {
        $workspace = $this->createWorkspace();
        $plan = $this->createProPlan();

        $billingSub = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'status' => BillingSubscriptionStatus::Active,
        ]);

        BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'period_start' => '2026-07-21 00:00:00',
            'period_end' => '2026-08-21 00:00:00',
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'origin' => BillingCycleOrigin::Payment,
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'period_start' => '2026-07-21 00:00:00',
            'period_end' => '2026-08-21 00:00:00',
            'status' => BillingCycleStatus::Failed,
            'amount' => 0,
            'origin' => BillingCycleOrigin::Payment,
        ]);
    }

    // ── ProviderEvent dedup_key unique ──

    public function test_provider_event_dedup_key_unique(): void
    {
        DB::table('provider_events')->insert([
            'id' => \Illuminate\Support\Str::uuid(),
            'provider' => 'mock',
            'dedup_key' => 'unique_event_123',
            'received_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('provider_events')->insert([
            'id' => \Illuminate\Support\Str::uuid(),
            'provider' => 'mock',
            'dedup_key' => 'unique_event_123',
            'received_at' => now(),
        ]);
    }

    // ── Money fields stored precisely ──

    public function test_money_fields_stored_precisely(): void
    {
        $workspace = $this->createWorkspace();
        $plan = $this->createProPlan();

        $billingSub = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'status' => BillingSubscriptionStatus::Active,
        ]);

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'period_start' => '2026-07-21 00:00:00',
            'period_end' => '2026-08-21 00:00:00',
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'origin' => BillingCycleOrigin::Payment,
        ]);

        $this->assertSame(490, $cycle->fresh()->amount);

        // Test large amounts
        $cycle2 = BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'period_start' => '2026-08-21 00:00:00',
            'period_end' => '2026-09-21 00:00:00',
            'status' => BillingCycleStatus::Paid,
            'amount' => 5880,
            'origin' => BillingCycleOrigin::Payment,
        ]);

        $this->assertSame(5880, $cycle2->fresh()->amount);
    }

    // ── Enums and casts work correctly ──

    public function test_billing_subscription_status_enum_cast(): void
    {
        $workspace = $this->createWorkspace();
        $plan = $this->createProPlan();

        $sub = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'status' => BillingSubscriptionStatus::PendingInitial,
        ]);

        $this->assertInstanceOf(BillingSubscriptionStatus::class, $sub->fresh()->status);
        $this->assertSame(BillingSubscriptionStatus::PendingInitial, $sub->fresh()->status);
    }

    public function test_billing_cycle_status_enum_cast(): void
    {
        $workspace = $this->createWorkspace();
        $plan = $this->createProPlan();

        $billingSub = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'status' => BillingSubscriptionStatus::Active,
        ]);

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'period_start' => '2026-07-21 00:00:00',
            'period_end' => '2026-08-21 00:00:00',
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'origin' => BillingCycleOrigin::Payment,
        ]);

        $this->assertInstanceOf(BillingCycleStatus::class, $cycle->fresh()->status);
    }

    public function test_payment_attempt_status_enum_cast(): void
    {
        $workspace = $this->createWorkspace();
        $plan = $this->createProPlan();

        $billingSub = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'status' => BillingSubscriptionStatus::Active,
        ]);

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'period_start' => '2026-07-21 00:00:00',
            'period_end' => '2026-08-21 00:00:00',
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'origin' => BillingCycleOrigin::Payment,
        ]);

        $attempt = PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'mock',
            'attempt_number' => 1,
            'amount' => 490,
            'internal_order_id' => 'mock_test_123',
            'status' => PaymentAttemptStatus::Succeeded,
            'initiated_at' => now(),
        ]);

        $this->assertInstanceOf(PaymentAttemptStatus::class, $attempt->fresh()->status);
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->fresh()->status);
    }

    // ── PlanPrice backfill correctness ──

    public function test_plan_prices_created_correctly(): void
    {
        $plan = TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'is_active' => true,
        ]);

        DiscountRule::create(['period_months' => 1, 'discount_percent' => 0, 'is_active' => true]);
        DiscountRule::create(['period_months' => 3, 'discount_percent' => 5, 'is_active' => true]);
        DiscountRule::create(['period_months' => 6, 'discount_percent' => 10, 'is_active' => true]);
        DiscountRule::create(['period_months' => 12, 'discount_percent' => 20, 'is_active' => true]);

        // Simulate backfill logic (same as migration)
        $periods = [1, 3, 6, 12];
        $discounts = DiscountRule::where('is_active', true)->get()->keyBy('period_months');

        foreach ($periods as $months) {
            $discount = $discounts->get($months);
            $discountPercent = $discount?->discount_percent ?? 0;
            $baseAmount = $plan->price_monthly * $months;
            $finalAmount = (int) round($baseAmount * (1 - $discountPercent / 100));

            PlanPrice::create([
                'tariff_plan_id' => $plan->id,
                'period_months' => $months,
                'base_amount' => $baseAmount,
                'discount_percent' => $discountPercent,
                'final_amount' => $finalAmount,
                'currency' => 'RUB',
                'version' => 1,
                'valid_from' => now(),
                'is_active' => true,
            ]);
        }

        $prices = PlanPrice::where('tariff_plan_id', $plan->id)->orderBy('period_months')->get();

        $this->assertCount(4, $prices);

        // 1 month: 490 * 1 * (1 - 0/100) = 490
        $this->assertSame(490, $prices[0]->base_amount);
        $this->assertSame(0, $prices[0]->discount_percent);
        $this->assertSame(490, $prices[0]->final_amount);

        // 3 months: 490 * 3 * (1 - 5/100) = 1470 * 0.95 = 1397 (rounded)
        $this->assertSame(1470, $prices[1]->base_amount);
        $this->assertSame(5, $prices[1]->discount_percent);
        $this->assertSame(1397, $prices[1]->final_amount);

        // 6 months: 490 * 6 * (1 - 10/100) = 2940 * 0.9 = 2646
        $this->assertSame(2940, $prices[2]->base_amount);
        $this->assertSame(10, $prices[2]->discount_percent);
        $this->assertSame(2646, $prices[2]->final_amount);

        // 12 months: 490 * 12 * (1 - 20/100) = 5880 * 0.8 = 4704
        $this->assertSame(5880, $prices[3]->base_amount);
        $this->assertSame(20, $prices[3]->discount_percent);
        $this->assertSame(4704, $prices[3]->final_amount);
    }

    // ── BillingSubscription money fields use integer ──

    public function test_amount_paid_is_integer_not_float(): void
    {
        $workspace = $this->createWorkspace();
        $plan = $this->createProPlan();

        $billingSub = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'status' => BillingSubscriptionStatus::Active,
        ]);

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'period_start' => '2026-07-21 00:00:00',
            'period_end' => '2026-08-21 00:00:00',
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'origin' => BillingCycleOrigin::Payment,
        ]);

        $this->assertIsInt($cycle->fresh()->amount);
        $this->assertInstanceOf(BillingSubscriptionStatus::class, $billingSub->fresh()->status);
    }
}
