<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Models\PlanPrice;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\RenewalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessBillingRenewalsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PAYMENT_ID = '735986544';

    private TariffPlan $proPlan;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.gateways.tbank' => [
                'driver' => 'tbank',
                'terminal_key' => 'TestTerminal',
                'password' => 'test-password',
                'base_url' => 'https://securepay.tinkoff.ru',
            ],
        ]);

        $this->proPlan = TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'max_appointments_per_month' => null,
            'max_masters' => 1,
            'features' => ['unlimited_appointments'],
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
            'valid_from' => now()->subDay(),
            'is_active' => true,
        ]);
    }

    // ── Dry-run ──

    public function test_dry_run_reports_candidates_without_writes_or_http(): void
    {
        $this->dueSubscription($this->createWorkspace());
        Http::fake();

        $this->artisan('billing:process-renewals')
            ->expectsOutputToContain('Candidates: 1')
            ->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(0, BillingCycle::count());
        $this->assertSame(0, PaymentAttempt::count());
    }

    public function test_dry_run_future_next_charge_at_is_zero(): void
    {
        $this->dueSubscription($this->createWorkspace(), [
            'next_charge_at' => now()->addHour(),
        ]);
        Http::fake();

        $this->artisan('billing:process-renewals')
            ->expectsOutputToContain('Candidates: 0')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_dry_run_cancel_at_period_end_is_zero(): void
    {
        $this->dueSubscription($this->createWorkspace(), [
            'cancel_at_period_end' => true,
        ]);
        Http::fake();

        $this->artisan('billing:process-renewals')
            ->expectsOutputToContain('Candidates: 0')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    // ── Execute ──

    public function test_execute_processes_due_subscription(): void
    {
        $workspace = $this->createWorkspace();
        $this->dueSubscription($workspace);
        $this->fakeRecurringHttp();

        $this->artisan('billing:process-renewals --execute')
            ->expectsOutputToContain('Candidates: 1')
            ->expectsOutputToContain('Processed: 1')
            ->expectsOutputToContain('Failed: 0')
            ->assertExitCode(0);

        $attempt = PaymentAttempt::sole();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);

        $cycle = BillingCycle::sole();
        $this->assertSame($workspace->id, $cycle->workspace_id);
        $this->assertSame(BillingCycleStatus::Paid, $cycle->status);
    }

    public function test_subscription_option_targets_only_given_subscription(): void
    {
        $workspaceA = $this->createWorkspace();
        $subA = $this->dueSubscription($workspaceA, ['next_charge_at' => now()->subHours(2)]);

        $workspaceB = $this->createWorkspace();
        $this->dueSubscription($workspaceB, ['next_charge_at' => now()->subHours(1)]);

        $this->fakeRecurringHttp();

        $this->artisan("billing:process-renewals --execute --subscription={$subA->id}")
            ->expectsOutputToContain('Candidates: 1')
            ->expectsOutputToContain('Processed: 1')
            ->assertExitCode(0);

        $attempt = PaymentAttempt::sole();
        $this->assertSame(PaymentAttemptStatus::Succeeded, $attempt->status);

        // Subscription B was untouched.
        $this->assertSame(0, BillingCycle::where('workspace_id', $workspaceB->id)->count());
    }

    public function test_limit_option_processes_only_one(): void
    {
        $workspaceA = $this->createWorkspace();
        $this->dueSubscription($workspaceA, ['next_charge_at' => now()->subHours(2)]);

        $workspaceB = $this->createWorkspace();
        $this->dueSubscription($workspaceB, ['next_charge_at' => now()->subHours(1)]);

        $this->fakeRecurringHttp();

        $this->artisan('billing:process-renewals --execute --limit=1')
            ->expectsOutputToContain('Candidates: 1')
            ->expectsOutputToContain('Processed: 1')
            ->assertExitCode(0);

        // Earliest next_charge_at (A) was processed; B was not.
        $this->assertSame(1, PaymentAttempt::count());
        $this->assertSame(1, BillingCycle::where('workspace_id', $workspaceA->id)->count());
        $this->assertSame(0, BillingCycle::where('workspace_id', $workspaceB->id)->count());
    }

    public function test_failed_subscription_does_not_stop_batch(): void
    {
        $workspaceA = $this->createWorkspace();
        $subA = $this->dueSubscription($workspaceA, [], [
            // Executor guard: empty provider_reference fails closed.
            'provider_reference' => '',
        ]);

        $workspaceB = $this->createWorkspace();
        $this->dueSubscription($workspaceB);

        $this->fakeRecurringHttp();

        $this->artisan('billing:process-renewals --execute')
            ->expectsOutputToContain('Candidates: 2')
            ->expectsOutputToContain('Processed: 1')
            ->expectsOutputToContain('Failed: 1')
            ->assertExitCode(1);

        // Failed subscription: attempt exists but nothing executed.
        $attemptA = PaymentAttempt::whereHas('billingCycle', function ($q) use ($workspaceA) {
            $q->where('workspace_id', $workspaceA->id);
        })->sole();
        $this->assertSame(PaymentAttemptStatus::Created, $attemptA->status);
        $this->assertNull($attemptA->provider_payment_id);

        // Batch continued: subscription B completed.
        $this->assertSame(PaymentAttemptStatus::Succeeded, PaymentAttempt::whereHas('billingCycle', function ($q) use ($workspaceB) {
            $q->where('workspace_id', $workspaceB->id);
        })->sole()->status);

        $this->assertNotNull(BillingSubscription::find($subA->id));
    }

    public function test_marked_inflight_attempt_is_noop_without_http(): void
    {
        $workspace = $this->createWorkspace();
        $sub = $this->dueSubscription($workspace);

        $attempt = app(RenewalService::class)->prepare($sub);
        $this->assertNotNull($attempt);
        $attempt->update([
            'status' => PaymentAttemptStatus::Processing,
            'provider_payment_id' => self::PAYMENT_ID,
            'metadata' => array_merge($attempt->metadata ?? [], [
                'charge_dispatch_started_at' => now()->toISOString(),
            ]),
        ]);

        Http::fake();

        $this->artisan('billing:process-renewals --execute')
            ->expectsOutputToContain('Candidates: 1')
            ->expectsOutputToContain('No-op: 1')
            ->expectsOutputToContain('Processed: 0')
            ->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(1, PaymentAttempt::count());
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
    }

    // ── Technical retry (--retry-technical) ──

    public function test_default_run_ignores_technical_grace_candidates(): void
    {
        $workspace = $this->createWorkspace();
        $this->technicalFailureSubscription($workspace);
        Http::fake();

        $this->artisan('billing:process-renewals')
            ->expectsOutputToContain('Candidates: 0')
            ->assertExitCode(0);

        $this->artisan('billing:process-renewals --execute')
            ->expectsOutputToContain('Candidates: 0')
            ->expectsOutputToContain('Processed: 0')
            ->assertExitCode(0);

        Http::assertNothingSent();
        // Nothing was prepared or charged without the explicit option.
        $this->assertSame(1, PaymentAttempt::count());
        $this->assertSame(
            BillingCycleStatus::Failed,
            BillingCycle::where('origin', BillingCycleOrigin::Renewal)->sole()->status,
        );
    }

    public function test_retry_technical_dry_run_reports_candidate_without_writes_or_http(): void
    {
        $workspace = $this->createWorkspace();
        $this->technicalFailureSubscription($workspace);
        Http::fake();

        $this->artisan('billing:process-renewals --retry-technical')
            ->expectsOutputToContain('Candidates: 1')
            ->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(1, PaymentAttempt::count());
        $this->assertSame(
            BillingCycleStatus::Failed,
            BillingCycle::where('origin', BillingCycleOrigin::Renewal)->sole()->status,
        );
        $this->assertSame(
            BillingSubscriptionStatus::PastDue,
            BillingSubscription::sole()->status,
        );
    }

    public function test_retry_technical_execute_charges_a_new_attempt_via_existing_executor(): void
    {
        $workspace = $this->createWorkspace();
        $sub = $this->technicalFailureSubscription($workspace);
        $this->fakeRecurringHttp();

        $this->artisan('billing:process-renewals --retry-technical --execute')
            ->expectsOutputToContain('Candidates: 1')
            ->expectsOutputToContain('Processed: 1')
            ->expectsOutputToContain('Failed: 0')
            ->assertExitCode(0);

        $this->assertSame(2, PaymentAttempt::count());

        $first = PaymentAttempt::where('attempt_number', 1)->sole();
        $retry = PaymentAttempt::where('attempt_number', 2)->sole();

        // The old failed attempt was never re-Charged and stays terminal.
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $first->status);
        $this->assertNull($first->metadata['technical_retry'] ?? null);
        $this->assertStringStartsWith('renew_', $retry->internal_order_id);
        $this->assertNotSame($first->internal_order_id, $retry->internal_order_id);
        $this->assertTrue($retry->metadata['renewal'] ?? false);
        $this->assertTrue($retry->metadata['technical_retry'] ?? false);
        $this->assertSame($first->id, $retry->metadata['retry_of_attempt_id']);

        // The retry ran through the ordinary executor: own dispatch marker.
        $this->assertSame(PaymentAttemptStatus::Succeeded, $retry->status);
        $this->assertNotNull($retry->metadata['charge_dispatch_started_at'] ?? null);

        $cycle = BillingCycle::where('origin', BillingCycleOrigin::Renewal)->sole();
        $this->assertSame(BillingCycleStatus::Paid, $cycle->status);

        $sub->refresh();
        $this->assertSame(BillingSubscriptionStatus::Active, $sub->status);
        $this->assertNull($sub->grace_until);
        $this->assertTrue($sub->current_period_end->equalTo($cycle->period_end));
        $this->assertNotNull($sub->next_charge_at);
        $this->assertTrue($sub->next_charge_at->equalTo($sub->current_period_end));
    }

    public function test_retry_technical_rerun_does_not_create_a_duplicate_attempt(): void
    {
        $workspace = $this->createWorkspace();
        // Executor guard: empty provider_reference fails closed after prepare.
        $sub = $this->technicalFailureSubscription($workspace, [], [
            'provider_reference' => '',
        ]);
        $this->fakeRecurringHttp();

        $this->artisan('billing:process-renewals --retry-technical --execute')
            ->expectsOutputToContain('Candidates: 1')
            ->expectsOutputToContain('Failed: 1')
            ->assertExitCode(1);

        $this->assertSame(2, PaymentAttempt::count());
        $this->assertSame(
            PaymentAttemptStatus::Created,
            PaymentAttempt::where('attempt_number', 2)->sole()->status,
        );

        // Second run: the existing retry attempt is reused, never duplicated.
        Http::fake();

        $this->artisan('billing:process-renewals --retry-technical --execute')
            ->expectsOutputToContain('Candidates: 1')
            ->expectsOutputToContain('Skipped: 1')
            ->expectsOutputToContain('Processed: 0')
            ->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(2, PaymentAttempt::count());
        $this->assertSame(2, BillingCycle::count());
        $sub->refresh();
        $this->assertSame(BillingSubscriptionStatus::PastDue, $sub->status);
        $this->assertNotNull($sub->grace_until);
    }

    public function test_retry_technical_respects_subscription_option(): void
    {
        $workspaceA = $this->createWorkspace();
        $subA = $this->technicalFailureSubscription($workspaceA);

        $workspaceB = $this->createWorkspace();
        $subB = $this->technicalFailureSubscription($workspaceB);

        $this->fakeRecurringHttp();

        $this->artisan("billing:process-renewals --retry-technical --execute --subscription={$subA->id}")
            ->expectsOutputToContain('Candidates: 1')
            ->expectsOutputToContain('Processed: 1')
            ->assertExitCode(0);

        $this->assertSame(3, PaymentAttempt::count());
        $this->assertSame(1, BillingCycle::where('workspace_id', $workspaceA->id)
            ->where('origin', BillingCycleOrigin::Renewal)
            ->where('status', BillingCycleStatus::Paid)->count());

        // Subscription B was untouched.
        $subB->refresh();
        $this->assertSame(BillingSubscriptionStatus::PastDue, $subB->status);
        $this->assertSame(1, PaymentAttempt::whereHas('billingCycle', function ($q) use ($workspaceB) {
            $q->where('workspace_id', $workspaceB->id);
        })->count());
    }

    public function test_retry_technical_respects_limit_option(): void
    {
        $workspaceA = $this->createWorkspace();
        $this->technicalFailureSubscription($workspaceA, [
            'grace_until' => now()->addDay(),
        ]);

        $workspaceB = $this->createWorkspace();
        $this->technicalFailureSubscription($workspaceB, [
            'grace_until' => now()->addDays(2),
        ]);

        $this->fakeRecurringHttp();

        $this->artisan('billing:process-renewals --retry-technical --execute --limit=1')
            ->expectsOutputToContain('Candidates: 1')
            ->expectsOutputToContain('Processed: 1')
            ->assertExitCode(0);

        // Earliest grace window (A) was retried; B still has only attempt #1.
        $this->assertSame(3, PaymentAttempt::count());
        $this->assertSame(1, PaymentAttempt::whereHas('billingCycle', function ($q) use ($workspaceB) {
            $q->where('workspace_id', $workspaceB->id);
        })->count());
    }

    // ── Helpers ──

    private function createWorkspace(): Workspace
    {
        $master = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'ws-'.$master->id,
            'owner_id' => $master->id,
        ]);
        $workspace->ensureSlug();
        $master->update(['workspace_id' => $workspace->id]);

        return $workspace;
    }

    private function dueSubscription(
        Workspace $workspace,
        array $overrides = [],
        array $methodOverrides = [],
    ): BillingSubscription {
        $periodEnd = now()->subHour();

        $sub = BillingSubscription::create(array_merge([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::Active,
            'renewal_period_months' => 1,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => $periodEnd,
            'next_charge_at' => $periodEnd,
            'cancel_at_period_end' => false,
            'auto_renew_consent_at' => now()->subDays(2),
            'auto_renew_consent_version' => config('billing.recurring_terms_version'),
        ], $overrides));

        PaymentMethod::create(array_merge([
            'workspace_id' => $workspace->id,
            'provider' => 'tbank',
            'type' => 'card',
            'provider_reference' => 'rebill_'.bin2hex(random_bytes(8)),
            'status' => 'active',
            'is_default' => true,
        ], $methodOverrides));

        return $sub;
    }

    /**
     * PastDue subscription with an open technical grace window: paid
     * granting cycle + failed renewal cycle + one terminal technical
     * failure attempt + active default payment method.
     */
    private function technicalFailureSubscription(
        Workspace $workspace,
        array $overrides = [],
        array $methodOverrides = [],
    ): BillingSubscription {
        $periodEnd = now()->subDay();

        $sub = BillingSubscription::create(array_merge([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'status' => BillingSubscriptionStatus::PastDue,
            'renewal_period_months' => 1,
            'current_period_start' => $periodEnd->copy()->subMonth(),
            'current_period_end' => $periodEnd,
            'next_charge_at' => null,
            'cancel_at_period_end' => false,
            'auto_renew_consent_at' => now()->subDays(2),
            'auto_renew_consent_version' => config('billing.recurring_terms_version'),
            'grace_until' => now()->addDays(3),
        ], $overrides));

        BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => $periodEnd->copy()->subMonth(),
            'period_end' => $periodEnd,
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::LegacyGrant,
        ]);

        $renewalCycle = BillingCycle::create([
            'billing_subscription_id' => $sub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $this->proPlan->id,
            'period_start' => $periodEnd,
            'period_end' => $periodEnd->copy()->addMonth(),
            'status' => BillingCycleStatus::Failed,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Renewal,
        ]);

        $method = PaymentMethod::create(array_merge([
            'workspace_id' => $workspace->id,
            'provider' => 'tbank',
            'type' => 'card',
            'provider_reference' => 'rebill_'.bin2hex(random_bytes(8)),
            'status' => 'active',
            'is_default' => true,
        ], $methodOverrides));

        PaymentAttempt::create([
            'billing_cycle_id' => $renewalCycle->id,
            'payment_method_id' => $method->id,
            'provider' => 'tbank',
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'renew_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => 'tbank_'.bin2hex(random_bytes(8)),
            'status' => PaymentAttemptStatus::FailedTerminal,
            'failure_code' => '9999',
            'failure_category' => 'reconciliation_timeout',
            'failure_message' => 'Не удалось подтвердить статус платежа',
            'initiated_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(30),
            'metadata' => [
                'renewal' => true,
                'billing_subscription_id' => $sub->id,
            ],
        ]);

        return $sub;
    }

    private function fakeRecurringHttp(): void
    {
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/v2/Charge')) {
                return Http::response([
                    'Success' => true,
                    'PaymentId' => self::PAYMENT_ID,
                    'Status' => 'CONFIRMED',
                    'Amount' => 49000,
                ], 200);
            }

            if (str_ends_with($request->url(), '/v2/Init')) {
                return Http::response([
                    'Success' => true,
                    'PaymentId' => self::PAYMENT_ID,
                ], 200);
            }

            // CheckOrder — no payment yet
            return Http::response(['Success' => true, 'Payments' => []], 200);
        });
    }
}
