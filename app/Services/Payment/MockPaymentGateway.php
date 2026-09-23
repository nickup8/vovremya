<?php

namespace App\Services\Payment;

use App\Models\Subscription;

class MockPaymentGateway implements PaymentGatewayInterface
{
    public function createPayment(Subscription $subscription, int $amount): array
    {
        $paymentId = 'mock_'.bin2hex(random_bytes(16));

        return [
            'payment_id' => $paymentId,
            'confirmation_url' => config('app.url')."/admin/settings?payment={$paymentId}",
        ];
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

    public function parseWebhookStatus(array $payload): ?string
    {
        $status = $payload['status'] ?? null;

        return match ($status) {
            'succeeded', 'paid' => 'paid',
            'failed', 'canceled' => 'failed',
            'refunded' => 'refunded',
            default => null,
        };
    }
}
