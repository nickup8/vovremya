<?php

namespace App\Services\Payment;

use App\Enums\PaymentAttemptStatus;
use App\Services\Payment\DTOs\PaymentInitiation;
use App\Services\Payment\DTOs\ProviderStatusUpdate;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use RuntimeException;

class TBankPaymentGateway implements PaymentGatewayInterface
{
    private const DEFAULT_BASE_URL = 'https://securepay.tinkoff.ru';

    private const INIT_PATH = '/v2/Init';

    private const CHARGE_PATH = '/v2/Charge';

    /**
     * Endpoints the global Guzzle retry decider (AppServiceProvider) must
     * never transport-retry: Init and Charge create/trigger money movement,
     * and a ConnectException may arrive AFTER the request reached the bank
     * (e.g. a response timeout), so an automatic re-send could hit the bank
     * a second time. One Init/Charge call = one HTTP attempt; an undefined
     * outcome is owned by CheckOrder recovery, the charge dispatch marker
     * and reconciliation — never by an automatic re-send.
     *
     * Built from the path constants every Init call site (card, SBP,
     * recurring) and Charge use below, so rule and URLs cannot drift apart.
     */
    public const SINGLE_ATTEMPT_PATHS = [self::INIT_PATH, self::CHARGE_PATH];

    /**
     * ErrorCode set returned by T-Bank for "insufficient funds" on Charge.
     * Only these are classified as a user-level decline; every other
     * Success=false stays fail closed.
     */
    private const INSUFFICIENT_FUNDS_ERROR_CODES = ['103', '116', '1051'];

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
        if (($context['payment_method'] ?? null) === 'sbp') {
            return $this->createSbpPayment($amount, $internalOrderId, $context);
        }

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
        // Signed per-attempt return URLs: the local attempt already exists
        // (Phase A), so the bank can only bring THIS payment back — never
        // "the latest one" a parallel tab started. Return routes stay
        // verdict-free; status source of truth is the webhook / Billing Core.
        $payload['SuccessURL'] = $this->signedReturnUrl('admin.billing.payment.success', $context);
        $payload['FailURL'] = $this->signedReturnUrl('admin.billing.payment.failed', $context);
        $payload['Token'] = $this->computeToken($payload);

        $response = Http::post($this->url(self::INIT_PATH), $payload);
        $data = $response->successful() ? $response->json() : null;

        if (! is_array($data) || empty($data['Success'])) {
            throw new RuntimeException('T-Bank Init request failed');
        }

        if (empty($data['PaymentId']) || empty($data['PaymentURL'])) {
            throw new RuntimeException('T-Bank Init response missing PaymentId or PaymentURL');
        }

