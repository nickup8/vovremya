<?php

namespace Tests\Unit\Services;

use App\Services\Payment\DTOs\PaymentInitiation;
use InvalidArgumentException;
use Tests\TestCase;

class PaymentInitiationTest extends TestCase
{
    public function test_redirect_with_checkout_url_is_valid(): void
    {
        $initiation = new PaymentInitiation(
            providerPaymentId: '700123456',
            method: 'redirect',
            checkoutUrl: 'https://securepay.tinkoff.ru/pay?paymentId=700123456',
        );

        $this->assertSame('700123456', $initiation->providerPaymentId);
        $this->assertSame('redirect', $initiation->method);
        $this->assertSame('https://securepay.tinkoff.ru/pay?paymentId=700123456', $initiation->checkoutUrl);
        $this->assertNull($initiation->payload);
        $this->assertSame([], $initiation->metadata);
    }

    public function test_redirect_without_checkout_url_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PaymentInitiation(
            providerPaymentId: '700123456',
            method: 'redirect',
        );
    }

    public function test_redirect_with_empty_checkout_url_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PaymentInitiation(
            providerPaymentId: '700123456',
            method: 'redirect',
            checkoutUrl: '',
        );
    }

    public function test_sbp_with_payload_is_valid(): void
    {
        $initiation = new PaymentInitiation(
            providerPaymentId: '700123456',
            method: 'sbp',
            payload: 'https://qr.nspk.ru/AS10001234567890',
            metadata: ['bank' => 'tbank'],
        );

        $this->assertSame('sbp', $initiation->method);
        $this->assertSame('https://qr.nspk.ru/AS10001234567890', $initiation->payload);
        $this->assertNull($initiation->checkoutUrl);
        $this->assertSame(['bank' => 'tbank'], $initiation->metadata);
    }

    public function test_sbp_without_payload_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PaymentInitiation(
            providerPaymentId: '700123456',
            method: 'sbp',
        );
    }

    public function test_sbp_with_empty_payload_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PaymentInitiation(
            providerPaymentId: '700123456',
            method: 'sbp',
            payload: '',
        );
    }

    public function test_unknown_method_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported payment method: card');

        new PaymentInitiation(
            providerPaymentId: '700123456',
            method: 'card',
            checkoutUrl: 'https://example.test/pay',
        );
    }
}
