<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\SubscriptionStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\PaymentAttempt;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TariffCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;

    private Workspace $workspace;

    private User $master;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.legacy_mock_webhook_secret' => 'test_cache_inv_secret',
            'billing.core_entitlement' => false,
        ]);

        $this->master = User::factory()->master()->create();
        $this->workspace = Workspace::create([
            'name' => 'ws-'.$this->master->id,
            'owner_id' => $this->master->id,
        ]);
        $this->workspace->ensureSlug();
        $this->master->update(['workspace_id' => $this->workspace->id]);

        $this->proPlan = TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'max_appointments_per_month' => null,
            'max_masters' => 1,
            'features' => ['unlimited_appointments', 'free_windows'],
            'is_active' => true,
        ]);

        PlanPrice::create([
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'base_amount' => 490,
            'discount_percent' => 0,
            'final_amount' => 490,
            'currency' => 'RUB',
            'version' => 1,
            'valid_from' => now(),
            'is_active' => true,
        ]);
    }

    private function createPendingSubscription(string $paymentId): Subscription
    {
        $sub = Subscription::create([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Pending,
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
            'payment_id' => $paymentId,
        ]);

        $billingSub = BillingSubscription::create([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::PendingInitial,
        ]);

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => $sub->starts_at,
            'period_end' => $sub->expires_at,
            'status' => BillingCycleStatus::Pending,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => 'payment',
            'legacy_subscription_id' => $sub->id,
        ]);

        PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'mock',
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'core_cache_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => $paymentId,
            'status' => PaymentAttemptStatus::Processing,
            'initiated_at' => now(),
            'metadata' => ['legacy_subscription_id' => $sub->id],
        ]);

        return $sub;
    }

    private function sendWebhook(array $payload, string $signature = 'test_cache_inv_secret'): TestResponse
    {
        return $this->postJson(route('webhooks.payment'), $payload, [
            'X-Webhook-Signature' => $signature,
        ]);
    }

    // ── 7.1 Payment success invalidates tariff cache ──
    //
    // Expected path: PaymentTransitionService → mirrorToLegacy → legacy
    // subscription update → SubscriptionObserver::saved → Cache::forget.

    public function test_payment_success_invalidates_tariff_cache(): void
    {
        // Create pending structures first: Subscription::create itself fires
        // the observer, so the cache must be written AFTER creation — otherwise
        // the final assertion would pass trivially without the webhook.
        $sub = $this->createPendingSubscription('mock_cache_pay_1');

        $cacheKey = "tariff:{$this->workspace->id}";
        Cache::put($cacheKey, ['code' => 'start', 'name' => 'Старт'], 300);
        $this->assertTrue(Cache::has($cacheKey));

        $response = $this->sendWebhook([
            'payment_id' => 'mock_cache_pay_1',
            'status' => 'succeeded',
            'amount' => 490,
        ]);
        $response->assertOk();

        $sub->refresh();
        $this->assertSame('active', $sub->status);

        $this->assertFalse(Cache::has($cacheKey));
    }

    // ── 7.2 Admin grant invalidates tariff cache ──
    //
    // Goes through the real public action: SuperAdminController::extendSubscription.

    public function test_admin_grant_invalidates_tariff_cache(): void
    {
        $sub = Subscription::create([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDays(10),
            'expires_at' => now()->addDays(10),
        ]);

        $cacheKey = "tariff:{$this->workspace->id}";
        Cache::put($cacheKey, ['code' => 'start', 'name' => 'Старт'], 300);
        $this->assertTrue(Cache::has($cacheKey));

        $before = $sub->expires_at->copy();

        $admin = User::factory()->master()->create(['is_super_admin' => true]);
        $this->actingAs($admin)
            ->post(route('super_admin.extend', $this->master), ['days' => 30]);

        $sub->refresh();
        $this->assertTrue($sub->expires_at->greaterThan($before));
        $this->assertFalse(Cache::has($cacheKey));
    }

    // ── 7.3 Authoritative gates bypass stale cache on expiry ──
    //
    // Known limitation (NOT fixed in this PR): the tariff cache may keep
    // serving a stale "pro" value until its TTL (~300s) expires. Authoritative
    // feature gates and billing presentation must never trust that stale value.

    public function test_authoritative_gates_bypass_stale_cache_on_expiry(): void
    {
        $sub = Subscription::create([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_months' => 1,
            'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addMonth(),
        ]);

        // Sanity: while Pro is active, the paid feature IS granted.
        $this->assertTrue($this->workspace->hasFeature('free_windows'));

        // Stale entry as written by HandleInertiaRequests while Pro was active
        // (full key structure: the middleware reads code/name/max_masters/total/used).
        $cacheKey = "tariff:{$this->workspace->id}";
        Cache::put($cacheKey, [
            'code' => 'pro',
            'name' => 'Профи',
            'max_masters' => 1,
            'total' => null,
            'used' => 0,
        ], 300);

        // Expire the subscription WITHOUT Eloquent model events: this simulates
        // the known stale window where the cache was not invalidated on expiry
        // (observer's Cache::forget swallows failures — TTL ≈300s may remain).
        DB::table('subscriptions')->where('id', $sub->id)->update([
            'status' => 'expired',
            'expires_at' => now()->subDay(),
        ]);

        $this->artisan('subscriptions:check-expirations')->assertOk();

        // Document the known limitation: stale "pro" value is still cached.
        $this->assertSame('pro', Cache::get($cacheKey)['code']);

        // Authoritative gate reads DB, not the stale cache:
        $this->assertFalse($this->workspace->hasFeature('free_windows'));

        // Billing presentation resolves the current plan from authoritative DB:
        $this->actingAs($this->master)
            ->get('/admin/billing')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('current.tariff', 'start')
                ->where('current.is_paid', false));
    }
}
