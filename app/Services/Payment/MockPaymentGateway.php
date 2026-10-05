<?php

namespace App\Services\Payment;

use App\Enums\PaymentAttemptStatus;
use App\Services\Payment\DTOs\PaymentInitiation;
use App\Services\Payment\DTOs\ProviderStatusUpdate;

class MockPaymentGateway implements PaymentGatewayInterface
{
    public function name(): string
    {
        return 'mock';
    }

    public function createPayment(
        int $amount,
        string $currency,
        string $internalOrderId,
        array $context = [],
    ): PaymentInitiation {
        $paymentId = 'mock_'.bin2hex(random_bytes(16));

        return new PaymentInitiation(
            providerPaymentId: $paymentId,
            method: PaymentInitiation::METHOD_REDIRECT,
            checkoutUrl: config('app.url')."/admin/settings?payment={$paymentId}",
        );
    }

    public function verifyWebhook(array $payload, string $signature): bool
    {
        $secret = config('billing.legacy_mock_webhook_secret');

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        if ($signature === '') {
            return false;
        }

        return hash_equals($secret, $signature);
    }

    public function normalizeWebhook(array $payload): ProviderStatusUpdate
    {
        $status = $payload['status'] ?? null;
        $paymentId = $payload['payment_id'] ?? $payload['transaction_id'] ?? null;
        $orderId = $payload['order_id'] ?? null;
        $amount = isset($payload['amount']) && is_numeric($payload['amount'])
            ? (int) $payload['amount']
            : null;

        $normalizedStatus = match ($status) {
            'succeeded', 'paid' => PaymentAttemptStatus::Succeeded,
            'failed', 'canceled' => PaymentAttemptStatus::FailedTerminal,
            'refunded' => PaymentAttemptStatus::Refunded,
            default => PaymentAttemptStatus::Unknown,
        };

        return new ProviderStatusUpdate(
            provider: $this->name(),
            providerPaymentId: $paymentId,
            internalOrderId: $orderId,
            normalizedStatus: $normalizedStatus,
            amount: $amount,
            currency: 'RUB',
            raw: $payload,
        );
    }

    public function getPaymentStatus(
        ?string $providerPaymentId,
        string $internalOrderId,
    ): ?ProviderStatusUpdate {
        // Mock gateway has no external state to query
        return null;
    }
}
