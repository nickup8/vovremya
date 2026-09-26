<?php

namespace App\Services\Payment;

use App\Services\Payment\DTOs\PaymentInitiation;
use App\Services\Payment\DTOs\ProviderStatusUpdate;

interface PaymentGatewayInterface
{
    /**
     * Provider name for routing and identification.
     */
    public function name(): string;

    /**
     * Create a payment at the provider.
     */
    public function createPayment(
        int $amount,
        string $currency,
        string $internalOrderId,
        array $context = [],
    ): PaymentInitiation;

    /**
     * Verify webhook signature.
     */
    public function verifyWebhook(array $payload, string $signature): bool;

    /**
     * Normalize raw webhook payload to a provider status update.
     */
    public function normalizeWebhook(array $payload): ProviderStatusUpdate;

    /**
     * Query provider for current payment status.
     * Returns null if provider cannot resolve the payment.
     */
    public function getPaymentStatus(
        ?string $providerPaymentId,
        string $internalOrderId,
    ): ?ProviderStatusUpdate;
}
