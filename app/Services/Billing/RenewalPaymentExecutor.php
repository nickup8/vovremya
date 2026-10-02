<?php

namespace App\Services\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\PaymentAttemptStatus;
use App\Models\PaymentAttempt;
use App\Services\Payment\DTOs\ProviderStatusUpdate;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\PaymentTransitionService;
use App\Services\Payment\TBankPaymentGateway;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Executes a prepared renewal PaymentAttempt against T-Bank (A2.3c2/A2.3d).
 *
 * Always runs CheckOrder first for Created attempts without a PaymentId — a
 * lost Init may have already produced a bank payment. Then: Init (if fresh)
 * → attach PaymentId under a row lock → Charge (or recovery branching) →
 * PaymentTransitionService::transition(). No scheduler, no Charge retries,
 * no manual attempt/cycle/subscription edits after Charge — the existing
 * transition path owns state after that point.
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

        // Phase R — CheckOrder BEFORE any Init: while it is unknown whether a
        // bank PaymentId already exists, creating a new payment is unsafe.
        // A CheckOrder exception propagates without Init; attempt stays Created.
        $existing = $gateway->findPaymentByOrderId($attempt->internal_order_id);

        if ($existing === null) {
            return $this->executeFresh($attempt, $gateway);
        }

        return $this->resumeExisting($attempt, $gateway, $existing);
    }

    /**
     * Fresh path: no bank payment exists — Init → attach → Charge → transition.
     */
    private function executeFresh(
        PaymentAttempt $attempt,
        TBankPaymentGateway $gateway,
    ): array {
        // Init (outside any DB transaction). On failure the attempt stays
        // Created with provider_payment_id=null; the exception propagates.
        $providerPaymentId = $gateway->initRecurringPayment(
            amount: $attempt->amount,
            currency: $attempt->currency,
            internalOrderId: $attempt->internal_order_id,
        );

        // Persist the PaymentId BEFORE Charge so webhook and reconciliation
        // can locate the attempt while Charge is in flight.
        $this->attachPaymentId($attempt, $providerPaymentId);

        // Charge, then hand the normalized update to the shared transition
        // path without any state-machine logic here.
        $update = $gateway->chargeRecurringPayment(
            providerPaymentId: $providerPaymentId,
            rebillId: $attempt->paymentMethod->provider_reference,
        );

        return $this->transitionService->transition($update);
    }

    /**
     * Recovery: CheckOrder found an existing payment for this OrderId.
     *
     * The PaymentId goes through the same locked Phase B attach first; only
     * then does the bank-side status decide whether a Charge is allowed.
     */
    private function resumeExisting(
        PaymentAttempt $attempt,
        TBankPaymentGateway $gateway,
        ProviderStatusUpdate $existing,
    ): array {
        $recoveredPaymentId = $existing->providerPaymentId;
        if ($recoveredPaymentId === null || $recoveredPaymentId === '') {
            throw new RuntimeException('T-Bank CheckOrder recovery payment missing PaymentId');
        }

        $this->attachPaymentId($attempt, $recoveredPaymentId);

        if (in_array($existing->normalizedStatus, [
            PaymentAttemptStatus::Succeeded,
            PaymentAttemptStatus::FailedTerminal,
            PaymentAttemptStatus::Refunded,
            PaymentAttemptStatus::Processing,
        ], true)) {
            // Terminal/decided or in-flight at the bank — never Charge again.
            return $this->transitionService->transition($existing);
        }

        if (($existing->raw['Status'] ?? null) === 'NEW') {
            // Recovered Init that never reached the COF Charge — charge now.
            $update = $gateway->chargeRecurringPayment(
                providerPaymentId: $recoveredPaymentId,
                rebillId: $attempt->paymentMethod->provider_reference,
            );

            return $this->transitionService->transition($update);
        }

        // Any other status that normalized as Unknown — no Charge; the update
        // goes through transition and gets clarified by reconciliation later.
        return $this->transitionService->transition($existing);
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
