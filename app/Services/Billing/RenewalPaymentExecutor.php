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
 * Executes a prepared renewal PaymentAttempt against T-Bank (A2.3c2–A2.3e).
 *
 * Always runs CheckOrder first for Created attempts without a PaymentId — a
 * lost Init may have already produced a bank payment. Then: Init (if fresh)
 * → attach PaymentId under a row lock → Charge (or recovery branching) →
 * PaymentTransitionService::transition().
 *
 * Every Charge dispatch is preceded by an atomic charge_dispatch_started_at
 * metadata marker: once written, that attempt is never Charged again from
 * here. In-flight attempts without a marker (old attach→Charge crash window)
 * are resolved via GetState first. No scheduler, no automatic Charge retry.
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
            return $this->noop();
        }

        if ($attempt->status === PaymentAttemptStatus::Processing
            && $attempt->provider_payment_id !== null) {
            return $this->resumeInFlight($attempt, $gateway);
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

        // Marker immediately BEFORE the external /Charge; committed atomically.
        if (! $this->markChargeDispatch($attempt)) {
            return $this->noop();
        }

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
            // Recovered Init that never reached the COF Charge — charge now,
            // but only after the dispatch marker is committed under a lock.
            if (! $this->markChargeDispatch($attempt)) {
                return $this->noop();
            }

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
     * Recovery for in-flight attempts (Processing + provider_payment_id).
     */
    private function resumeInFlight(
        PaymentAttempt $attempt,
        TBankPaymentGateway $gateway,
    ): array {
        // A) Dispatch marker present — Charge was already handed to the bank
        // at least once; reconciliation owns the outcome. No HTTP at all.
        if ($this->chargeDispatchStarted($attempt)) {
            return $this->noop();
        }

        // B) Old crash window: attach committed, marker never written.
        $state = $gateway->getPaymentStatus(
            $attempt->provider_payment_id,
            $attempt->internal_order_id,
        );

        if ($state === null) {
            throw new RuntimeException('T-Bank GetState returned no data for in-flight renewal attempt');
        }

        if (($state->raw['Status'] ?? null) === 'NEW') {
            // Payment exists but the COF Charge never ran — dispatch once.
            if (! $this->markChargeDispatch($attempt)) {
                return $this->noop();
            }

            $update = $gateway->chargeRecurringPayment(
                providerPaymentId: $attempt->provider_payment_id,
                rebillId: $attempt->paymentMethod->provider_reference,
            );

            return $this->transitionService->transition($update);
        }

        if (in_array($state->normalizedStatus, [
            PaymentAttemptStatus::Succeeded,
            PaymentAttemptStatus::Processing,
            PaymentAttemptStatus::FailedTerminal,
            PaymentAttemptStatus::Refunded,
        ], true)) {
            // No Charge — the bank already knows a definitive status.
            return $this->transitionService->transition($state);
        }

        // Any other raw status / normalized Unknown — never force
        // processing→unknown; reconciliation clarifies it later.
        return $this->noop();
    }

    private function chargeDispatchStarted(PaymentAttempt $attempt): bool
    {
        return ($attempt->metadata['charge_dispatch_started_at'] ?? null) !== null;
    }

    /**
     * Atomically write charge_dispatch_started_at immediately before /Charge.
     *
     * Returns false when the marker already exists — in that case the Charge
     * must NOT be dispatched. Existing metadata keys are preserved.
     */
    private function markChargeDispatch(PaymentAttempt $attempt): bool
    {
        return DB::transaction(function () use ($attempt) {
            $locked = PaymentAttempt::lockForUpdate()
                ->whereKey($attempt->getKey())
                ->first();

            if ($locked === null) {
                throw new RuntimeException('T-Bank renewal attempt not found for charge dispatch');
            }

            $metadata = $locked->metadata ?? [];

            if (($metadata['charge_dispatch_started_at'] ?? null) !== null) {
                return false;
            }

            $metadata['charge_dispatch_started_at'] = now()->toISOString();
            $locked->update(['metadata' => $metadata]);

            return true;
        });
    }

    /**
     * Controlled no-op result.
     *
     * @return array{success: bool, noop: bool}
     */
    private function noop(): array
    {
        return ['success' => true, 'noop' => true];
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
