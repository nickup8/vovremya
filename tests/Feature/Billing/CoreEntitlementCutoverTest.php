<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\SubscriptionStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\PaymentAttempt;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\PlanPrice;
use App\Services\Billing\TariffLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class CoreEntitlementCutoverTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;
    private TariffPlan $startPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startPlan = TariffPlan::create([
            'code' => 'start',
            'name' => 'Старт',
            'price_monthly' => 0,
            'max_appointments_per_month' => 30,
            'max_masters' => 1,
            'features' => ['calendar', 'basic_client_management'],
            'is_active' => true,
        ]);

        $this->proPlan = TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'max_appointments_per_month' => null,
            'max_masters' => 3,
            'features' => ['calendar', 'basic_client_management', 'client_management', 'channel_analytics', 'slot_autofill', 'recurring_appointments'],
            'is_active' => true,
        ]);

        config(['billing.legacy_mock_webhook_secret' => 'test_secret_123']);

        // Create PlanPrice records for pro plan (required for Core mode calculatePrice)
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

    private function createMasterWithWorkspace(): array
    {
        $master = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'ws-'.$master->id,
            'owner_id' => $master->id,
        ]);
        $workspace->ensureSlug();
        $master->update(['workspace_id' => $workspace->id]);

        return [$master, $workspace];
    }

    private function createCoreEntitlement(Workspace $workspace, TariffPlan $plan, string $origin = 'payment', ?string $status = null): void
    {
        $billingSub = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'status' => BillingSubscriptionStatus::Active,
        ]);

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'period_start' => now()->subMonth(),
            'period_end' => now()->addMonth(),
            'status' => $status ?? BillingCycleStatus::Paid,
            'amount' => $plan->price_monthly,
            'currency' => 'RUB',
            'origin' => $origin,
        ]);

        if ($origin === 'payment' || $origin === 'renewal') {
            PaymentAttempt::create([
                'billing_cycle_id' => $cycle->id,
                'provider' => 'mock',
                'attempt_number' => 1,
                'amount' => $plan->price_monthly,
                'currency' => 'RUB',
                'internal_order_id' => 'core_'.uniqid(),
                'status' => PaymentAttemptStatus::Succeeded,
                'initiated_at' => now(),
            ]);
        }
    }

    private function createLegacyEntitlement(Workspace $workspace, TariffPlan $plan): void
    {
        Subscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'period_months' => 1,
            'amount_paid' => $plan->price_monthly,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);
    }

    // ── 1. Workspace Pro feature gate via Core ──

    public function test_workspace_has_feature_pro_via_core(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createCoreEntitlement($workspace, $this->proPlan);

        config(['billing.core_entitlement' => true]);

        $this->assertTrue($workspace->hasFeature('slot_autofill'));
        $this->assertTrue($workspace->hasFeature('channel_analytics'));
        $this->assertTrue($workspace->hasFeature('recurring_appointments'));
    }

    // ── 2. Start fallback via Core ──

    public function test_workspace_has_feature_start_fallback_via_core(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();

        config(['billing.core_entitlement' => true]);

        $this->assertTrue($workspace->hasFeature('calendar'));
        $this->assertTrue($workspace->hasFeature('basic_client_management'));
        $this->assertFalse($workspace->hasFeature('slot_autofill'));
    }

    // ── 3. EnsureHasFeature 403/allow ──

    public function test_ensure_has_feature_403_via_core(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createCoreEntitlement($workspace, $this->startPlan);

        config(['billing.core_entitlement' => true]);

        $this->actingAs($master)->post('/admin/tracking-links')->assertForbidden();
    }

    public function test_ensure_has_feature_allow_via_core(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createCoreEntitlement($workspace, $this->proPlan);

        config(['billing.core_entitlement' => true]);

        $this->actingAs($master)->post('/admin/tracking-links', [
            'name' => 'Test Link',
        ])->assertRedirect();
    }

    // ── 4. AutoFill Core feature ──

    public function test_autofill_feature_gated_by_core(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createCoreEntitlement($workspace, $this->proPlan);
        $master->update(['autofill_enabled' => true]);

        config(['billing.core_entitlement' => true]);

        $this->assertTrue($master->isAutoFillEnabled());
    }

    public function test_autofill_feature_denied_by_core_start(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $master->update(['autofill_enabled' => true]);

        config(['billing.core_entitlement' => true]);

        $this->assertFalse($master->isAutoFillEnabled());
    }

    // ── 5. Recurring feature ──

    public function test_recurring_feature_gated_by_core(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createCoreEntitlement($workspace, $this->proPlan);

        config(['billing.core_entitlement' => true]);

        $this->assertTrue($master->hasFeature('recurring_appointments'));
    }

    // ── 6. maxMasters via Core ──

    public function test_max_masters_via_core(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createCoreEntitlement($workspace, $this->proPlan);

        config(['billing.core_entitlement' => true]);

        $this->assertSame(3, $workspace->maxMasters());
    }

    public function test_max_masters_start_fallback_via_core(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();

        config(['billing.core_entitlement' => true]);

        $this->assertSame(1, $workspace->maxMasters());
    }

    // ── 7. canAddMaster/provider via Core ──

    public function test_can_add_master_via_core(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createCoreEntitlement($workspace, $this->proPlan);

        config(['billing.core_entitlement' => true]);

        $this->assertTrue($workspace->canAddMaster());
        $this->assertTrue($workspace->canAddProvider());
    }

    // ── 8. Monthly appointment limit ──

    public function test_monthly_limit_via_core(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createCoreEntitlement($workspace, $this->startPlan);

        config(['billing.core_entitlement' => true]);

        $service = app(TariffLimitService::class);
        $this->assertSame(30, $service->getMonthlyLimit($workspace));
    }

    // ── 9. Unlimited Core null → PHP_INT_MAX → frontend total=null ──

    public function test_unlimited_core_null_to_frontend_null(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createCoreEntitlement($workspace, $this->proPlan);

        config(['billing.core_entitlement' => true]);

        $service = app(TariffLimitService::class);
        $limit = $service->getMonthlyLimit($workspace);

        $this->assertSame(PHP_INT_MAX, $limit);

        $total = $limit === PHP_INT_MAX ? null : $limit;
        $this->assertNull($total);
    }

    // ── 10. BookingService limit enforcement ──

    public function test_booking_service_limit_enforcement_via_core(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createCoreEntitlement($workspace, $this->startPlan);

        config(['billing.core_entitlement' => true]);

        $service = app(TariffLimitService::class);
        $this->assertTrue($service->canCreateAppointment($workspace));
    }

    // ── 11. User::isSolo with Core plan ──

    public function test_user_is_not_solo_with_core_plan(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createCoreEntitlement($workspace, $this->proPlan);

        config(['billing.core_entitlement' => true]);

        $this->assertFalse($master->isSolo());
    }

    // ── 12. User::isSolo without Core entitlement ──

    public function test_user_is_solo_without_core_entitlement(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();

        config(['billing.core_entitlement' => true]);

        $this->assertTrue($master->isSolo());
    }

    // ── 13. Legacy active but Core absent → flag=true obeys Core ──

    public function test_legacy_active_core_absent_flag_true_obeys_core(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createLegacyEntitlement($workspace, $this->proPlan);

        config(['billing.core_entitlement' => true]);

        $this->assertFalse($workspace->hasFeature('slot_autofill'));
        $this->assertSame(1, $workspace->maxMasters());
    }

    // ── 14. Core active but legacy absent → flag=true obeys Core ──

    public function test_core_active_legacy_absent_flag_true_obeys_core(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createCoreEntitlement($workspace, $this->proPlan);

        config(['billing.core_entitlement' => true]);

        $this->assertTrue($workspace->hasFeature('slot_autofill'));
        $this->assertSame(3, $workspace->maxMasters());
    }

    // ── 15. Inertia tariff_code ──

    public function test_inertia_tariff_code_via_core(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createCoreEntitlement($workspace, $this->proPlan);

        config(['billing.core_entitlement' => true]);

        $this->actingAs($master)
            ->get('/admin/settings')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('auth.user')
                ->where('auth.user.tariff_code', 'pro'));
    }

    // ── 16. Inertia tariff_name ──

    public function test_inertia_tariff_name_via_core(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createCoreEntitlement($workspace, $this->proPlan);

        config(['billing.core_entitlement' => true]);

        $this->actingAs($master)
            ->get('/admin/settings')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.user.tariff_name', 'Профи'));
    }

    // ── 17. Inertia max_masters contract ──

    public function test_inertia_max_masters_via_core(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createCoreEntitlement($workspace, $this->proPlan);

        config(['billing.core_entitlement' => true]);

        $this->actingAs($master)
            ->get('/admin/settings')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tariff_limits'));
    }

    // ── 18. PaymentController current.tariff ──

    public function test_payment_controller_tariff_via_core(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createCoreEntitlement($workspace, $this->proPlan);

        config(['billing.core_entitlement' => true]);

        $this->actingAs($master)
            ->get('/admin/billing')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('current.tariff', 'pro'));
    }

    // ── 19. current.tariff_name ──

    public function test_payment_controller_tariff_name_via_core(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createCoreEntitlement($workspace, $this->proPlan);

        config(['billing.core_entitlement' => true]);

        $this->actingAs($master)
            ->get('/admin/billing')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('current.tariff_name', 'Профи'));
    }

    // ── 20. current.is_paid ──

    public function test_payment_controller_is_paid_via_core(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createCoreEntitlement($workspace, $this->proPlan);

        config(['billing.core_entitlement' => true]);

        $this->actingAs($master)
            ->get('/admin/billing')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('current.is_paid', true));
    }

    // ── 21. current.expires_at ISO ──

    public function test_payment_controller_expires_at_iso_via_core(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createCoreEntitlement($workspace, $this->proPlan);

        config(['billing.core_entitlement' => true]);

        $this->actingAs($master)
            ->get('/admin/billing')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('current.expires_at'));
    }

    // ── 22. current.days_left ──

    public function test_payment_controller_days_left_via_core(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createCoreEntitlement($workspace, $this->proPlan);

        config(['billing.core_entitlement' => true]);

        $this->actingAs($master)
            ->get('/admin/billing')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('current.days_left'));
    }

    // ── 23. CheckAppointmentLimits includes Core-entitled workspace ──

    public function test_check_limits_includes_core_entitled(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createCoreEntitlement($workspace, $this->startPlan);

        config(['billing.core_entitlement' => true]);

        $this->artisan('subscriptions:check-limits')->assertExitCode(0);
    }

    // ── 24. CheckAppointmentLimits excludes legacy-only when flag=true ──

    public function test_check_limits_excludes_legacy_only_when_flag_true(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createLegacyEntitlement($workspace, $this->proPlan);

        config(['billing.core_entitlement' => true]);

        $this->artisan('subscriptions:check-limits')->assertExitCode(0);
    }

    // ── 25. CheckAppointmentLimits legacy behavior when flag=false ──

    public function test_check_limits_legacy_behavior_when_flag_false(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createLegacyEntitlement($workspace, $this->startPlan);

        config(['billing.core_entitlement' => false]);

        $this->artisan('subscriptions:check-limits')->assertExitCode(0);
    }

    // ── 26. Runtime flag rollback true→false ──

    public function test_runtime_flag_rollback_true_to_false(): void
    {
        [$master, $workspace] = $this->createMasterWithWorkspace();
        $this->createCoreEntitlement($workspace, $this->proPlan);
        $this->createLegacyEntitlement($workspace, $this->startPlan);

        config(['billing.core_entitlement' => true]);
        $this->assertTrue($workspace->hasFeature('slot_autofill'));
        $this->assertSame(3, $workspace->maxMasters());

        config(['billing.core_entitlement' => false]);
        $this->assertFalse($workspace->hasFeature('slot_autofill'));
        $this->assertSame(1, $workspace->maxMasters());

        config(['billing.core_entitlement' => true]);
        $this->assertTrue($workspace->hasFeature('slot_autofill'));
        $this->assertSame(3, $workspace->maxMasters());
    }
}
