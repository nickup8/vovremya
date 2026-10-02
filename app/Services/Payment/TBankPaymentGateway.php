<?php

namespace App\Services\Payment;

use App\Enums\PaymentAttemptStatus;
use App\Services\Payment\DTOs\PaymentInitiation;
use App\Services\Payment\DTOs\ProviderStatusUpdate;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TBankPaymentGateway implements PaymentGatewayInterface
{
    private const DEFAULT_BASE_URL = 'https://securepay.tinkoff.ru';

    public function __construct(
        private readonly ?string $terminalKey,
        private readonly ?string $password,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
    ) {}

    public function name(): string
    {
        return 'tbank';
    }

    public function createPayment(
        int $amount,
        string $currency,
        string $internalOrderId,
        array $context = [],
    ): PaymentInitiation {
        $payload = [
            'TerminalKey' => $this->terminalKey,
            'Amount' => $amount * 100,
            'OrderId' => $internalOrderId,
            'Description' => 'Подписка ИРСИ',
            'PayType' => 'O',
            'Recurrent' => 'Y',
            'CustomerKey' => (string) ($context['workspace_id'] ?? ''),
            'DATA' => ['OperationInitiatorType' => '1'],
            'NotificationURL' => config('app.url').'/webhooks/payment/tbank',
            'SuccessURL' => config('app.url').'/admin/billing',
            'FailURL' => config('app.url').'/admin/billing',
        ];
        $payload['Token'] = $this->computeToken($payload);

        $response = Http::post($this->url('/v2/Init'), $payload);
        $data = $response->successful() ? $response->json() : null;

        if (! is_array($data) || empty($data['Success'])) {
            throw new RuntimeException('T-Bank Init request failed');
        }

        if (empty($data['PaymentId']) || empty($data['PaymentURL'])) {
            throw new RuntimeException('T-Bank Init response missing PaymentId or PaymentURL');
        }

        return new PaymentInitiation(
            providerPaymentId: (string) $data['PaymentId'],
            checkoutUrl: (string) $data['PaymentURL'],
        );
    }

    /**
     * T-Bank signs webhooks with its own Token field, not X-Webhook-Signature.
     */
    public function verifyWebhook(array $payload, string $signature): bool
    {
        $token = $payload['Token'] ?? null;

        if (! is_string($token) || $token === '') {
            return false;
        }

        return hash_equals($this->computeToken($payload), $token);
    }

    public function normalizeWebhook(array $payload): ProviderStatusUpdate
    {
        return new ProviderStatusUpdate(
            provider: $this->name(),
            normalizedStatus: $this->mapStatus($payload['Status'] ?? null),
            providerPaymentId: isset($payload['PaymentId']) ? (string) $payload['PaymentId'] : null,
            internalOrderId: isset($payload['OrderId']) ? (string) $payload['OrderId'] : null,
            amount: $this->toInternalAmount($payload['Amount'] ?? null),
            currency: 'RUB',
            raw: $payload,
        );
    }

    public function getPaymentStatus(
        ?string $providerPaymentId,
        string $internalOrderId,
    ): ?ProviderStatusUpdate {
        if ($providerPaymentId === null || $providerPaymentId === '') {
            return null;
        }

        $payload = [
            'TerminalKey' => $this->terminalKey,
            'PaymentId' => $providerPaymentId,
        ];
        $payload['Token'] = $this->computeToken($payload);

        $response = Http::post($this->url('/v2/GetState'), $payload);
        $data = $response->successful() ? $response->json() : null;

        if (! is_array($data) || empty($data['Success'])) {
            return null;
        }

        return $this->normalizeWebhook($data);
    }

    /**
     * Token = SHA-256(sorted scalar values + Password).
     *
     * Token itself and nested objects/arrays are excluded from the hash input.
     */
    private function computeToken(array $payload): string
    {
        unset($payload['Token']);

        $scalars = [];
        foreach ($payload as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $scalars[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        ksort($scalars);

        $concatenated = implode('', $scalars).($this->password ?? '');

        return hash('sha256', $concatenated);
    }

    private function mapStatus(mixed $status): PaymentAttemptStatus
    {
        return match ($status) {
            'CONFIRMED' => PaymentAttemptStatus::Succeeded,
            'REJECTED', 'CANCELED', 'REVERSED' => PaymentAttemptStatus::FailedTerminal,
            'REFUNDED' => PaymentAttemptStatus::Refunded,
            default => PaymentAttemptStatus::Unknown,
        };
    }

    private function toInternalAmount(mixed $amount): ?int
    {
        if (is_int($amount)) {
            $minor = $amount;
        } elseif (is_string($amount) && ctype_digit($amount)) {
            $minor = (int) $amount;
        } else {
            return null;
        }

        if ($minor % 100 !== 0) {
            return null;
        }

        return intdiv($minor, 100);
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}
