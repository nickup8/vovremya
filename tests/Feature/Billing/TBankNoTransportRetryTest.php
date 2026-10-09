<?php

namespace Tests\Feature\Billing;

use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\TBankPaymentGateway;
use App\Services\VkApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Transport-level retry policy for T-Bank Init/Charge.
 *
 * Every case below drives the REAL Guzzle retry middleware from
 * AppServiceProvider with an async rejected promise (Guzzle ConnectException)
 * — see MakesFailingHttpTransport for why Http::fake(Http::failedConnection())
 * cannot prove attempt counts.
 */
class TBankNoTransportRetryTest extends TestCase
{
    use MakesFailingHttpTransport;

    private const TERMINAL_KEY = 'TestTerminal';

    private const PASSWORD = 'test-password';

    private const BASE_URL = 'https://securepay.tinkoff.ru';

    /**
     * The production retry budget: decider allows retries 0..4, so a retried
     * request is dispatched 6 times in total.
     */
    private const RETRY_BUDGET_ATTEMPTS = 6;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.gateways.tbank' => [
                'driver' => 'tbank',
                'terminal_key' => self::TERMINAL_KEY,
                'password' => self::PASSWORD,
                'base_url' => self::BASE_URL,
            ],
        ]);
    }

    private function gateway(): TBankPaymentGateway
    {
        return new TBankPaymentGateway(
            terminalKey: self::TERMINAL_KEY,
            password: self::PASSWORD,
            baseUrl: self::BASE_URL,
        );
    }

    // ── Init / Charge: exactly one HTTP attempt ──

    public function test_card_init_transport_failure_is_attempted_exactly_once(): void
    {
        $this->failTransportOnPaths('/v2/Init');

        $caught = null;

        try {
            $this->gateway()->createPayment(490, 'RUB', 'order-1', ['return_attempt_id' => '01923456-789a-7bcd-8def-0123456789ab']);
        } catch (ConnectionException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(ConnectionException::class, $caught, 'Transport failure must surface to the checkout');
        $this->assertSame(1, $this->attemptsFor('/v2/Init'), 'Card Init must be dispatched exactly once');
        $this->assertSame(['/v2/Init'], $this->dispatchedPaths);
    }

    public function test_sbp_init_transport_failure_is_attempted_exactly_once_and_never_getqr(): void
    {
        $this->failTransportOnPaths('/v2/Init');

        $caught = null;

        try {
            $this->gateway()->createPayment(490, 'RUB', 'order-1', ['payment_method' => 'sbp']);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $caught, 'Shared post() failure handling must stay in place');
        $this->assertInstanceOf(HttpClientException::class, $caught->getPrevious(), 'The transport error stays the cause');
        $this->assertSame(1, $this->attemptsFor('/v2/Init'), 'SBP Init must be dispatched exactly once');
        $this->assertSame(0, $this->attemptsFor('/v2/GetQr'), 'GetQr must never be reached after a failed Init');
    }

    public function test_recurring_init_transport_failure_is_attempted_exactly_once(): void
    {
        $this->failTransportOnPaths('/v2/Init');

        $caught = null;

        try {
            $this->gateway()->initRecurringPayment(490, 'RUB', 'renew-order-1');
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertInstanceOf(HttpClientException::class, $caught->getPrevious(), 'The transport error stays the cause');
        $this->assertSame(1, $this->attemptsFor('/v2/Init'), 'Recurring Init must be dispatched exactly once');
        $this->assertSame(['/v2/Init'], $this->dispatchedPaths);
    }

    public function test_charge_transport_failure_is_attempted_exactly_once(): void
    {
        $this->failTransportOnPaths('/v2/Charge');

        $caught = null;

        try {
            $this->gateway()->chargeRecurringPayment('700123456', 'rebill_123');
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $caught, 'Existing Charge failure handling must stay in place');
        $this->assertInstanceOf(HttpClientException::class, $caught->getPrevious(), 'The transport error stays the cause');
        $this->assertSame(1, $this->attemptsFor('/v2/Charge'), 'Charge must be dispatched exactly once');
        $this->assertSame(['/v2/Charge'], $this->dispatchedPaths);
    }

    // ── Everything else keeps the transport retries ──

    public function test_check_order_recovery_keeps_transport_retries(): void
    {
        $this->failTransportOnPaths('/v2/CheckOrder');

        $caught = null;

        try {
            $this->gateway()->findPaymentByOrderId('renew-order-1');
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $caught, 'CheckOrder must still fail closed on transport errors');
        $this->assertSame(
            self::RETRY_BUDGET_ATTEMPTS,
            $this->attemptsFor('/v2/CheckOrder'),
            'CheckOrder (recovery entry point) must keep the original retry budget'
        );
    }

    public function test_other_integration_keeps_transport_retries(): void
    {
        config(['services.vk.bot_token' => 'test-token']);

        $this->failTransportOnPaths('/method/messages.send');

        // VkApiClient swallows transport failures — the attempt count is the
        // observable under test here.
        app(VkApiClient::class)->sendMessage('12345', 'hello');

        $this->assertSame(
            self::RETRY_BUDGET_ATTEMPTS,
            $this->attemptsFor('/method/messages.send'),
            'Non-T-Bank integrations must keep the original retry budget'
        );
    }

    // ── The exception is scoped to the CONFIGURED gateway URLs ──

    public function test_foreign_host_with_tbank_paths_keeps_transport_retries(): void
    {
        $this->failTransportOnPaths('/v2/Init', '/v2/Charge');

        // Same paths as T-Bank, but a foreign origin — must not be excepted.
        $this->postIgnoringFailure('https://evil.example.com/v2/Init');
        $this->postIgnoringFailure('https://evil.example.com/v2/Charge');

        $this->assertSame(self::RETRY_BUDGET_ATTEMPTS, $this->attemptsForUrl('https://evil.example.com/v2/Init'));
        $this->assertSame(self::RETRY_BUDGET_ATTEMPTS, $this->attemptsForUrl('https://evil.example.com/v2/Charge'));
    }

    public function test_configured_tbank_base_url_is_single_attempt(): void
    {
        config(['billing.gateways.tbank' => [
            'driver' => 'tbank',
            'terminal_key' => self::TERMINAL_KEY,
            'password' => self::PASSWORD,
            'base_url' => 'https://acquiring.tbank-test.example',
        ]]);

        $this->failTransportOnPaths('/v2/Init');

        // The manager builds the gateway from the same config the retry
        // decider reads — the whole chain config → gateway → URL is under test.
        $gateway = app(PaymentGatewayManager::class)->getGateway('tbank');
        $this->assertInstanceOf(TBankPaymentGateway::class, $gateway);

        $caught = null;

        try {
            $gateway->initRecurringPayment(490, 'RUB', 'order-1');
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertSame(
            1,
            $this->attemptsForUrl('https://acquiring.tbank-test.example/v2/Init'),
            'The configured gateway base URL must be single-attempt'
        );
    }

    public function test_other_scheme_or_port_is_not_excepted(): void
    {
        $this->failTransportOnPaths('/v2/Init', '/v2/Charge');

        // Same host and paths, but http:// instead of https:// …
        $this->postIgnoringFailure('http://securepay.tinkoff.ru/v2/Init');
        // … and a non-default port.
        $this->postIgnoringFailure('https://securepay.tinkoff.ru:8443/v2/Charge');

        $this->assertSame(self::RETRY_BUDGET_ATTEMPTS, $this->attemptsForUrl('http://securepay.tinkoff.ru/v2/Init'));
        $this->assertSame(self::RETRY_BUDGET_ATTEMPTS, $this->attemptsForUrl('https://securepay.tinkoff.ru:8443/v2/Charge'));

        // An explicit scheme-default port is the SAME origin (effective
        // port). psr7 normalizes ":443" away before the request reaches the
        // retry layer — the configured "https://host" still matches it.
        $this->postIgnoringFailure('https://securepay.tinkoff.ru:443/v2/Init');
        $this->assertSame(1, $this->attemptsForUrl('https://securepay.tinkoff.ru/v2/Init'));
    }

    public function test_base_url_with_path_prefix_is_recognized(): void
    {
        config(['billing.gateways.tbank' => [
            'driver' => 'tbank',
            'terminal_key' => self::TERMINAL_KEY,
            'password' => self::PASSWORD,
            'base_url' => 'https://acquiring.example.com/tbank-proxy/',
        ]]);

        $this->failTransportOnPaths('/v2/Init');

        $gateway = app(PaymentGatewayManager::class)->getGateway('tbank');
        $this->assertInstanceOf(TBankPaymentGateway::class, $gateway);

        $caught = null;

        try {
            $gateway->initRecurringPayment(490, 'RUB', 'order-1');
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertSame(
            1,
            $this->attemptsForUrl('https://acquiring.example.com/tbank-proxy/v2/Init'),
            'A TBANK_BASE_URL path prefix must be part of the recognized URL'
        );

        // Same origin WITHOUT the configured prefix is not a gateway URL.
        $this->postIgnoringFailure('https://acquiring.example.com/v2/Init');
        $this->assertSame(self::RETRY_BUDGET_ATTEMPTS, $this->attemptsForUrl('https://acquiring.example.com/v2/Init'));
    }

    private function postIgnoringFailure(string $url): void
    {
        try {
            Http::post($url, []);
        } catch (ConnectionException) {
            // Expected: the fake transport rejects every attempt; the attempt
            // count is what the assertions below observe.
        }
    }

    // ── Preserved HTTP policy ──

    public function test_timeouts_and_tls_verification_are_preserved(): void
    {
        $options = Http::createPendingRequest()->getOptions();

        $this->assertSame(3, $options['connect_timeout'], 'connect timeout must stay untouched');
        $this->assertSame(20, $options['timeout'], 'response timeout must stay untouched');

        // Guzzle verifies TLS by default; no global option may switch it off.
        $this->assertTrue(Http::createPendingRequest()->buildClient()->getConfig('verify'));
    }
}
