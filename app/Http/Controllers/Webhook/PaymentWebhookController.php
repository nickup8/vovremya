<?php

namespace App\Http\Controllers\Webhook;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\Billing\BillingCoreWriter;
use App\Services\Payment\PaymentGatewayInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function __construct(
        private PaymentGatewayInterface $paymentGateway,
        private BillingCoreWriter $coreWriter,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $signature = $request->header('X-Webhook-Signature', '');

        if (! $this->paymentGateway->verifyWebhook($request->all(), $signature)) {
            Log::warning('Payment webhook: rejected', ['reason' => 'invalid_signature']);

            abort(403, 'Invalid webhook signature');
        }

        $payload = $request->all();
        $paymentId = $payload['payment_id'] ?? $payload['transaction_id'] ?? null;
        $orderId = $payload['order_id'] ?? null;

        $rawStatus = $this->paymentGateway->parseWebhookStatus($payload);

        if (! $rawStatus) {
            return response()->json(['ok' => true]);
        }

        // ── Primary lookup: by payment_id (legacy happy path) ──
        $subscription = $paymentId
            ? Subscription::where('payment_id', $paymentId)->first()
            : null;

        // ── Fallback: by order_id → PaymentAttempt.internal_order_id ──
        if (! $subscription && $orderId) {
            $attempt = $this->coreWriter->findAttemptByInternalOrderId($orderId);

            if ($attempt && in_array($attempt->status->value, ['unknown', 'created', 'processing'], true)) {
                return $this->handleOrderRecovery($attempt, $paymentId, $rawStatus, $payload);
            }
        }

        if (! $subscription) {
            Log::warning('Payment webhook: rejected', [
                'reason' => 'subscription_not_found',
                'payment_id' => $paymentId,
                'order_id' => $orderId,
            ]);

            return response()->json(['ok' => true]);
        }

        // ── Amount verification ──
        if ($rawStatus === 'paid') {
            $webhookAmount = $payload['amount'] ?? null;

            if ($webhookAmount === null || ! is_numeric($webhookAmount) || (int) $webhookAmount <= 0) {
                Log::warning('Payment webhook: rejected', [
                    'reason' => 'amount_invalid',
                    'payment_id' => $paymentId,
                    'subscription_id' => $subscription->id,
                ]);

                return response()->json(['ok' => true]);
            }

            if ((int) $webhookAmount !== $subscription->amount_paid) {
                Log::warning('Payment webhook: rejected', [
                    'reason' => 'amount_mismatch',
                    'payment_id' => $paymentId,
                    'subscription_id' => $subscription->id,
                ]);

                return response()->json(['ok' => true]);
            }
        }

        $parsedStatus = SubscriptionStatus::tryFrom($rawStatus === 'paid' ? 'active' : $rawStatus);

        if (! $parsedStatus) {
            Log::warning('Payment webhook: rejected', [
                'reason' => 'invalid_status',
                'payment_id' => $paymentId,
                'raw_status' => $rawStatus,
            ]);

            return response()->json(['error' => 'Invalid subscription status'], 400);
        }

        // P1.1c: не оживляем терминальные подписки запоздалым webhook.
        if (! in_array($subscription->status, ['pending', 'active'], true)) {
            Log::warning('Payment webhook: rejected', [
                'reason' => 'terminal_state',
                'payment_id' => $paymentId,
                'subscription_id' => $subscription->id,
                'current_status' => $subscription->status,
                'incoming' => $rawStatus,
            ]);

            return response()->json(['ok' => true]);
        }

        // ── Atomic legacy + Core update in one transaction ──
        DB::transaction(function () use ($subscription, $parsedStatus, $paymentId, $rawStatus, $payload) {
            // Lock legacy subscription for update
            Subscription::where('id', $subscription->id)->lockForUpdate()->first();

            $subscription->update(['status' => $parsedStatus]);

            // ProviderEvent dedup
            $isNewEvent = $this->coreWriter->recordProviderEvent($paymentId, $rawStatus, $payload);

            if ($isNewEvent) {
                match ($parsedStatus) {
                    SubscriptionStatus::Active => $this->coreWriter->paymentSucceeded($paymentId),
                    SubscriptionStatus::Failed => $this->coreWriter->paymentFailed($paymentId),
                    SubscriptionStatus::Refunded => $this->coreWriter->paymentRefunded($paymentId),
                    default => null,
                };
            }
        });

        return response()->json(['ok' => true]);
    }

    /**
     * Handle webhook recovery via order_id fallback.
     *
     * For unknown/created/processing attempts:
     * - attach provider_payment_id
     * - resolve legacy subscription from metadata
     * - perform success/failed transition
     */
    private function handleOrderRecovery(
        \App\Models\PaymentAttempt $attempt,
        ?string $paymentId,
        string $rawStatus,
        array $payload,
    ): JsonResponse {
        if (! $paymentId) {
            Log::warning('Payment webhook: order_id recovery skipped', [
                'reason' => 'no_payment_id',
                'order_id' => $payload['order_id'] ?? null,
            ]);

            return response()->json(['ok' => true]);
        }

        DB::transaction(function () use ($attempt, $paymentId, $rawStatus, $payload) {
            $this->coreWriter->recoverAttemptFromWebhook(
                $attempt,
                $paymentId,
                $rawStatus,
            );

            $this->coreWriter->recordProviderEvent($paymentId, $rawStatus, $payload);
        });

        Log::info('Payment webhook: order_id recovery succeeded', [
            'order_id' => $payload['order_id'] ?? null,
            'payment_id' => $paymentId,
            'status' => $rawStatus,
        ]);

        return response()->json(['ok' => true]);
    }
}
