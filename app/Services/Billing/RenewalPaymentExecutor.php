<?php

namespace App\Services\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\PaymentAttemptStatus;
use App\Models\PaymentAttempt;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\PaymentTransitionService;
use App\Services\Payment\TBankPaymentGateway;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Executes a prepared renewal PaymentAttempt against T-Bank (A2.3c2).
 *
 * Phases: Init (outside any DB transaction) → attach PaymentId under a row
 * lock → Charge → PaymentTransitionService::transition(). No scheduler, no
 * retries, no manual attempt/cycle/subscription edits after Charge — the
 * existing transition path owns state after that point.
 */
class RenewalPaymentExecutor
{
    public function __construct(
        private PaymentGatewayManager $gatewayManager,
        private PaymentTransitionService $transitionService,
    ) {}

    /**
     * Execute the prepared renewal attempt.
     *
     * @return array{success: bool, noop?: bool, error?: string}
     */
    public function execute(PaymentAttempt $attempt): array
    {
        $attempt = $this->loadAttempt($attempt);

        $gateway = $this->resolveGateway();

        // Idempotency / status gates (§4).
        if (in_array($attempt->status, [
            PaymentAttemptStatus::Succeeded,
            PaymentAttemptStatus::FailedTerminal,
            PaymentAttemptStatus::Refunded,
        ], true)) {
            return ['success' => true, 'noop' => true];
        }

        if ($attempt->status === PaymentAttemptStatus::Processing
            && $attempt->provider_payment_id !== null) {
            // In-flight with a provider PaymentId — a repeated Charge here is
            // forbidden; reconciliation owns the clarification.
            return ['success' => true, 'noop' => true];
        }

        if ($attempt->status !== PaymentAttemptStatus::Created
            || $attempt->provider_payment_id !== null) {
            throw new RuntimeException('T-Bank renewal attempt is not executable in its current state');
        }

        // Phase A — Init (outside any DB transaction). On failure the attempt
        // stays Created with provider_payment_id=null; the exception propagates.
        $providerPaymentId = $gateway->initRecurringPayment(
            amount: $attempt->amount,
            currency: $attempt->currency,
            internalOrderId: $attempt->internal_order_id,
        );

        // Phase B — persist the PaymentId BEFORE Charge so webhook and
        // reconciliation can locate the attempt while Charge is in flight.
        $this->attachPaymentId($attempt, $providerPaymentId);

        // Phase C — Charge, then hand the normalized update to the shared
        // transition path without any state-machine logic here.
        $update = $gateway->chargeRecurringPayment(
            providerPaymentId: $providerPaymentId,
            rebillId: $attempt->paymentMethod->provider_reference,
        );

        return $this->transitionService->transition($update);
    }

    /**
     * Re-load the attempt with relations and fail closed on any guard violation.
     * Never performs external requests itself.
     */
    private function loadAttempt(PaymentAttempt $attempt): PaymentAttempt
    {
        $fresh = PaymentAttempt::with(['paymentMethod', 'billingCycle'])
            ->whereKey($attempt->getKey())
            ->first();

        if ($fresh === null) {
            throw new RuntimeException('T-Bank renewal attempt not found');
        }

        $cycle = $fresh->billingCycle;
        $method = $fresh->paymentMethod;

        $allowed = ($fresh->metadata['renewal'] ?? null) === true
            && $cycle !== null
            && $cycle->origin === BillingCycleOrigin::Renewal
            && $fresh->provider === 'tbank'
            && $method !== null
            && $method->provider === 'tbank'
            && $method->type === 'card'
            && $method->status === 'active'
            && is_string($method->provider_reference)
            && $method->provider_reference !== '';

        if (! $allowed) {
            throw new RuntimeException('T-Bank renewal attempt failed execution guards');
        }

        return $fresh;
    }

    private function resolveGateway(): TBankPaymentGateway
    {
        $gateway = $this->gatewayManager->getGateway('tbank');

        if (! $gateway instanceof TBankPaymentGateway) {
            throw new RuntimeException('T-Bank renewal requires the TBankPaymentGateway driver');
        }

        return $gateway;
    }

    private function attachPaymentId(PaymentAttempt $attempt, string $providerPaymentId): void
    {
        DB::transaction(function () use ($attempt, $providerPaymentId) {
            $locked = PaymentAttempt::lockForUpdate()
                ->whereKey($attempt->getKey())
                ->first();

            if ($locked === null
                || $locked->status !== PaymentAttemptStatus::Created
                || $locked->provider_payment_id !== null) {
                throw new RuntimeException('T-Bank renewal attempt changed before PaymentId attach');
            }

            $locked->update([
                'provider_payment_id' => $providerPaymentId,
                'status' => PaymentAttemptStatus::Processing,
            ]);
        });
    }
}