        return new PaymentInitiation(
            providerPaymentId: (string) $data['PaymentId'],
            method: PaymentInitiation::METHOD_REDIRECT,
            checkoutUrl: (string) $data['PaymentURL'],
        );
    }

    /**
     * Return URL signed for the exact local PaymentAttempt of this checkout.
     *
     * `return_attempt_id` is the primary key of the attempt created before
     * Init. Without it the URL is still signed but carries no attempt — the
     * return route treats that (like a legacy unsigned URL) as neutral.
     */
    private function signedReturnUrl(string $routeName, array $context): string
    {
        return URL::signedRoute($routeName, [
            'attempt' => $context['return_attempt_id'] ?? null,
        ]);
    }

    /**
     * SBP Init + GetQr(PAYLOAD) — без card redirect.
     *
     * Init идентичен карточному, но без автопродления (Recurrent/CustomerKey/
     * DATA не передаются — автопродление через СБП не реализовано). PaymentURL
     * не требуется: клиентский payload берётся из GetQr Data.
     *
     * context['sbp_expires_at'] — абсолютный срок действия ссылки,
     * вычисленный сервером ОДИН РАЗ в Phase A и уже сохранённый в
     * attempt.metadata: он уходит в Init как RedirectDueDate и
     * участвует в Token. Здесь срок не вычисляется и не продлевается;
     * без контекста поле в Init не попадает.
     *
     * Если Init прошёл, а GetQr упал — fail closed без второго Init:
     * платёж остаётся в статусе unknown и уходит в reconciliation.
     * Token/Password/TerminalKey никогда не логируются.
     */
    private function createSbpPayment(int $amount, string $internalOrderId, array $context = []): PaymentInitiation
    {
        $initPayload = [
            'TerminalKey' => $this->terminalKey,
            'Amount' => $amount * 100,
            'OrderId' => $internalOrderId,
            'Description' => 'Подписка ИРСИ',
            'PayType' => 'O',
            'NotificationURL' => config('app.url').'/webhooks/payment/tbank',
            'SuccessURL' => config('app.url').'/admin/billing/payment/success',
            'FailURL' => config('app.url').'/admin/billing/payment/failed',
        ];

        // Absolute deadline with timezone — added BEFORE the Token so it
        // participates in the hash (official Init contract).
        $expiresAt = $context['sbp_expires_at'] ?? null;
        if (is_string($expiresAt) && $expiresAt !== '') {
            $initPayload['RedirectDueDate'] = $expiresAt;
        }

        $initPayload['Token'] = $this->computeToken($initPayload);

        $data = $this->post($this->url(self::INIT_PATH), $initPayload, 'Init');

        if (empty($data['PaymentId'])) {
            throw new RuntimeException('T-Bank Init response missing PaymentId');
        }

        $paymentId = (string) $data['PaymentId'];

        $qrPayload = [
            'TerminalKey' => $this->terminalKey,
            'PaymentId' => $paymentId,
            'DataType' => 'PAYLOAD',
            'PaymentMethod' => 'SBP',
        ];
        $qrPayload['Token'] = $this->computeToken($qrPayload);

        $qrData = $this->post($this->url('/v2/GetQr'), $qrPayload, 'GetQr');

        if (! isset($qrData['Data']) || ! is_string($qrData['Data']) || $qrData['Data'] === '') {
            throw new RuntimeException('T-Bank GetQr response missing Data');
        }

        $metadata = [];

        if (isset($qrData['RequestKey']) && is_string($qrData['RequestKey']) && $qrData['RequestKey'] !== '') {
            $metadata['request_key'] = $qrData['RequestKey'];
        }

        return new PaymentInitiation(
            providerPaymentId: $paymentId,
            method: PaymentInitiation::METHOD_SBP,
            payload: $qrData['Data'],
            metadata: $metadata,
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

        $data = $this->post($this->url(self::INIT_PATH), $payload, 'Init');

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
     *
     * Insufficient-funds declines (ErrorCode 103/116/1051) are classified as
     * a terminal failure instead of an exception, so the business layer can
     * tell "card has no money" apart from a provider/technical error. Every
     * other Success=false (e.g. ErrorCode 10) stays fail closed with a safe
     * diagnostic: ErrorCode/Message/Details are logged and included in the
     * exception message — never TerminalKey, Password, Token, RebillId or the
     * request payload. Transport errors, non-2xx and invalid JSON also fail
     * closed; non-2xx logs only the safe response fields.
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

        try {
            $response = Http::post($this->url(self::CHARGE_PATH), $payload);
        } catch (HttpClientException $e) {
            throw new RuntimeException('T-Bank Charge request failed', 0, $e);
        }

        if (! $response->successful()) {
            // Non-2xx: fail closed, log only safe fields (never the payload).
            $this->logChargeFailure($response->status(), $providerPaymentId, $response->json());

            throw new RuntimeException('T-Bank Charge request failed');
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('T-Bank Charge request failed');
        }

        if (empty($data['Success'])) {
            $errorCode = $this->safeDiagnosticValue($data['ErrorCode'] ?? null);
            $category = $this->classifyFailure($errorCode);

            if ($category !== null) {
                $paymentId = ! empty($data['PaymentId']) ? (string) $data['PaymentId'] : $providerPaymentId;

                return new ProviderStatusUpdate(
                    provider: $this->name(),
                    normalizedStatus: PaymentAttemptStatus::FailedTerminal,
                    providerPaymentId: $paymentId,
                    internalOrderId: isset($data['OrderId']) ? (string) $data['OrderId'] : null,
                    amount: $this->toInternalAmount($data['Amount'] ?? null),
                    currency: 'RUB',
                    raw: $data,
                    failureCode: $errorCode,
                    failureCategory: $category,
                    failureMessage: $this->extractFailureMessage($data),
                );
            }

            $fields = $this->safeErrorFields($data);
            $this->logChargeFailure($response->status(), $providerPaymentId, $data);

            throw new RuntimeException(sprintf(
                'T-Bank Charge request failed: ErrorCode=%s; Message=%s; Details=%s',
                $fields['error_code'] ?? '-',
                $fields['message'] ?? '-',
                $fields['details'] ?? '-',
            ));
        }

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
        $failureCode = $this->extractFailureCode($payload);

        return new ProviderStatusUpdate(
            provider: $this->name(),
            normalizedStatus: $this->mapStatus($payload['Status'] ?? null),
            providerPaymentId: isset($payload['PaymentId']) ? (string) $payload['PaymentId'] : null,
            internalOrderId: isset($payload['OrderId']) ? (string) $payload['OrderId'] : null,
            amount: $this->toInternalAmount($payload['Amount'] ?? null),
            currency: 'RUB',
            raw: $payload,
            failureCode: $failureCode,
            failureCategory: $this->classifyFailure($failureCode),
            failureMessage: $this->extractFailureMessage($payload),
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

    /**
     * DEADLINE_EXPIRED — bank-reported SBP timeout (official test scenario
     * «Платеж — отказ по таймауту», developer.tbank.ru/eacq/intro/errors/test-sbp:
     * GetState returns DEADLINE_EXPIRED). A provider verdict, not a local
     * age-release: failure_category stays provider_failed, never
     * reconciliation_timeout, so the outcome is never flagged undefined.
     */
    private function mapStatus(mixed $status): PaymentAttemptStatus
    {
        return match ($status) {
            'AUTHORIZED' => PaymentAttemptStatus::Processing,
            'CONFIRMED' => PaymentAttemptStatus::Succeeded,
            'REJECTED', 'CANCELED', 'REVERSED', 'DEADLINE_EXPIRED' => PaymentAttemptStatus::FailedTerminal,
            'REFUNDED' => PaymentAttemptStatus::Refunded,
            default => PaymentAttemptStatus::Unknown,
        };
    }

    /**
     * ErrorCode as-is when present and not "0". Message/Details are provider
     * human-readable error texts — safe to keep, never secrets (Token and
     * card data live in raw only).
     */
    private function extractFailureCode(array $payload): ?string
    {
        if (! array_key_exists('ErrorCode', $payload)) {
            return null;
        }

        $code = (string) $payload['ErrorCode'];

        return $code === '' || $code === '0' ? null : $code;
    }

    /**
     * Single classifier shared by the direct Charge response path and the
     * webhook/reconciliation path (normalizeWebhook), so ErrorCode 103/116/1051
     * always yield the same failureCategory. Unknown codes stay unclassified.
     */
    private function classifyFailure(?string $errorCode): ?string
    {
        if ($errorCode === null) {
            return null;
        }

        return in_array($errorCode, self::INSUFFICIENT_FUNDS_ERROR_CODES, true)
            ? 'insufficient_funds'
            : null;
    }

    private function extractFailureMessage(array $payload): ?string
    {
        $message = $payload['Message'] ?? $payload['Details'] ?? null;

        if (! is_string($message) || $message === '') {
            return null;
        }

        return $message;
    }

    /**
     * Scalar diagnostic value as string; anything else (null, array, object,
     * bool) → null so callers never trigger PHP warnings/coercion issues.
     */
    private function safeDiagnosticValue(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * Safe T-Bank error fields for diagnostics/logs. Only ErrorCode, Message
     * and Details are extracted — never TerminalKey/Password/Token/RebillId
     * and never the full payload.
     *
     * @return array{error_code: ?string, message: ?string, details: ?string}
     */
    private function safeErrorFields(array $data): array
    {
        return [
            'error_code' => $this->safeDiagnosticValue($data['ErrorCode'] ?? null),
            'message' => $this->safeDiagnosticValue($data['Message'] ?? null),
            'details' => $this->safeDiagnosticValue($data['Details'] ?? null),
        ];
    }

    /**
     * Log a Charge failure with safe fields only: operation, payment_id and
     * HTTP status always; ErrorCode/Message/Details when the body has them.
     */
    private function logChargeFailure(int $httpStatus, string $providerPaymentId, mixed $body): void
    {
        $fields = is_array($body)
            ? $this->safeErrorFields($body)
            : ['error_code' => null, 'message' => null, 'details' => null];

        Log::warning('T-Bank Charge request failed', [
            'operation' => 'Charge',
            'payment_id' => $providerPaymentId,
            'http_status' => $httpStatus,
            'error_code' => $fields['error_code'],
            'message' => $fields['message'],
            'details' => $fields['details'],
        ]);
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
