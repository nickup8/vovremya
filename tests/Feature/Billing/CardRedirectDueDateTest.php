<?php

namespace Tests\Feature\Billing;

use App\Enums\PaymentAttemptStatus;
use App\Enums\UserRole;
use App\Models\PaymentAttempt;
use App\Models\PlanPrice;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use App\Services\Payment\PaymentGatewayInterface;
use App\Services\Payment\TBankPaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Новый карточный срок (RedirectDueDate) checkout-попытки.
 *
 * Срок вычисляется на сервере ОДИН РАЗ в Phase A, после проверки
 * in-flight попытки и до HTTP: сохраняется в attempt.metadata.card_expires_at,
 * тем же скаляром уходит в Init (до Token), возвращается в checkout-ответе
 * и в pending_attempt. Reuse не продлевает срок, старые attempts без ключа
 * его не получают, sbp_expires_at для карты не используется, автопродление
 * и SBP-контракт не меняются.
 */
class CardRedirectDueDateTest extends TestCase
{
    use RefreshDatabase;

    private const TERMINAL_KEY = 'TestTerminal';

    private const PASSWORD = 'test-password';

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

        $this->app->instance(PaymentGatewayInterface::class, new TBankPaymentGateway(
            terminalKey: self::TERMINAL_KEY,
            password: self::PASSWORD,
            baseUrl: 'https://securepay.tinkoff.ru',
        ));
    }

    /**
     * Независимая копия официального алгоритма T-Bank: scalars only,
     * Password участвует в алфавитной сортировке.
     */
    private function tokenFor(array $payload): string
    {
        unset($payload['Token']);

        $scalars = [];
        foreach ($payload as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $scalars[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        $scalars['Password'] = self::PASSWORD;

        ksort($scalars);

        return hash('sha256', implode('', $scalars));
    }

    private function fakeCardInit(): void
    {
        Http::fake([
            'securepay.tinkoff.ru/v2/Init' => Http::response([
                'Success' => true,
                'PaymentId' => '700123456',
                'PaymentURL' => 'https://securepay.tinkoff.ru/pay?paymentId=700123456',
            ], 200),
        ]);
    }

    private function fakeSbpInit(): void
    {
        Http::fake([
            'securepay.tinkoff.ru/v2/Init' => Http::response([
                'Success' => true,
                'PaymentId' => '700123456',
            ], 200),
            'securepay.tinkoff.ru/v2/GetQr' => Http::response([
                'Success' => true,
                'Data' => 'https://qr.nspk.ru/AS10001234567890',
                'RequestKey' => 'req-key-1',
            ], 200),
        ]);
    }

    private function createOwnerWithWorkspace(): User
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $workspace = Workspace::create([
            'name' => 'ws-'.$owner->id,
            'owner_id' => $owner->id,
        ]);
        $workspace->ensureSlug();
        $owner->update(['workspace_id' => $workspace->id]);

        return $owner;
    }

    private function coreAttempt(): PaymentAttempt
    {
        return PaymentAttempt::where('internal_order_id', 'like', 'core_%')->firstOrFail();
    }

    public function test_card_deadline_is_written_once_to_metadata_init_and_response(): void
    {
        $this->fakeCardInit();
        $owner = $this->createOwnerWithWorkspace();

        $result = app(BillingService::class)->subscribe($owner, $this->proPlan, 1, false, 'card');

        $expiresAt = $result['card_expires_at'] ?? null;
        $this->assertIsString($expiresAt);

        // TTL — config billing, default 30 минут; абсолютный ISO8601 с timezone
        $this->assertSame(30, (int) config('billing.card_redirect_ttl_minutes'));
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $expiresAt,
        );
        $this->assertEqualsWithDelta(
            Carbon::now()->addMinutes(30)->getTimestamp(),
            Carbon::parse($expiresAt)->getTimestamp(),
            5,
        );

        // Phase A metadata несёт ТОТ ЖЕ скаляр; sbp-ключ картой не занят
        $attempt = $this->coreAttempt();
        $this->assertSame($expiresAt, $attempt->metadata['card_expires_at']);
        $this->assertArrayNotHasKey('sbp_expires_at', $attempt->metadata);

        // Init уходит с тем же дедлайном И он участвует в Token
        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($expiresAt) {
            if ($request->url() !== 'https://securepay.tinkoff.ru/v2/Init') {
                return false;
            }

            $data = $request->data();

            return ($data['RedirectDueDate'] ?? null) === $expiresAt
                && isset($data['Token'])
                && hash_equals($this->tokenFor($data), $data['Token']);
        });

        // pending_attempt отдаёт тот же срок; GET остаётся read-only
        $this->actingAs($owner)
            ->get('/admin/billing/checkout?period_months=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing-checkout')
                ->where('pending_attempt.method', 'card')
                ->where('pending_attempt.card_expires_at', $expiresAt)
                ->where('pending_attempt.checkout_url', 'https://securepay.tinkoff.ru/pay?paymentId=700123456')
            );
        Http::assertSentCount(1);
    }

    public function test_create_checkout_json_carries_card_expires_at(): void
    {
        $this->fakeCardInit();
        $owner = $this->createOwnerWithWorkspace();

        $this->actingAs($owner)
            ->postJson('/admin/checkout', [
                'tariff_plan_id' => $this->proPlan->id,
                'period_months' => 1,
                'payment_method' => 'card',
            ])
            ->assertOk()
            ->assertJsonPath('payment_method', 'card')
            ->assertJsonPath('checkout_url', 'https://securepay.tinkoff.ru/pay?paymentId=700123456')
            ->assertJsonPath('card_expires_at', $this->coreAttempt()->metadata['card_expires_at']);
    }

    public function test_card_reuse_keeps_original_deadline_and_never_calls_bank(): void
    {
        $this->fakeCardInit();
        $owner = $this->createOwnerWithWorkspace();
        $service = app(BillingService::class);

        $first = $service->subscribe($owner, $this->proPlan, 1, false, 'card');
        Http::assertSentCount(1);

        // Срок истёк — но это только срок ссылки: статус не меняется,
        // новый Init не разрешается, попытка не отменяется.
        $this->travel(40)->minutes();
        $this->assertTrue(Carbon::parse($first['card_expires_at'])->isPast());

        $second = $service->subscribe($owner, $this->proPlan, 1, false, 'card');

        $this->assertSame($first['payment_id'], $second['payment_id']);
        $this->assertSame($first['confirmation_url'], $second['confirmation_url']);
        $this->assertSame($first['card_expires_at'], $second['card_expires_at']);

        // Reuse не продлевает срок и не вызывает банк
        Http::assertSentCount(1);

        $attempt = $this->coreAttempt();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        $this->assertSame($first['card_expires_at'], $attempt->metadata['card_expires_at']);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->travelBack();
    }

    public function test_old_card_attempt_without_deadline_stays_without_it_on_reuse(): void
    {
        $this->fakeCardInit();
        $owner = $this->createOwnerWithWorkspace();
        $service = app(BillingService::class);

        $service->subscribe($owner, $this->proPlan, 1, false, 'card');

        // Старый attempt (созданный до этого изменения): срока в metadata нет
        $attempt = $this->coreAttempt();
        $metadata = $attempt->metadata;
        unset($metadata['card_expires_at']);
        $attempt->update(['metadata' => $metadata]);
        Http::assertSentCount(1);

        $reused = $service->subscribe($owner, $this->proPlan, 1, false, 'card');

        // Без срока — и задним числом он не вычисляется, без нового Init
        $this->assertNull($reused['card_expires_at'] ?? null);
        Http::assertSentCount(1);

        $attempt->refresh();
        $this->assertArrayNotHasKey('card_expires_at', $attempt->metadata);
    }

    public function test_pending_attempt_of_old_card_attempt_has_no_deadline(): void
    {
        $this->fakeCardInit();
        $owner = $this->createOwnerWithWorkspace();
        app(BillingService::class)->subscribe($owner, $this->proPlan, 1, false, 'card');

        $attempt = $this->coreAttempt();
        $metadata = $attempt->metadata;
        unset($metadata['card_expires_at']);
        $attempt->update(['metadata' => $metadata]);

        $this->actingAs($owner)
            ->get('/admin/billing/checkout?period_months=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/billing-checkout')
                ->where('pending_attempt.card_expires_at', null)
            );
    }

    public function test_sbp_checkout_contract_carries_no_card_deadline(): void
    {
        $this->fakeSbpInit();
        $owner = $this->createOwnerWithWorkspace();

        $result = app(BillingService::class)->subscribe($owner, $this->proPlan, 1, false, 'sbp');

        // SBP-контракт прежний: свой срок, без карточного ключа
        $this->assertArrayHasKey('sbp_expires_at', $result);
        $this->assertArrayNotHasKey('card_expires_at', $result);

        $attempt = $this->coreAttempt();
        $this->assertArrayHasKey('sbp_expires_at', $attempt->metadata);
        $this->assertArrayNotHasKey('card_expires_at', $attempt->metadata);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://securepay.tinkoff.ru/v2/Init') {
                return false;
            }

            $data = $request->data();

            // В SBP Init уходит sbp_expires_at, а не карточный срок
            return isset($data['RedirectDueDate'])
                && ! array_key_exists('card_expires_at', $data);
        });
    }

    public function test_recurring_renewal_init_has_no_redirect_due_date(): void
    {
        Http::fake([
            'securepay.tinkoff.ru/v2/Init' => Http::response([
                'Success' => true,
                'PaymentId' => '777000111',
            ], 200),
        ]);

        $gateway = new TBankPaymentGateway(
            terminalKey: self::TERMINAL_KEY,
            password: self::PASSWORD,
            baseUrl: 'https://securepay.tinkoff.ru',
        );

        // Рекуррентный Init автопродления — вне контекста checkout-срока
        $paymentId = $gateway->initRecurringPayment(490, 'RUB', 'core_renewal_'.bin2hex(random_bytes(8)));

        $this->assertSame('777000111', $paymentId);
        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://securepay.tinkoff.ru/v2/Init') {
                return false;
            }

            return ! array_key_exists('RedirectDueDate', $request->data());
        });
    }

    public function test_card_ttl_default_is_inside_the_redirect_due_date_range(): void
    {
        // Допустимый диапазон RedirectDueDate у T-Bank: 1 минута … 90 дней.
        // Дефолт 30 минут лежит внутри него.
        $this->assertSame(30, (int) config('billing.card_redirect_ttl_minutes'));

        $this->assertLessThanOrEqual(129600, (int) config('billing.card_redirect_ttl_minutes'));
        $this->assertGreaterThanOrEqual(1, (int) config('billing.card_redirect_ttl_minutes'));
    }

    public function test_card_ttl_env_values_are_clamped_into_the_allowed_range(): void
    {
        $reloadConfig = function (): array {
            $values = [];

            foreach (['0', '-5', '1000000'] as $envValue) {
                putenv('BILLING_CARD_REDIRECT_TTL_MINUTES='.$envValue);
                $_ENV['BILLING_CARD_REDIRECT_TTL_MINUTES'] = $envValue;
                $_SERVER['BILLING_CARD_REDIRECT_TTL_MINUTES'] = $envValue;

                $loaded = include base_path('config/billing.php');
                $values[$envValue] = $loaded['card_redirect_ttl_minutes'];
            }

            putenv('BILLING_CARD_REDIRECT_TTL_MINUTES');
            unset($_ENV['BILLING_CARD_REDIRECT_TTL_MINUTES'], $_SERVER['BILLING_CARD_REDIRECT_TTL_MINUTES']);

            return $values;
        };

        $values = $reloadConfig();

        // 0 и отрицательное → 1 минута (не в прошлом); > 90 дней → 129600
        $this->assertSame(1, $values['0']);
        $this->assertSame(1, $values['-5']);
        $this->assertSame(129600, $values['1000000']);
    }
}
