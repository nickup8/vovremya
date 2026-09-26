<?php

namespace App\Services\Payment\DTOs;

use App\Enums\PaymentAttemptStatus;

readonly class ProviderStatusUpdate
{
    public function __construct(
        public string $provider,
        public PaymentAttemptStatus $normalizedStatus,
        public ?string $providerEventId = null,
        public ?string $providerPaymentId = null,
        public ?string $internalOrderId = null,
        public ?int $amount = null,
        public ?string $currency = null,
        public array $raw = [],
    ) {}
}
