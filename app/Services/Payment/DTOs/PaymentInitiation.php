<?php

namespace App\Services\Payment\DTOs;

readonly class PaymentInitiation
{
    public function __construct(
        public string $providerPaymentId,
        public string $checkoutUrl,
    ) {}
}
