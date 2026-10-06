<?php

namespace Tests\Feature\Billing;

use App\Enums\PaymentAttemptStatus;
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
use Tests\TestCase;

/**
 * PR10.1: новый SBP attempt несёт абсолютный срок ссылки (RedirectDueDate).
 *
 * Срок вычисляется на сервере один раз в Phase A ДО HTTP, сохраняется в
 * attempt.metadata.sbp_expires_at, передаётся тем же значением в Init и
 * возвращается в checkout response. Истечение срока НЕ меняет статус,
 * НЕ разрешает новый Init и НЕ отменяет попытку — это только срок ссылки.
 */
class SbpRedirectDueDateTest extends TestCase
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

        // BillingService ходит в реальный T-Bank gateway с Http::fake.
        $this->app->instance(PaymentGatewayInterface::class, new TBankPaymentGateway(
            terminalKey: self::TERMINAL_KEY,
            password: self::PASSWORD,
            baseUrl: 'https://securepay.tinkoff.ru',
        ));
    }

    /**
     * Independent mirror of the official T-Bank token algorithm for tests:
     * scalars only, Password participates in the alphabetical sort.
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

    private function createMasterWithWorkspace(): User
    {
        $master = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'ws-'.$master->id,
            'owner_id' => $master->id,
        ]);
        $workspace->ensureSlug();
        $master->update(['workspace_id' => $workspace->id]);

        return $master;
    }

    private function coreAttempt(): PaymentAttempt
    {
        return PaymentAttempt::where('internal_order_id', 'like', 'core_%')->firstOrFail();
    }

    public function test_sbp_init_redirect_due_date_matches_saved_deadline_and_response(): void
    {
        $this->fakeSbpInit();
        $master = $this->createMasterWithWorkspace();

        $result = app(BillingService::class)->subscribe($master, $this->proPlan, 1, false, 'sbp');

        $expiresAt = $result['sbp_expires_at'];
        $this->assertIsString($expiresAt);

        // Ответ несёт тот дедлайн, что сохранён в Phase A ДО HTTP.
        $attempt = $this->coreAttempt();
        $this->assertSame($expiresAt, $attempt->metadata['sbp_expires_at']);

        // TTL — config billing, default 15 минут; формат абсолютный, с timezone.
        $this->assertSame(15, (int) config('billing.sbp_redirect_ttl_minutes'));
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $expiresAt,
        );
        $this->assertEqualsWithDelta(
            Carbon::now()->addMinutes(15)->getTimestamp(),
            Carbon::parse($expiresAt)->getTimestamp(),
            5,
        );

        // Init уходит с тем же дедлайном, и он участвует в Token.
        Http::assertSentCount(2);
        Http::assertSent(function ($request) use ($expiresAt) {
            if ($request->url() !== 'https://securepay.tinkoff.ru/v2/Init') {
                return false;
            }

            $data = $request->data();

            return ($data['RedirectDueDate'] ?? null) === $expiresAt
                && isset($data['Token'])
                && hash_equals($this->tokenFor($data), $data['Token']);
        });
    }

    public function test_sbp_reuse_keeps_original_deadline_and_never_calls_bank(): void
    {
        $this->fakeSbpInit();
        $master = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        $first = $service->subscribe($master, $this->proPlan, 1, false, 'sbp');
        Http::assertSentCount(2); // Init + GetQr

        // Срок истёк — но это только срок ссылки: статус не меняется,
        // новый Init не разрешается, попытка не отменяется.
        $this->travel(20)->minutes();
        $this->assertTrue(Carbon::parse($first['sbp_expires_at'])->isPast());

        $second = $service->subscribe($master, $this->proPlan, 1, false, 'sbp');

        $this->assertSame($first['payment_id'], $second['payment_id']);
        $this->assertSame($first['sbp_expires_at'], $second['sbp_expires_at']);

        // Reuse не продлевает срок и не вызывает банк.
        Http::assertSentCount(2);

        $attempt = $this->coreAttempt();
        $this->assertSame(PaymentAttemptStatus::Processing, $attempt->status);
        $this->assertSame($first['sbp_expires_at'], $attempt->metadata['sbp_expires_at']);
        $this->assertDatabaseCount('payment_attempts', 1);
    }

    public function test_attempt_without_deadline_stays_without_it_on_reuse(): void
    {
        $this->fakeSbpInit();
        $master = $this->createMasterWithWorkspace();
        $service = app(BillingService::class);

        $service->subscribe($master, $this->proPlan, 1, false, 'sbp');

        // Старый attempt (созданный до этого изменения): срока в metadata нет.
        $attempt = $this->coreAttempt();
        $metadata = $attempt->metadata;
        unset($metadata['sbp_expires_at']);
        $attempt->update(['metadata' => $metadata]);
        Http::assertSentCount(2);

        $reused = $service->subscribe($master, $this->proPlan, 1, false, 'sbp');

        // Без срока — и задним числом он не вычисляется, без нового Init.
        $this->assertNull($reused['sbp_expires_at']);
        Http::assertSentCount(2);

        $attempt->refresh();
        $this->assertArrayNotHasKey('sbp_expires_at', $attempt->metadata);
    }

    public function test_card_init_unchanged_without_redirect_due_date(): void
    {
        $this->fakeCardInit();
        $master = $this->createMasterWithWorkspace();

        $result = app(BillingService::class)->subscribe($master, $this->proPlan, 1, false, 'card');

        $this->assertArrayNotHasKey('sbp_expires_at', $result);

        $attempt = $this->coreAttempt();
        $this->assertArrayNotHasKey('sbp_expires_at', $attempt->metadata);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://securepay.tinkoff.ru/v2/Init') {
                return false;
            }

            $data = $request->data();

            return ! array_key_exists('RedirectDueDate', $data)
                && isset($data['Token'])
                && hash_equals($this->tokenFor($data), $data['Token']);
        });
    }
}
