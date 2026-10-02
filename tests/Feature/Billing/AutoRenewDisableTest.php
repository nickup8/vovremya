<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\UserRole;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\PlanPrice;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AutoRenewDisableTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->proPlan = TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'max_appointments_per_month' => null,
            'max_masters' => 1,
            'features' => ['unlimited_appointments'],
            'is_active' => true,
        ]);

        foreach ([1, 3, 6, 12] as $months) {
            PlanPrice::create([
                'tariff_plan_id' => $this->proPlan->id,
                'period_months' => $months,
                'base_amount' => 490 * $months,
                'discount_percent' => 0,
                'final_amount' => 490 * $months,
                'currency' => 'RUB',
                'version' => 1,
                'valid_from' => now(),
                'is_active' => true,
            ]);
        }
    }

    private function createOwnerWithWorkspace(): array
    {
        $owner = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'ws-'.$owner->id,
            'owner_id' => $owner->id,
        ]);
        $workspace->ensureSlug();
        $owner->update(['workspace_id' => $workspace->id]);

        return [$owner, $workspace];
    }

    private function createConsentedActiveSub(Workspace $workspace, array $overrides = []): BillingSubscription
    {
        return BillingSubscription::create(array_merge([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::Active,
            'renewal_period_months' => 1,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
            'next_charge_at' => now()->addMonth(),
            'cancel_at_period_end' => false,
            'auto_renew_consent_at' => now(),
            'auto_renew_consent_version' => config('billing.recurring_terms_version'),
        ], $overrides));
    }

    public function test_owner_can_disable_auto_renew(): void
    {
        [$owner, $workspace] = $this->createOwnerWithWorkspace();
        $sub = $this->createConsentedActiveSub($workspace);

        $periodStart = $sub->current_period_start;
        $periodEnd = $sub->current_period_end;

        $response = $this->actingAs($owner)
            ->postJson(route('admin.billing.auto-renew.disable'));

        $response->assertOk();

        $sub->refresh();
        $this->assertTrue($sub->cancel_at_period_end);
        $this->assertNull($sub->next_charge_at);

        // Paid period continues unchanged
        $this->assertSame(BillingSubscriptionStatus::Active, $sub->status);
        $this->assertTrue($sub->current_period_start->equalTo($periodStart));
        $this->assertTrue($sub->current_period_end->equalTo($periodEnd));

        // Consent is preserved
        $this->assertNotNull($sub->auto_renew_consent_at);
        $this->assertSame(config('billing.recurring_terms_version'), $sub->auto_renew_consent_version);
        $this->assertSame(1, $sub->renewal_period_months);
    }

    public function test_disable_is_idempotent(): void
    {
        [$owner, $workspace] = $this->createOwnerWithWorkspace();
        $sub = $this->createConsentedActiveSub($workspace);

        $this->actingAs($owner)
            ->postJson(route('admin.billing.auto-renew.disable'))
            ->assertOk();

        $second = $this->actingAs($owner)
            ->postJson(route('admin.billing.auto-renew.disable'));

        $second->assertOk();

        $sub->refresh();
        $this->assertTrue($sub->cancel_at_period_end);
        $this->assertNull($sub->next_charge_at);
        $this->assertSame(BillingSubscriptionStatus::Active, $sub->status);
        $this->assertSame(1, $sub->renewal_period_months);
        $this->assertNotNull($sub->auto_renew_consent_at);
    }

    public function test_user_without_billing_permission_gets_403(): void
    {
        [$owner, $workspace] = $this->createOwnerWithWorkspace();
        $sub = $this->createConsentedActiveSub($workspace);

        $withoutPermission = User::factory()->create(['role' => UserRole::Master]);

        $this->actingAs($withoutPermission)
            ->postJson(route('admin.billing.auto-renew.disable'))
            ->assertForbidden();

        $sub->refresh();
        $this->assertFalse($sub->cancel_at_period_end);
        $this->assertNotNull($sub->next_charge_at);
    }

    public function test_other_workspace_is_untouched(): void
    {
        [$ownerA, $workspaceA] = $this->createOwnerWithWorkspace();
        [$ownerB, $workspaceB] = $this->createOwnerWithWorkspace();

        $subA = $this->createConsentedActiveSub($workspaceA);
        $subB = $this->createConsentedActiveSub($workspaceB);

        $this->actingAs($ownerA)
            ->postJson(route('admin.billing.auto-renew.disable'))
            ->assertOk();

        $subA->refresh();
        $subB->refresh();

        $this->assertTrue($subA->cancel_at_period_end);
        $this->assertNull($subA->next_charge_at);

        $this->assertFalse($subB->cancel_at_period_end);
        $this->assertNotNull($subB->next_charge_at);
        $this->assertSame($ownerB->workspace_id, $subB->workspace_id);
    }

    public function test_disable_without_subscription_returns_404_and_creates_nothing(): void
    {
        [$owner] = $this->createOwnerWithWorkspace();

        $this->actingAs($owner)
            ->postJson(route('admin.billing.auto-renew.disable'))
            ->assertNotFound();

        $this->assertDatabaseCount('billing_subscriptions', 0);

        $noWorkspace = User::factory()->create();

        $this->actingAs($noWorkspace)
            ->postJson(route('admin.billing.auto-renew.disable'))
            ->assertNotFound();

        $this->assertDatabaseCount('billing_subscriptions', 0);
    }

    public function test_index_exposes_auto_renew_state_and_disable_updates_it(): void
    {
        config(['billing.core_entitlement' => true]);

        [$owner, $workspace] = $this->createOwnerWithWorkspace();
        $sub = $this->createConsentedActiveSub($workspace);

        // Granting cycle so the current Pro plan resolves
        BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => now(),
            'period_end' => now()->addMonth(),
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::AdminGrant,
        ]);

        $this->actingAs($owner)
            ->get(route('admin.billing'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing')
                ->where('current.tariff', 'pro')
                ->where('current.is_paid', true)
                ->where('current.auto_renew_enabled', true)
                ->where('current.renewal_period_months', 1)
            );

        $this->actingAs($owner)
            ->postJson(route('admin.billing.auto-renew.disable'))
            ->assertOk();

        $this->actingAs($owner)
            ->get(route('admin.billing'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing')
                ->where('current.auto_renew_enabled', false)
                ->where('current.renewal_period_months', 1)
            );
    }
}
