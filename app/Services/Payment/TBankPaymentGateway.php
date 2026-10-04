<?php

namespace App\Services\Payment;

use App\Enums\PaymentAttemptStatus;
use App\Services\Payment\DTOs\PaymentInitiation;
use App\Services\Payment\DTOs\ProviderStatusUpdate;
use Illuminate\Http\Client\HttpClientException;
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
        $autoRenew = ($context['auto_renew'] ?? false) === true;

        $payload = [
            'TerminalKey' => $this->terminalKey,
            'Amount' => $amount * 100,
            'OrderId' => $internalOrderId,
            'Description' => 'Подписка ИРСИ',
            'PayType' => 'O',
        ];

        if ($autoRenew) {
            $payload['Recurrent'] = 'Y';
            $payload['CustomerKey'] = (string) ($context['workspace_id'] ?? '');
        }

        $payload['DATA'] = ['OperationInitiatorType' => $autoRenew ? '1' : '0'];
        $payload['NotificationURL'] = config('app.url').'/webhooks/payment/tbank';
        // Return routes only set a one-shot UX flash — status source of truth
        // stays the webhook / Billing Core.
        $payload['SuccessURL'] = config('app.url').'/admin/billing/payment/success';
        $payload['FailURL'] = config('app.url').'/admin/billing/payment/failed';
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
     * Recurring card Init for a renewal (no user redirect).
     *
     * Deliberately WITHOUT Recurrent/CustomerKey/SuccessURL/FailURL —
     * the card on file is charged later via chargeRecurringPayment().
     * Returns the provider PaymentId; PaymentURL is not required.
     */
    public function initRecurringPayment(
        int $amount,
        string $currency,
        string $internalOrderId,
    ): string {
        $payload = [
            'TerminalKey' => $this->terminalKey,
            'Amount' => $amount * 100,
            'OrderId' => $internalOrderId,
            'Description' => 'Продление подписки ИРСИ',
            'PayType' => 'O',
            'DATA' => ['OperationInitiatorType' => 'R'],
            'NotificationURL' => config('app.url').'/webhooks/payment/tbank',
        ];
        $payload['Token'] = $this->computeToken($payload);

        $data = $this->post($this->url('/v2/Init'), $payload, 'Init');

        if (empty($data['PaymentId'])) {
            throw new RuntimeException('T-Bank recurring Init response missing PaymentId');
        }

        return (string) $data['PaymentId'];
    }

    /**
     * Charge the saved card (RebillId) for a recurring payment.
     *
     * Response is normalized through the shared webhook path — no separate
     * status mapping or amount conversion here.
     */
    public function chargeRecurringPayment(
        string $providerPaymentId,
        string $rebillId,
    ): ProviderStatusUpdate {
        $payload = [
            'TerminalKey' => $this->terminalKey,
            'PaymentId' => $providerPaymentId,
            'RebillId' => $rebillId,
        ];
        $payload['Token'] = $this->computeToken($payload);

        $data = $this->post($this->url('/v2/Charge'), $payload, 'Charge');

        return $this->normalizeWebhook($data);
    }

    /**
     * Look up a payment by OrderId (CheckOrder) — recovery entry point for a
     * possibly-lost Init.
     *
     * Returns null when no payments exist, and also when the provider reports
     * ErrorCode 335 ("OrderId not found"): for a fresh renewal OrderId there is
     * legitimately no payment yet, so the caller may proceed with a fresh Init.
     * Every other failure (transport, non-2xx, invalid body, other error codes)
     * fails closed. Multiple distinct PaymentIds also fail closed: never pick
     * one automatically.
     */
    public function findPaymentByOrderId(string $internalOrderId): ?ProviderStatusUpdate
    {
        $payload = [
            'TerminalKey' => $this->terminalKey,
            'OrderId' => $internalOrderId,
        ];
        $payload['Token'] = $this->computeToken($payload);

        try {
            $response = Http::post($this->url('/v2/CheckOrder'), $payload);
        } catch (HttpClientException $e) {
            throw new RuntimeException('T-Bank CheckOrder request failed', 0, $e);
        }

        $data = $response->successful() ? $response->json() : null;

        if (! is_array($data)) {
            throw new RuntimeException('T-Bank CheckOrder request failed');
        }

        if (empty($data['Success'])) {
            if ((string) ($data['ErrorCode'] ?? '') === '335') {
                return null;
            }

            throw new RuntimeException('T-Bank CheckOrder request failed');
        }

        $payments = $data['Payments'] ?? null;
        if (! is_array($payments) || $payments === []) {
            return null;
        }

        // Keep the first item per distinct PaymentId, dropping empty ones.
        $byPaymentId = [];
        foreach ($payments as $payment) {
            if (! is_array($payment) || empty($payment['PaymentId'])) {
                continue;
            }

            $paymentId = (string) $payment['PaymentId'];
            $byPaymentId[$paymentId] ??= $payment;
        }

        if ($byPaymentId === []) {
            return null;
        }

        if (count($byPaymentId) > 1) {
            throw new RuntimeException('T-Bank CheckOrder returned multiple distinct PaymentIds');
        }

        $payment = reset($byPaymentId);
        if (empty($payment['OrderId'])) {
            $payment['OrderId'] = $internalOrderId;
        }

        return $this->normalizeWebhook($payment);
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
     * POST with shared failure handling for the recurring endpoints:
     * transport / non-2xx / invalid body / Success=false → RuntimeException.
     * Messages never include payload contents (no secrets leaked).
     *
     * @return array<string, mixed>
     */
    private function post(string $url, array $payload, string $operation): array
    {
        try {
            $response = Http::post($url, $payload);
        } catch (HttpClientException $e) {
            throw new RuntimeException("T-Bank {$operation} request failed", 0, $e);
        }

        $data = $response->successful() ? $response->json() : null;

        if (! is_array($data) || empty($data['Success'])) {
            throw new RuntimeException("T-Bank {$operation} request failed");
        }

        return $data;
    }

    /**
     * Token = SHA-256(sorted scalar values including Password).
     *
     * Official T-Bank algorithm: Password is added as a regular "Password"
     * key and participates in the alphabetical sort — it must NOT be appended
     * after sorting. Token itself and nested objects/arrays are excluded from
     * the hash input.
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

        $scalars['Password'] = $this->password ?? '';

        ksort($scalars);

        return hash('sha256', implode('', $scalars));
    }

    private function mapStatus(mixed $status): PaymentAttemptStatus
    {
        return match ($status) {
            'AUTHORIZED' => PaymentAttemptStatus::Processing,
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
