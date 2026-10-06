<?php

namespace Tests\Feature\Billing;

use App\Enums\PaymentAttemptStatus;
use App\Enums\UserRole;
use App\Models\PaymentAttempt;
use App\Models\PlanPrice;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Payment\PaymentGatewayInterface;
use App\Services\Payment\TBankPaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The bank return routes hand over the attempt named by their own signed
 * SuccessURL/FailURL (bound to the local attempt before Init), so two
 * checkouts in one session can never cross-contaminate each other's return.
 * Returns are verdict-free: they never claim success/failure (Billing Core
 * does), never change payment status, and a legacy unsigned URL, a broken
 * signature or an attempt of another workspace/provider yields the neutral
 * "returned" flash with no id. The ?payment_attempt= URL param keeps the
 * id alive across reloads of the billing page.
 */
class PaymentReturnRouteTest extends TestCase
{
    use RefreshDatabase;

    private TariffPlan $proPlan;

    /**
     * Every faked T-Bank Init in call order: request payload (signed return
     * URLs) and the PaymentId the fake responded with.
     *
     * @var list<array{request: array<string, mixed>, payment_id: string}>
     */
    private array $tbankInits = [];

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

        // Real T-Bank gateway so the Init payload carries the signed return
        // URLs exactly as production does; only the provider HTTP is faked.
        $this->app->instance(
            PaymentGatewayInterface::class,
            new TBankPaymentGateway(terminalKey: 'test-terminal', password: 'test-password'),
        );

