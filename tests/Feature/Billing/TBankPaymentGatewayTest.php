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

    private function fakeChargeResponse(string $status): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => true,
                'PaymentId' => '700123456',
                'OrderId' => 'renew_order_1',
                'Status' => $status,
                'Amount' => 49000,
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

        $this->gateway()->createPayment(490, 'RUB', 'order-1', ['workspace_id' => 55, 'auto_renew' => true]);

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
                && $data['SuccessURL'] === config('app.url').'/admin/billing/payment/success'
                && $data['FailURL'] === config('app.url').'/admin/billing/payment/failed'
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

    public function test_init_without_auto_renew_is_not_recurrent(): void
    {
        $this->fakeInitSuccess();

        // auto_renew отсутствует
        $this->gateway()->createPayment(490, 'RUB', 'order_a', ['workspace_id' => 55]);
        // auto_renew=false
        $this->gateway()->createPayment(490, 'RUB', 'order_b', ['workspace_id' => 55, 'auto_renew' => false]);

        Http::assertSentCount(2);

        foreach (Http::recorded() as [$request]) {
            $data = $request->data();

            $this->assertSame(49000, $data['Amount']);
            $this->assertArrayNotHasKey('Recurrent', $data);
            $this->assertArrayNotHasKey('CustomerKey', $data);
            $this->assertSame(['OperationInitiatorType' => '0'], $data['DATA']);
        }
    }

    public function test_init_with_auto_renew_true_is_recurrent(): void
    {
        $this->fakeInitSuccess();

        $this->gateway()->createPayment(490, 'RUB', 'order_recurrent', ['workspace_id' => 55, 'auto_renew' => true]);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $data['Recurrent'] === 'Y'
                && $data['CustomerKey'] === '55'
                && $data['DATA'] === ['OperationInitiatorType' => '1'];
        });
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

    // ── Recurring Init (renewal card) ──

    public function test_recurring_init_sends_expected_payload(): void
    {
        $this->fakeInitSuccess();

        $this->gateway()->initRecurringPayment(490, 'RUB', 'renew_order_1');

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://securepay.tinkoff.ru/v2/Init') {
                return false;
            }

            $data = $request->data();

            return $data['Amount'] === 49000
                && $data['TerminalKey'] === self::TERMINAL_KEY
                && $data['OrderId'] === 'renew_order_1'
                && $data['Description'] === 'Продление подписки ИРСИ'
                && $data['PayType'] === 'O'
                && $data['DATA'] === ['OperationInitiatorType' => 'R']
                && $data['NotificationURL'] === config('app.url').'/webhooks/payment/tbank'
                && ! array_key_exists('Recurrent', $data)
                && ! array_key_exists('CustomerKey', $data)
                && ! array_key_exists('SuccessURL', $data)
                && ! array_key_exists('FailURL', $data)
                && isset($data['Token'])
                && hash_equals($this->tokenFor($data), $data['Token']);
        });
    }

    public function test_recurring_init_returns_payment_id(): void
    {
        $this->fakeInitSuccess();

        $paymentId = $this->gateway()->initRecurringPayment(490, 'RUB', 'renew_order_2');

        $this->assertSame('700123456', $paymentId);
    }

    public function test_recurring_init_does_not_require_payment_url(): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => true,
                'PaymentId' => '700123456',
            ], 200),
        ]);

        $paymentId = $this->gateway()->initRecurringPayment(490, 'RUB', 'renew_order_3');

        $this->assertSame('700123456', $paymentId);
    }

    public function test_unsuccessful_recurring_init_throws_exception(): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => false,
                'ErrorCode' => '9999',
                'ErrorMessage' => 'card not found',
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);

        $this->gateway()->initRecurringPayment(490, 'RUB', 'renew_order_4');
    }

    // ── Charge (recurring card) ──

    public function test_charge_sends_expected_payload(): void
    {
        $this->fakeChargeResponse('CONFIRMED');

        $this->gateway()->chargeRecurringPayment('700123456', 'rebill_123');

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://securepay.tinkoff.ru/v2/Charge') {
                return false;
            }

            $data = $request->data();

            return $data['TerminalKey'] === self::TERMINAL_KEY
                && $data['PaymentId'] === '700123456'
                && $data['RebillId'] === 'rebill_123'
                && ! array_key_exists('Amount', $data)
                && isset($data['Token'])
                && hash_equals($this->tokenFor($data), $data['Token']);
        });
    }

    public function test_charge_confirmed_returns_succeeded_update(): void
    {
        $this->fakeChargeResponse('CONFIRMED');

        $update = $this->gateway()->chargeRecurringPayment('700123456', 'rebill_123');

        $this->assertSame('tbank', $update->provider);
        $this->assertSame(PaymentAttemptStatus::Succeeded, $update->normalizedStatus);
        $this->assertSame('700123456', $update->providerPaymentId);
        $this->assertSame(490, $update->amount);
        $this->assertSame('RUB', $update->currency);
    }

    public function test_charge_authorized_returns_processing_update(): void
    {
        $this->fakeChargeResponse('AUTHORIZED');

        $update = $this->gateway()->chargeRecurringPayment('700123456', 'rebill_123');

        $this->assertSame(PaymentAttemptStatus::Processing, $update->normalizedStatus);
        $this->assertSame('700123456', $update->providerPaymentId);
    }

    public function test_unsuccessful_charge_throws_exception(): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => false,
                'ErrorCode' => '5106',
                'ErrorMessage' => 'card blocked',
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);

        $this->gateway()->chargeRecurringPayment('700123456', 'rebill_123');
    }

    public function test_charge_error_code_10_still_throws_exception(): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => false,
                'ErrorCode' => '10',
                'ErrorMessage' => 'request is incorrect',
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);

        $this->gateway()->chargeRecurringPayment('700123456', 'rebill_123');
    }

    public function test_charge_non_2xx_still_throws_exception(): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response('Server Error', 500),
        ]);

        $this->expectException(RuntimeException::class);

        $this->gateway()->chargeRecurringPayment('700123456', 'rebill_123');
    }

    public function test_charge_invalid_json_still_throws_exception(): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response('<html>not json</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->expectException(RuntimeException::class);

        $this->gateway()->chargeRecurringPayment('700123456', 'rebill_123');
    }

    public function test_charge_transport_error_still_throws_exception(): void
    {
        Http::fake(Http::failedConnection());

        $this->expectException(RuntimeException::class);

        $this->gateway()->chargeRecurringPayment('700123456', 'rebill_123');
    }

    // ── Charge: insufficient funds classification ──

    public function test_charge_insufficient_funds_103_returns_failed_terminal(): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => false,
                'ErrorCode' => '103',
                'Message' => 'Недостаточно средств на карте',
                'PaymentId' => '700123456',
                'OrderId' => 'renew_order_1',
                'Amount' => 49000,
            ], 200),
        ]);

        $update = $this->gateway()->chargeRecurringPayment('700123456', 'rebill_123');

        $this->assertSame('tbank', $update->provider);
        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $update->normalizedStatus);
        $this->assertSame('700123456', $update->providerPaymentId);
        $this->assertSame('renew_order_1', $update->internalOrderId);
        $this->assertSame('103', $update->failureCode);
        $this->assertSame('insufficient_funds', $update->failureCategory);
        $this->assertSame('Недостаточно средств на карте', $update->failureMessage);
        $this->assertSame('103', $update->raw['ErrorCode']);
        $this->assertSame(490, $update->amount);
    }

    public function test_charge_insufficient_funds_116_returns_failed_terminal(): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => false,
                'ErrorCode' => '116',
                'Details' => 'Недостаточно средств',
                'PaymentId' => '700123456',
            ], 200),
        ]);

        $update = $this->gateway()->chargeRecurringPayment('700123456', 'rebill_123');

        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $update->normalizedStatus);
        $this->assertSame('116', $update->failureCode);
        $this->assertSame('insufficient_funds', $update->failureCategory);
        $this->assertSame('Недостаточно средств', $update->failureMessage);
    }

    public function test_charge_insufficient_funds_1051_returns_failed_terminal(): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => false,
                'ErrorCode' => '1051',
                'Message' => 'Недостаточно средств на счете',
            ], 200),
        ]);

        $update = $this->gateway()->chargeRecurringPayment('700123456', 'rebill_123');

        $this->assertSame(PaymentAttemptStatus::FailedTerminal, $update->normalizedStatus);
        $this->assertSame('1051', $update->failureCode);
        $this->assertSame('insufficient_funds', $update->failureCategory);
        $this->assertSame('Недостаточно средств на счете', $update->failureMessage);
    }

    public function test_charge_insufficient_funds_falls_back_to_input_payment_id(): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => false,
                'ErrorCode' => '103',
                'Message' => 'Недостаточно средств',
                // No PaymentId in the response
            ], 200),
        ]);

        $update = $this->gateway()->chargeRecurringPayment('input_payment_id', 'rebill_123');

        $this->assertSame('input_payment_id', $update->providerPaymentId);
        $this->assertSame('insufficient_funds', $update->failureCategory);
    }

    // ── CheckOrder (recovery) ──

    public function test_check_order_empty_payments_returns_null(): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => true,
                'Payments' => [],
            ], 200),
        ]);

        $this->assertNull($this->gateway()->findPaymentByOrderId('renew_order_1'));

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://securepay.tinkoff.ru/v2/CheckOrder') {
                return false;
            }

            $data = $request->data();

            return $data['TerminalKey'] === self::TERMINAL_KEY
                && $data['OrderId'] === 'renew_order_1'
                && isset($data['Token'])
                && hash_equals($this->tokenFor($data), $data['Token']);
        });
    }

    public function test_check_order_single_new_payment_returns_update(): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => true,
                'Payments' => [
                    [
                        'PaymentId' => '700123456',
                        'OrderId' => 'renew_order_2',
                        'Status' => 'NEW',
                        'Amount' => 49000,
                    ],
                ],
            ], 200),
        ]);

        $update = $this->gateway()->findPaymentByOrderId('renew_order_2');

        $this->assertNotNull($update);
        $this->assertSame('tbank', $update->provider);
        $this->assertSame('700123456', $update->providerPaymentId);
        $this->assertSame('renew_order_2', $update->internalOrderId);
        $this->assertSame(PaymentAttemptStatus::Unknown, $update->normalizedStatus);
        $this->assertSame('NEW', $update->raw['Status']);
    }

    public function test_check_order_confirmed_returns_succeeded_with_amount_conversion(): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => true,
                'Payments' => [
                    [
                        'PaymentId' => '700123456',
                        'Status' => 'CONFIRMED',
                        'Amount' => 49000,
                        // No OrderId — must be injected from the request argument
                    ],
                ],
            ], 200),
        ]);

        $update = $this->gateway()->findPaymentByOrderId('renew_order_3');

        $this->assertNotNull($update);
        $this->assertSame(PaymentAttemptStatus::Succeeded, $update->normalizedStatus);
        $this->assertSame('700123456', $update->providerPaymentId);
        $this->assertSame('renew_order_3', $update->internalOrderId);
        $this->assertSame(490, $update->amount);
        $this->assertSame('RUB', $update->currency);
    }

    public function test_check_order_multiple_payment_ids_throws(): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => true,
                'Payments' => [
                    ['PaymentId' => '111111111', 'Status' => 'NEW'],
                    ['PaymentId' => '222222222', 'Status' => 'NEW'],
                ],
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);

        $this->gateway()->findPaymentByOrderId('renew_order_4');
    }

    public function test_check_order_unsuccessful_throws(): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => false,
                'ErrorCode' => '9999',
                'ErrorMessage' => 'order not found',
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);

        $this->gateway()->findPaymentByOrderId('renew_order_5');
    }

    public function test_check_order_error_code_335_returns_null_for_missing_order(): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => false,
                'ErrorCode' => '335',
                'ErrorMessage' => 'Order not found',
            ], 200),
        ]);

        $this->assertNull($this->gateway()->findPaymentByOrderId('renew_order_fresh'));
    }

    public function test_check_order_error_code_335_as_integer_returns_null(): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response([
                'Success' => false,
                'ErrorCode' => 335,
            ], 200),
        ]);

        $this->assertNull($this->gateway()->findPaymentByOrderId('renew_order_fresh_int'));
    }

    public function test_check_order_non_2xx_throws(): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response('Server Error', 500),
        ]);

        $this->expectException(RuntimeException::class);

        $this->gateway()->findPaymentByOrderId('renew_order_6');
    }

    public function test_check_order_invalid_json_throws(): void
    {
        Http::fake([
            'securepay.tinkoff.ru/*' => Http::response('<html>not json</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->expectException(RuntimeException::class);

        $this->gateway()->findPaymentByOrderId('renew_order_7');
    }

    // ── Token signing (official T-Bank algorithm) ──

    public function test_official_webhook_token_example(): void
    {
        // Official T-Bank docs example: Password must participate in the
        // alphabetical sort, NOT be appended after it.
        $gateway = new TBankPaymentGateway(
            terminalKey: '1234567890DEMO',
            password: '11111111111',
            baseUrl: 'https://securepay.tinkoff.ru',
        );

        $payload = [
            'TerminalKey' => '1234567890DEMO',
            'OrderId' => '000000',
            'Success' => true,
            'Status' => 'AUTHORIZED',
            'PaymentId' => '0000000',
            'ErrorCode' => 0,
            'Amount' => 1111,
            'CardId' => '000000',
            'Pan' => '200000******0000',
            'ExpDate' => '1111',
            'RebillId' => '000000',
            'Token' => '1c0964277d0213349243065a0d5b838b8e90d2d25f740d0f2767836e710e80c8',
        ];

        $this->assertTrue($gateway->verifyWebhook($payload, ''));
    }

    public function test_outgoing_token_signing_never_leaks_password(): void
    {
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/v2/Init')) {
                return Http::response([
                    'Success' => true,
                    'PaymentId' => '700123456',
                    'PaymentURL' => 'https://securepay.tinkoff.ru/pay?paymentId=700123456',
                ], 200);
            }

            // CheckOrder and everything else
            return Http::response([
                'Success' => true,
                'Payments' => [],
            ], 200);
        });

        $this->gateway()->findPaymentByOrderId('renew_order_sign');

        // CheckOrder: Token signed with Password inside the alphabetical sort,
        // Password itself never appears in the HTTP payload.
        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/v2/CheckOrder')) {
                return false;
            }

            $data = $request->data();

            return isset($data['Token'])
                && ! array_key_exists('Password', $data)
                && hash_equals($this->tokenFor($data), $data['Token']);
        });

        $this->gateway()->createPayment(490, 'RUB', 'order_sign', ['auto_renew' => true]);

        // Init: nested DATA stays excluded from the signature (the mirror
        // skips arrays — a matching Token proves production does too).
        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/v2/Init')) {
                return false;
            }

            $data = $request->data();

            return isset($data['Token'])
                && ! array_key_exists('Password', $data)
                && isset($data['DATA'])
                && hash_equals($this->tokenFor($data), $data['Token']);
        });
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

    public function test_authorized_maps_to_processing(): void
    {
        $update = $this->gateway()->normalizeWebhook(['Status' => 'AUTHORIZED']);

        $this->assertSame(PaymentAttemptStatus::Processing, $update->normalizedStatus);
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
