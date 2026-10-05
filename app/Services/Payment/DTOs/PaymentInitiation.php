<?php

namespace App\Services\Payment\DTOs;

use InvalidArgumentException;

readonly class PaymentInitiation
{
    public const METHOD_REDIRECT = 'redirect';

    public const METHOD_SBP = 'sbp';

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $providerPaymentId,
        public string $method,
        public ?string $checkoutUrl = null,
        public ?string $payload = null,
        public array $metadata = [],
    ) {
        match ($this->method) {
            self::METHOD_REDIRECT => $this->requireNonEmpty($checkoutUrl, 'checkoutUrl'),
            self::METHOD_SBP => $this->requireNonEmpty($payload, 'payload'),
            default => throw new InvalidArgumentException("Unsupported payment method: {$this->method}"),
        };
    }

    private function requireNonEmpty(?string $value, string $field): void
    {
        if ($value === null || trim($value) === '') {
            throw new InvalidArgumentException(
                "Payment method \"{$this->method}\" requires a non-empty {$field}"
            );
        }
    }
}
