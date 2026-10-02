<?php

namespace Tests\Feature\Billing;

use App\Enums\PaymentAttemptStatus;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\TBankPaymentGateway;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class TBankPaymentGatewayTest extends TestCase
{
    private const TERMINAL_KEY = 'TestTerminal';

    private const PASSWORD = 'test-password';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.gateways.tbank' => [
                'driver' => 'tbank',
                'terminal_key' => self::TERMINAL_KEY,
                'password' => self::PASSWORD,
                'base_url' => 'https://securepay.tinkoff.ru',
            ],
        ]);
    }

    private function gateway(): TBankPaymentGateway
    {
        return new TBankPaymentGateway(
            terminalKey: self::TERMINAL_KEY,
            password: self::PASSWORD,
            baseUrl: 'https://securepay.tinkoff.ru',
        );
    }

    /**
     * Independent mirror of the T-Bank token algorithm for test assertions.
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

        ksort($scalars);

        return hash('sha256', implode('', $scalars).self::PASSWORD);
    }

    private function fakeInitSuccess(): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => true,
                'PaymentId' => '700123456',
                'PaymentURL' => 'https://securepay.tinkoff.ru/pay?paymentId=700123456',
                'ErrorCode' => '0',
            ], 200),
        ]);
    }

    public function test_manager_registers_tbank_driver(): void
    {
        $manager = new PaymentGatewayManager(app());
        $gateway = $manager->driver('tbank');

        $this->assertInstanceOf(TBankPaymentGateway::class, $gateway);
        $this->assertSame('tbank', $gateway->name());
        $this->assertTrue($manager->hasGateway('tbank'));
        $this->assertSame('mock', config('billing.default_gateway'));
    }

    public function test_get_gateway_returns_tbank_gateway(): void
    {
        $manager = new PaymentGatewayManager(app());

        $gateway = $manager->getGateway('tbank');

        $this->assertInstanceOf(TBankPaymentGateway::class, $gateway);
        $this->assertSame('tbank', $gateway->name());
    }

    public function test_get_gateway_unknown_provider_throws(): void
    {
        $manager = new PaymentGatewayManager(app());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown payment gateway: unknown_provider');

        $manager->getGateway('unknown_provider');
    }

    public function test_init_sends_amount_in_kopecks(): void
    {
        $this->fakeInitSuccess();

        $this->gateway()->createPayment(490, 'RUB', 'order-1', ['workspace_id' => 55]);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://securepay.tinkoff.ru/v2/Init') {
                return false;
            }

            $data = $request->data();

            return $data['Amount'] === 49000
                && $data['TerminalKey'] === self::TERMINAL_KEY
                && $data['OrderId'] === 'order-1'
                && $data['Description'] === 'Подписка ИРСИ'
                && $data['PayType'] === 'O'
                && $data['Recurrent'] === 'Y'
                && $data['CustomerKey'] === '55'
                && $data['DATA'] === ['OperationInitiatorType' => '1']
                && $data['NotificationURL'] === config('app.url').'/webhooks/payment/tbank'
                && $data['SuccessURL'] === config('app.url').'/admin/billing'
                && $data['FailURL'] === config('app.url').'/admin/billing'
                && isset($data['Token'])
                && hash_equals($this->tokenFor($data), $data['Token']);
        });
    }

    public function test_init_returns_payment_initiation(): void
    {
        $this->fakeInitSuccess();

        $initiation = $this->gateway()->createPayment(490, 'RUB', 'order-1');

        $this->assertSame('700123456', $initiation->providerPaymentId);
        $this->assertSame('https://securepay.tinkoff.ru/pay?paymentId=700123456', $initiation->checkoutUrl);
    }

    public function test_invalid_init_response_throws_exception(): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => false,
                'ErrorCode' => '9999',
                'ErrorMessage' => 'terminal not found',
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);

        $this->gateway()->createPayment(490, 'RUB', 'order-1');
    }

    public function test_init_response_without_payment_url_throws_exception(): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => true,
                'PaymentId' => '700123456',
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);

        $this->gateway()->createPayment(490, 'RUB', 'order-1');
    }

    public function test_webhook_valid_token_accepted(): void
    {
        $payload = [
            'TerminalKey' => self::TERMINAL_KEY,
            'PaymentId' => '700123456',
            'OrderId' => 'order-1',
            'Status' => 'CONFIRMED',
            'Amount' => 49000,
        ];
        $payload['Token'] = $this->tokenFor($payload);

        $this->assertTrue($this->gateway()->verifyWebhook($payload, ''));
    }

    public function test_webhook_invalid_token_rejected(): void
    {
        $payload = [
            'TerminalKey' => self::TERMINAL_KEY,
            'PaymentId' => '700123456',
            'OrderId' => 'order-1',
            'Status' => 'CONFIRMED',
            'Amount' => 49000,
            'Token' => 'b1946ac92492d2347c6235b4d2611184b1946ac92492d2347c6235b4d2611184',
        ];

        $this->assertFalse($this->gateway()->verifyWebhook($payload, ''));
    }

    public function test_webhook_missing_token_rejected_fail_closed(): void
    {
        $payload = [
            'TerminalKey' => self::TERMINAL_KEY,
            'PaymentId' => '700123456',
            'OrderId' => 'order-1',
            'Status' => 'CONFIRMED',
            'Amount' => 49000,
        ];

        $this->assertFalse($this->gateway()->verifyWebhook($payload, 'any-signature'));
    }

    public function test_confirmed_maps_to_succeeded(): void
    {
        $update = $this->gateway()->normalizeWebhook([
            'PaymentId' => '700123456',
            'OrderId' => 'order-1',
            'Status' => 'CONFIRMED',
            'Amount' => 49000,
        ]);

        $this->assertSame('tbank', $update->provider);
        $this->assertSame(PaymentAttemptStatus::Succeeded, $update->normalizedStatus);
        $this->assertSame('700123456', $update->providerPaymentId);
        $this->assertSame('order-1', $update->internalOrderId);
        $this->assertSame('RUB', $update->currency);
    }

    public function test_terminal_statuses_mapping(): void
    {
        $gateway = $this->gateway();

        foreach (['REJECTED', 'CANCELED', 'REVERSED'] as $status) {
            $update = $gateway->normalizeWebhook(['Status' => $status]);
            $this->assertSame(PaymentAttemptStatus::FailedTerminal, $update->normalizedStatus, $status);
        }

        $refunded = $gateway->normalizeWebhook(['Status' => 'REFUNDED']);
        $this->assertSame(PaymentAttemptStatus::Refunded, $refunded->normalizedStatus);

        $pending = $gateway->normalizeWebhook(['Status' => 'PENDING']);
        $this->assertSame(PaymentAttemptStatus::Unknown, $pending->normalizedStatus);
    }

    public function test_amount_converted_from_kopecks(): void
    {
        $gateway = $this->gateway();

        $update = $gateway->normalizeWebhook(['Amount' => 49000]);
        $this->assertSame(490, $update->amount);

        $notDivisible = $gateway->normalizeWebhook(['Amount' => 49050]);
        $this->assertNull($notDivisible->amount);

        $missing = $gateway->normalizeWebhook([]);
        $this->assertNull($missing->amount);
    }

    public function test_get_payment_status_via_fake_http(): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => true,
                'PaymentId' => '700123456',
                'OrderId' => 'order-1',
                'Status' => 'CONFIRMED',
                'Amount' => 49000,
            ], 200),
        ]);

        $update = $this->gateway()->getPaymentStatus('700123456', 'order-1');

        $this->assertNotNull($update);
        $this->assertSame('tbank', $update->provider);
        $this->assertSame(PaymentAttemptStatus::Succeeded, $update->normalizedStatus);
        $this->assertSame('700123456', $update->providerPaymentId);
        $this->assertSame('order-1', $update->internalOrderId);
        $this->assertSame(490, $update->amount);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://securepay.tinkoff.ru/v2/GetState') {
                return false;
            }

            $data = $request->data();

            return $data['TerminalKey'] === self::TERMINAL_KEY
                && $data['PaymentId'] === '700123456'
                && isset($data['Token'])
                && hash_equals($this->tokenFor($data), $data['Token']);
        });
    }

    public function test_get_payment_status_returns_null_without_provider_payment_id(): void
    {
        Http::fake();

        $this->assertNull($this->gateway()->getPaymentStatus(null, 'order-1'));
        $this->assertNull($this->gateway()->getPaymentStatus('', 'order-1'));

        Http::assertNothingSent();
    }
}
