<?php

namespace App\Services\Payment;

use App\Models\Subscription;

interface PaymentGatewayInterface
{
    public function createPayment(Subscription $subscription, int $amount): array;

    public function verifyWebhook(array $payload, string $signature): bool;

    public function parseWebhookStatus(array $payload): ?string;
}
