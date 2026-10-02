<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\Payment\PaymentGatewayInterface;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\PaymentTransitionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PaymentWebhookController extends Controller
{
    public function __construct(
        private PaymentGatewayManager $gatewayManager,
        private PaymentTransitionService $transitionService,
    ) {}

    /**
     * Handle provider-specific webhook.
     *
     * POST /webhooks/payment/{provider}
     */
    public function handleProvider(Request $request, string $provider): Response
    {
        // Resolve gateway from registered providers
        if (! $this->gatewayManager->hasGateway($provider)) {
            Log::warning('Payment webhook: unknown provider', [
                'provider' => $provider,
            ]);

            return response()->json(['error' => 'Unknown provider'], 400);
        }

        $gateway = $this->gatewayManager->getGateway($provider);

        return $this->processWebhook($request, $gateway);
    }

    /**
     * Handle legacy webhook (compatibility alias).
     *
     * POST /webhooks/payment
     */
    public function handle(Request $request): Response
    {
        $gateway = $this->gatewayManager->getDefault();

        return $this->processWebhook($request, $gateway);
    }

    /**
     * Process webhook through the core transition path.
     */
    private function processWebhook(
        Request $request,
        PaymentGatewayInterface $gateway,
    ): Response {
        $signature = $request->header('X-Webhook-Signature', '');

        if (! $gateway->verifyWebhook($request->all(), $signature)) {
            Log::warning('Payment webhook: rejected', [
                'reason' => 'invalid_signature',
                'provider' => $gateway->name(),
            ]);

            abort(403, 'Invalid webhook signature');
        }

        // Normalize payload to provider status update
        $update = $gateway->normalizeWebhook($request->all());

        // Process through core transition service
        $result = $this->transitionService->transition(
            update: $update,
            signatureMetadata: $signature,
        );

        if ($result['success']) {
            return $this->successResponse($gateway);
        }

        // Log but return 200 to prevent provider retries for validation errors
        Log::warning('Payment webhook: transition failed', [
            'provider' => $update->provider,
            'error' => $result['error'],
        ]);

        return $this->successResponse($gateway);
    }

    /**
     * T-Bank expects a plain-text "OK" body; other gateways keep the JSON contract.
     */
    private function successResponse(PaymentGatewayInterface $gateway): Response
    {
        if ($gateway->name() === 'tbank') {
            return response('OK', 200);
        }

        return response()->json(['ok' => true]);
    }
}
