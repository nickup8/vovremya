<?php

namespace App\Http\Controllers\Webhook;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\Payment\PaymentGatewayInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function __construct(
        private PaymentGatewayInterface $paymentGateway,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $signature = $request->header('X-Webhook-Signature', '');

        if (! $this->paymentGateway->verifyWebhook($request->all(), $signature)) {
            abort(403, 'Invalid webhook signature');
        }

        $payload = $request->all();
        $paymentId = $payload['payment_id'] ?? $payload['transaction_id'] ?? null;

        if (! $paymentId) {
            return response()->json(['ok' => true]);
        }

        $subscription = Subscription::where('payment_id', $paymentId)->first();

        if (! $subscription) {
            Log::warning('Payment webhook: subscription not found', ['payment_id' => $paymentId]);

            return response()->json(['ok' => true]);
        }

        $rawStatus = $this->paymentGateway->parseWebhookStatus($payload);

        if (! $rawStatus) {
            return response()->json(['ok' => true]);
        }

        $parsedStatus = SubscriptionStatus::tryFrom($rawStatus === 'paid' ? 'active' : $rawStatus);

        if (! $parsedStatus) {
            Log::warning('Payment webhook: invalid status received', [
                'payment_id' => $paymentId,
                'raw_status' => $rawStatus,
            ]);

            return response()->json(['error' => 'Invalid subscription status'], 400);
        }

        $subscription->update(['status' => $parsedStatus]);

        if ($rawStatus === 'paid') {
            $this->activateSubscription($subscription);
        }

        return response()->json(['ok' => true]);
    }

    private function activateSubscription(Subscription $subscription): void
    {
        $user = $subscription->user;
        $plan = $subscription->tariffPlan;

        if (! $plan) {
            Log::warning('Payment webhook: tariff plan not found', ['subscription_id' => $subscription->id]);

            return;
        }

        $user->update([
            'tariff' => $plan->code,
            'expires_at' => $subscription->expires_at,
        ]);
    }
}