        $sequence = 0;
        Http::fake([
            'securepay.tinkoff.ru/*' => function ($request) use (&$sequence) {
                $sequence++;
                $paymentId = '700000'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);

                $this->tbankInits[] = [
                    'request' => $request->data(),
                    'payment_id' => $paymentId,
                ];

                return Http::response([
                    'Success' => true,
                    'PaymentId' => $paymentId,
                    'PaymentURL' => 'https://securepay.tinkoff.ru/pay?paymentId='.$paymentId,
                    'ErrorCode' => '0',
                ], 200);
            },
        ]);
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Owner]);
    }

    private function ownerWithWorkspace(): User
    {
        $owner = $this->owner();
        $workspace = Workspace::create([
            'name' => 'ws-'.$owner->id,
            'owner_id' => $owner->id,
        ]);
        $workspace->ensureSlug();
        $owner->update(['workspace_id' => $workspace->id]);

        return $owner;
    }

    /**
     * Card checkout through the real T-Bank gateway (Init faked).
     *
     * @return array{0: PaymentAttempt, 1: array<string, mixed>} the local attempt and its Init payload
     */
    private function checkoutAs(User $owner): array
    {
        $initCount = count($this->tbankInits);

        $this->actingAs($owner)
            ->postJson('/admin/checkout', [
                'tariff_plan_id' => $this->proPlan->id,
                'period_months' => 1,
                'payment_method' => 'card',
            ])
            ->assertOk();

        $this->assertCount($initCount + 1, $this->tbankInits);

        $init = $this->tbankInits[$initCount];

        $attempt = PaymentAttempt::query()
            ->where('provider', 'tbank')
            ->where('provider_payment_id', $init['payment_id'])
            ->firstOrFail();

        return [$attempt, $init['request']];
    }

    /**
     * Same URL with selected query values replaced — keeps host, path and
     * the remaining params (including the untouched original signature).
     */
    private function withQuery(string $url, array $overrides): string
    {
        $parts = parse_url($url);
        parse_str((string) ($parts['query'] ?? ''), $query);
        $query = array_merge($query, $overrides);

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $parts['scheme'].'://'.$parts['host'].$port.$parts['path'].'?'.http_build_query($query);
    }

    // ── Binding: signed return carries the exact checkout attempt ──

    public function test_card_checkout_signs_return_urls_for_its_attempt(): void
    {
        $owner = $this->owner();
        [$attempt, $init] = $this->checkoutAs($owner);

        foreach (['SuccessURL', 'FailURL'] as $key) {
            $url = $init[$key];
            $this->assertIsString($url);

            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            $this->assertSame((string) $attempt->id, $query['attempt'] ?? null);
            $this->assertNotEmpty($query['signature'] ?? null);
            $this->assertTrue(Request::create($url)->hasValidSignature());
        }
    }

    public function test_card_checkout_binds_attempt_to_success_return(): void
    {
        $owner = $this->owner();
        [$attempt, $init] = $this->checkoutAs($owner);

        $statusBefore = $attempt->status;

        $response = $this->actingAs($owner)->get($init['SuccessURL']);

        $response->assertRedirect('/admin/billing?payment_attempt='.$attempt->provider_payment_id);
        // No verdict flash — the attempt id is the only handover.
        $response->assertSessionMissing('payment_return');

        // The redirect itself never changes payment status.
        $this->assertSame($statusBefore, $attempt->refresh()->status);
    }

    public function test_card_checkout_binds_attempt_to_failed_return(): void
    {
        $owner = $this->owner();
        [$attempt, $init] = $this->checkoutAs($owner);

        // FailURL must behave exactly like SuccessURL: hand over the signed
        // attempt, never claim a decline, never change the status.
        $response = $this->actingAs($owner)->get($init['FailURL']);

        $response->assertRedirect('/admin/billing?payment_attempt='.$attempt->provider_payment_id);
        $response->assertSessionMissing('payment_return');
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->refresh()->status);
    }

    public function test_two_attempts_in_one_session_return_in_reverse_order(): void
    {
        $owner = $this->owner();

        [$attemptA, $initA] = $this->checkoutAs($owner);

        // Attempt A left the in-flight window (bank rejected it) …
        $attemptA->update(['status' => PaymentAttemptStatus::FailedTerminal]);

        // … so the second checkout creates a distinct attempt B.
        [$attemptB, $initB] = $this->checkoutAs($owner);

        $this->assertNotSame($attemptA->id, $attemptB->id);
        $this->assertNotSame($attemptA->provider_payment_id, $attemptB->provider_payment_id);

        // Return from the second tab first …
        $this->actingAs($owner)
            ->get($initB['SuccessURL'])
            ->assertRedirect('/admin/billing?payment_attempt='.$attemptB->provider_payment_id);

        // … then from the first tab: its binding must still be attempt A —
        // the session never selects the payment, the signed URL does.
        $this->actingAs($owner)
            ->get($initA['SuccessURL'])
            ->assertRedirect('/admin/billing?payment_attempt='.$attemptA->provider_payment_id);
    }

    public function test_repeat_return_keeps_the_same_binding(): void
    {
        $owner = $this->owner();
        [$attempt, $init] = $this->checkoutAs($owner);

        $this->actingAs($owner)
            ->get($init['SuccessURL'])
            ->assertRedirect('/admin/billing?payment_attempt='.$attempt->provider_payment_id);

        $this->actingAs($owner)
            ->get($init['SuccessURL'])
            ->assertRedirect('/admin/billing?payment_attempt='.$attempt->provider_payment_id);

        // Repeat visits change nothing: same binding, same status.
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->refresh()->status);
    }

    public function test_inflight_resume_keeps_the_same_return_binding(): void
    {
        $owner = $this->owner();
        [$attempt, $init] = $this->checkoutAs($owner);

        // Double-click resume: same attempt, same checkout URL, no new Init.
        $resume = $this->actingAs($owner)
            ->postJson('/admin/checkout', [
                'tariff_plan_id' => $this->proPlan->id,
                'period_months' => 1,
                'payment_method' => 'card',
            ])
            ->assertOk()
            ->json();

        $this->assertCount(1, $this->tbankInits);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertSame($attempt->refresh()->metadata['checkout_url'], $resume['checkout_url']);

        // The original signed return still binds the resumed attempt.
        $this->actingAs($owner)
            ->get($init['SuccessURL'])
            ->assertRedirect('/admin/billing?payment_attempt='.$attempt->provider_payment_id);
    }

    // ── Negative paths: neutral result, no data disclosure ──

    public function test_tampered_return_signature_is_neutral(): void
    {
        $owner = $this->owner();
        [$attempt, $init] = $this->checkoutAs($owner);

        // Foreign attempt under the original (now mismatching) signature.
        $swapped = $this->withQuery($init['SuccessURL'], [
            'attempt' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
        ]);

        $this->actingAs($owner)
            ->get($swapped)
            ->assertRedirect('/admin/billing')
            ->assertSessionHas('payment_return', 'returned');

        // Corrupted signature for the correct attempt.
        $corrupted = $this->withQuery($init['SuccessURL'], [
            'signature' => str_repeat('a', 64),
        ]);

        $this->actingAs($owner)
            ->get($corrupted)
            ->assertRedirect('/admin/billing')
            ->assertSessionHas('payment_return', 'returned');
    }

    public function test_signed_return_of_foreign_workspace_is_neutral(): void
    {
        $ownerA = $this->owner();
        [, $initA] = $this->checkoutAs($ownerA);

        $ownerB = $this->ownerWithWorkspace();
        $this->assertNotSame($ownerA->fresh()->workspace_id, $ownerB->workspace_id);

        // Valid signature, foreign attempt: signal without id, no verdict,
        // no provider payment id of workspace A ever handed over — the
        // exact-match redirect above proves the Location carries no query.
        $this->actingAs($ownerB)
            ->get($initA['SuccessURL'])
            ->assertRedirect('/admin/billing')
            ->assertSessionHas('payment_return', 'returned');
    }

    public function test_signed_return_for_non_tbank_attempt_is_neutral(): void
    {
        $owner = $this->owner();
        [$attempt, $init] = $this->checkoutAs($owner);
        $attempt->update(['provider' => 'mock']);

        $this->actingAs($owner)
            ->get($init['SuccessURL'])
            ->assertRedirect('/admin/billing')
            ->assertSessionHas('payment_return', 'returned');
    }

    public function test_return_without_binding_is_neutral(): void
    {
        $owner = $this->owner();

        // Legacy URL issued before per-attempt binding (no params, no
        // signature): neutral flash only — never a verdict, never an id.
        $response = $this->actingAs($owner)->get('/admin/billing/payment/success');

        $response->assertRedirect('/admin/billing');
        $response->assertSessionHas('payment_return', 'returned');
    }

    public function test_return_routes_require_auth(): void
    {
        $this->get('/admin/billing/payment/success')->assertRedirect('/login');
        $this->get('/admin/billing/payment/failed')->assertRedirect('/login');
    }

    public function test_return_routes_do_not_mutate_billing_data(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->get('/admin/billing/payment/success');
        $this->actingAs($owner)->get('/admin/billing/payment/failed');

        $this->assertDatabaseCount('billing_subscriptions', 0);
        $this->assertDatabaseCount('billing_cycles', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    // ── Billing page props: signal + id, never a verdict ──

    public function test_index_exposes_neutral_signal_without_attempt(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)
            ->get('/admin/billing/payment/success')
            ->assertRedirect('/admin/billing');

        $this->actingAs($owner)
            ->get('/admin/billing')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing')
                ->where('payment_return', 'returned')
                ->where('payment_attempt_id', null)
            );

        // Signal is one-shot: the next plain visit gets null.
        $this->actingAs($owner)
            ->get('/admin/billing')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing')
                ->where('payment_return', null)
            );
    }

    public function test_index_keeps_attempt_id_across_reloads(): void
    {
        $owner = $this->owner();
        [$attempt, $init] = $this->checkoutAs($owner);

        $this->actingAs($owner)
            ->get($init['SuccessURL'])
            ->assertRedirect('/admin/billing?payment_attempt='.$attempt->provider_payment_id);

        // First visit and a reload both see the id and the signal from the
        // URL — the one-shot flash is not what keeps the dialog alive.
        $this->actingAs($owner)
            ->get('/admin/billing?payment_attempt='.$attempt->provider_payment_id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing')
                ->where('payment_return', 'returned')
                ->where('payment_attempt_id', $attempt->provider_payment_id)
            );

        $this->actingAs($owner)
            ->get('/admin/billing?payment_attempt='.$attempt->provider_payment_id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing')
                ->where('payment_return', 'returned')
                ->where('payment_attempt_id', $attempt->provider_payment_id)
            );
    }

    public function test_index_drops_foreign_attempt_id(): void
    {
        $ownerA = $this->ownerWithWorkspace();
        [$attempt] = $this->checkoutAs($ownerA);

        $ownerB = $this->ownerWithWorkspace();

        // Foreign id: signal stays (the URL says "a return happened") but the
        // id is dropped — no status of another workspace is ever exposed.
        $this->actingAs($ownerB)
            ->get('/admin/billing?payment_attempt='.$attempt->provider_payment_id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing')
                ->where('payment_return', 'returned')
                ->where('payment_attempt_id', null)
            );
    }

    public function test_index_drops_unknown_attempt_id(): void
    {
        $owner = $this->ownerWithWorkspace();

        $this->actingAs($owner)
            ->get('/admin/billing?payment_attempt=tbank_unknown_payment')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing')
                ->where('payment_return', 'returned')
                ->where('payment_attempt_id', null)
            );
    }

    // ── Verification source of truth: local attempt before/after webhook ──

    public function test_return_before_webhook_reports_processing_and_after_webhook_succeeded(): void
    {
        $owner = $this->owner();
        [$attempt, $init] = $this->checkoutAs($owner);

        $this->actingAs($owner)
            ->get($init['SuccessURL'])
            ->assertRedirect('/admin/billing?payment_attempt='.$attempt->provider_payment_id);

        // Before the webhook: still processing → the client keeps polling.
        $this->actingAs($owner)
            ->get('/admin/billing/payment-status/'.$attempt->provider_payment_id)
            ->assertOk()
            ->assertExactJson(['status' => 'processing']);

        // Webhook processed (Billing Core confirmed the payment) …
        $attempt->refresh()->update(['status' => PaymentAttemptStatus::Succeeded]);

        // … and only now the status endpoint reports success.
        $this->actingAs($owner)
            ->get('/admin/billing/payment-status/'.$attempt->provider_payment_id)
            ->assertOk()
            ->assertExactJson(['status' => 'succeeded']);
    }

    public function test_card_status_is_not_readable_from_foreign_workspace(): void
    {
        $ownerA = $this->ownerWithWorkspace();
        [$attempt] = $this->checkoutAs($ownerA);

        $ownerB = $this->ownerWithWorkspace();

        $this->actingAs($ownerB)
            ->get('/admin/billing/payment-status/'.$attempt->provider_payment_id)
            ->assertNotFound();
    }
}
