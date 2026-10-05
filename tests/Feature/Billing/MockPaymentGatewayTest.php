<?php

namespace Tests\Feature\Billing;

use App\Services\Payment\MockPaymentGateway;
use Tests\TestCase;

class MockPaymentGatewayTest extends TestCase
{
    public function test_create_payment_returns_redirect_initiation(): void
    {
        $initiation = (new MockPaymentGateway)->createPayment(490, 'RUB', 'order-1');

        $this->assertSame('redirect', $initiation->method);
        $this->assertNotNull($initiation->checkoutUrl);
        $this->assertStringStartsWith(config('app.url'), $initiation->checkoutUrl);
        $this->assertStringContainsString('/admin/settings?payment=', $initiation->checkoutUrl);
        $this->assertNull($initiation->payload);
        $this->assertStringStartsWith('mock_', $initiation->providerPaymentId);
    }
}
