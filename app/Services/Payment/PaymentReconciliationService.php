<?php

namespace App\Services\Payment;

use App\Enums\PaymentAttemptStatus;
use App\Models\PaymentAttempt;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PaymentReconciliationService
{
    public function __construct(
        private PaymentGatewayManager $gatewayManager,
        private PaymentTransitionService $transitionService,
    ) {}

    /**
     * Reconcile stale payment attempts.
     *
     * @return array{processed: int, errors: int}
     */
    public function reconcile(): array
    {
        $config = config('billing.reconciliation');
        $batchSize = $config['batch_size'] ?? 50;
        $freshGraceSeconds = $config['fresh_grace_seconds'] ?? 60;
        $backoff = $config['backoff'] ?? [60, 120, 300, 900, 1800, 3600];
        $maxAgeWithProviderId = $config['max_age_with_provider_id'] ?? 86400;
        $maxAgeWithoutProviderId = $config['max_age_without_provider_id'] ?? 1800;

        $freshThreshold = now()->subSeconds($freshGraceSeconds);

        // Select stale attempts
        $attempts = PaymentAttempt::whereIn('status', [
            PaymentAttemptStatus::Created,
            PaymentAttemptStatus::Processing,
            PaymentAttemptStatus::Unknown,
        ])
            ->where('created_at', '<', $freshThreshold)
            ->orderBy('created_at')
            ->limit($batchSize)
            ->get();

        $processed = 0;
        $errors = 0;

        foreach ($attempts as $attempt) {
            try {
                $this->reconcileAttempt($attempt, $backoff, $maxAgeWithProviderId, $maxAgeWithoutProviderId);
                $processed++;
            } catch (\Throwable $e) {
                $errors++;
                Log::error('Reconciliation failed for attempt', [
                    'attempt_id' => $attempt->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['processed' => $processed, 'errors' => $errors];
    }

    /**
     * Reconcile a single payment attempt.
     */
    private function reconcileAttempt(
        PaymentAttempt $attempt,
        array $backoff,
        int $maxAgeWithProviderId,
        int $maxAgeWithoutProviderId,
    ): void {
        $metadata = $attempt->metadata ?? [];
        $pollCount = $metadata['poll_count'] ?? 0;
        $lastPolledAt = $this->parseLastPolledAt($metadata['last_polled_at'] ?? null);

        // Check age release
        if ($this->shouldAgeRelease($attempt, $maxAgeWithProviderId, $maxAgeWithoutProviderId)) {
            $this->ageRelease($attempt);

            return;
        }

        // Check backoff - skip if not yet time
        if ($pollCount > 0 && $lastPolledAt !== null) {
            $backoffSeconds = $backoff[min($pollCount - 1, count($backoff) - 1)];
            $nextPollAt = $lastPolledAt->addSeconds($backoffSeconds);

            if (now()->lt($nextPollAt)) {
                return; // Not yet time to poll
            }
        }

        // Resolve gateway
        if (! $this->gatewayManager->hasGateway($attempt->provider)) {
            Log::warning('Unknown gateway for reconciliation', [
                'attempt_id' => $attempt->id,
                'provider' => $attempt->provider,
            ]);

            return;
        }

        $gateway = $this->gatewayManager->getGateway($attempt->provider);

        // Query provider status (outside DB transaction)
        $statusUpdate = $gateway->getPaymentStatus(
            $attempt->provider_payment_id,
            $attempt->internal_order_id,
        );

        if ($statusUpdate !== null) {
            // T-Bank GetState for an in-flight recurring payment (Status=NEW)
            // normalizes as Unknown while the local attempt is Processing.
            // processing -> unknown is not an allowed transition, so skip the
            // state machine entirely: keep Processing, advance backoff poll
            // metadata, and create no provider event.
            if ($attempt->status === PaymentAttemptStatus::Processing
                && $statusUpdate->normalizedStatus === PaymentAttemptStatus::Unknown) {
                $this->updatePollMetadata($attempt, $pollCount);

                return;
            }

            // Provider returned a status - transition
            $result = $this->transitionService->transition($statusUpdate);

            if ($result['success']) {
                Log::info('Reconciliation resolved attempt', [
                    'attempt_id' => $attempt->id,
                    'new_status' => $statusUpdate->normalizedStatus->value,
                ]);
            } else {
                Log::warning('Reconciliation transition failed', [
                    'attempt_id' => $attempt->id,
                    'error' => $result['error'],
                ]);
            }
        } else {
            // Provider returned null - update metadata with backoff
            $this->updatePollMetadata($attempt, $pollCount);
        }
    }

    /**
     * Parse metadata last_polled_at (ISO8601 string) into Carbon.
     *
     * Returns null for missing or malformed values: backoff then cannot be
     * evaluated, so the current poll is allowed instead of failing. The
     * original metadata value is never mutated — a fresh instance is parsed.
     */
    private function parseLastPolledAt(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Check if attempt should be age-released.
     */
    private function shouldAgeRelease(
        PaymentAttempt $attempt,
        int $maxAgeWithProviderId,
        int $maxAgeWithoutProviderId,
    ): bool {
        $maxAge = $attempt->provider_payment_id !== null
            ? $maxAgeWithProviderId
            : $maxAgeWithoutProviderId;

        return $attempt->created_at->addSeconds($maxAge)->isPast();
    }

    /**
     * Age-release attempt to failed_terminal.
     */
    private function ageRelease(PaymentAttempt $attempt): void
    {
        $attempt->update([
            'status' => PaymentAttemptStatus::FailedTerminal,
            'failure_category' => 'reconciliation_timeout',
            'finished_at' => now(),
        ]);

        // Update cycle status
        $cycle = $attempt->billingCycle;
        if ($cycle !== null) {
            $cycle->update(['status' => \App\Enums\BillingCycleStatus::Failed]);
        }

        // Renewal attempts: PastDue + bounded technical grace (no-op for
        // checkout attempts and other failure categories).
        $this->transitionService->applyTechnicalRenewalGrace($attempt);

        Log::info('Age-released payment attempt', [
            'attempt_id' => $attempt->id,
            'provider_payment_id' => $attempt->provider_payment_id,
            'age_hours' => $attempt->created_at->diffInHours(now()),
        ]);
    }

    /**
     * Update poll metadata with backoff info.
     */
    private function updatePollMetadata(PaymentAttempt $attempt, int $currentPollCount): void
    {
        $metadata = $attempt->metadata ?? [];
        $metadata['poll_count'] = $currentPollCount + 1;
        $metadata['last_polled_at'] = now()->toIso8601String();

        $attempt->update(['metadata' => $metadata]);
    }
}
