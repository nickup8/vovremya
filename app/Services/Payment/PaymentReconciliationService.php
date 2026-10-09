<?php

namespace App\Services\Payment;

use App\Enums\BillingCycleStatus;
use App\Enums\PaymentAttemptStatus;
use App\Models\BillingCycle;
use App\Models\PaymentAttempt;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentReconciliationService
{
    /**
     * Statuses that are an actual provider verdict: only these are handed to
     * the state machine for a locally timed-out attempt. Anything else
     * (NEW → Unknown, AUTHORIZED → Processing) is still in flight at the
     * bank, so the local timeout and the checkout block built on it survive.
     */
    private const FINAL_VERDICTS = [
        PaymentAttemptStatus::Succeeded,
        PaymentAttemptStatus::FailedTerminal,
        PaymentAttemptStatus::Refunded,
    ];

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
        $timeoutBatchSize = $config['timeout_batch_size'] ?? 10;
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

        // Separate, bounded queue for local timeouts awaiting a verdict —
        // its own budget, so it never displaces the ordinary batch above.
        // Snapshotted BEFORE this run's age-releases: a row released below
        // is only polled from the next pass onwards. Only rows due under
        // the backoff take one of its slots (see selectUnresolvedTimeouts).
        $timeoutAttempts = $this->selectUnresolvedTimeouts($timeoutBatchSize, $backoff);

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

        foreach ($timeoutAttempts as $attempt) {
            try {
                $this->reconcileTimeoutAttempt($attempt, $backoff);
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
            $this->ageRelease($attempt, $maxAgeWithProviderId, $maxAgeWithoutProviderId);

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
                $this->updatePollMetadata($attempt);

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
            $this->updatePollMetadata($attempt);
        }
    }

    /**
     * Attempt metadata as an array.
     *
     * The 'array' cast is invisible to static analysis here (larastan falls
     * back to the json column type), so the shape is declared once at this
     * boundary instead of guessed at every offset read.
     *
     * @return array<string, mixed>
     */
    private function attemptMetadata(PaymentAttempt $attempt): array
    {
        $metadata = $attempt->metadata ?? [];

        return is_array($metadata) ? $metadata : [];
    }

    /**
     * GetState for an attempt that was already age-released to
     * failed_terminal + reconciliation_timeout and still carries a provider
     * payment id.
     *
     * No age-release here — it has already run and must not run again — and
     * no transaction wraps the HTTP call: backoff is evaluated first, the
     * provider is queried outside any transaction, and only then is the
     * poll metadata merged under the usual row lock.
     *
     * @param  array<int, int>  $backoff
     */
    private function reconcileTimeoutAttempt(
        PaymentAttempt $attempt,
        array $backoff,
    ): void {
        // Same backoff as the ordinary queue — a row still waiting out its
        // window costs no HTTP at all. Selection already applied this when
        // the batch was filled; it is re-checked here so a stale snapshot
        // can never fire an early GetState.
        if (! $this->isPollDue($attempt, $backoff)) {
            return; // Not yet time to poll
        }

        if (! $this->gatewayManager->hasGateway($attempt->provider)) {
            Log::warning('Unknown gateway for reconciliation', [
                'attempt_id' => $attempt->id,
                'provider' => $attempt->provider,
            ]);

            return;
        }

        $gateway = $this->gatewayManager->getGateway($attempt->provider);

        try {
            $statusUpdate = $gateway->getPaymentStatus(
                $attempt->provider_payment_id,
                $attempt->internal_order_id,
            );
        } catch (\Throwable $e) {
            // The GetState call really happened: advance the backoff before
            // handing the failure back, so the caller still records it in
            // errors and the next pass waits out its window.
            $this->updatePollMetadata($attempt);

            throw $e;
        }

        // Exactly one poll write per actual GetState — the null answer and
        // the exception path above included, never twice for one call.
        $this->updatePollMetadata($attempt);

        if ($statusUpdate === null) {
            // No verdict: the local timeout, its checkout block and the
            // undefined_outcome flag all stay untouched.
            return;
        }

        if (! in_array($statusUpdate->normalizedStatus, self::FINAL_VERDICTS, true)) {
            // Still in flight at the bank (NEW / AUTHORIZED): a non-terminal
            // status is never sent to transition() — the attempt keeps its
            // timeout until a real verdict arrives.
            return;
        }

        // Confirmed success or refusal — the existing transition path, which
        // resolves the local timeout in place under its own lock/validation.
        $result = $this->transitionService->transition($statusUpdate);

        if ($result['success']) {
            Log::info('Reconciliation resolved attempt', [
                'attempt_id' => $attempt->id,
                'new_status' => $statusUpdate->normalizedStatus->value,
            ]);
        } else {
            Log::warning('Reconciliation transition failed', [
                'attempt_id' => $attempt->id,
                'error' => $result['error'] ?? null,
            ]);
        }
    }

    /**
     * Attempts age-released to failed_terminal + failure_category
     * reconciliation_timeout with a non-empty provider payment id — the bank
     * may well have decided already.
     *
     * Fair queue: rows are served least-recently-polled first (never polled
     * first). Readiness is applied WHILE the batch is filled, not after it:
     * a row still waiting out its backoff is passed over without taking one
     * of the $limit slots, so an older row with a long window can no longer
     * crowd out a fresher row that is due right now. Skipped rows are never
     * written to — their poll metadata stays exactly as it was.
     *
     * The queue is walked one page at a time (max($limit, 100) candidates
     * in memory, never a full-queue get()) and the walk stops at $limit due
     * rows. Bounded by its own limit — the ordinary batch above keeps its
     * full batch_size.
     *
     * @param  array<int, int>  $backoff
     * @return Collection<int, PaymentAttempt>
     */
    private function selectUnresolvedTimeouts(int $limit, array $backoff): Collection
    {
        $due = PaymentAttempt::where('status', PaymentAttemptStatus::FailedTerminal)
            ->where('failure_category', 'reconciliation_timeout')
            ->whereNotNull('provider_payment_id')
            ->where('provider_payment_id', '!=', '')
            ->orderByRaw("metadata->>'last_polled_at' ASC NULLS FIRST")
            ->orderBy('created_at')
            ->orderBy('id')
            ->lazy(max($limit, 100))
            ->filter(fn (PaymentAttempt $attempt): bool => $this->isPollDue($attempt, $backoff))
            ->take($limit)
            ->values();

        return new Collection($due->all());
    }

    /**
     * Whether a timeout attempt may be polled right now.
     *
     * A row never polled, or one whose poll metadata cannot be read, is due
     * (the same fail-open the poll itself relies on); otherwise the existing
     * backoff indexed by poll_count must have elapsed since last_polled_at.
     *
     * @param  array<int, int>  $backoff
     */
    private function isPollDue(PaymentAttempt $attempt, array $backoff): bool
    {
        $metadata = $this->attemptMetadata($attempt);
        $pollCount = $metadata['poll_count'] ?? 0;
        $lastPolledAt = $this->parseLastPolledAt($metadata['last_polled_at'] ?? null);

        if ($pollCount > 0 && $lastPolledAt !== null) {
            $backoffSeconds = $backoff[min($pollCount - 1, count($backoff) - 1)];

            return now()->gte($lastPolledAt->addSeconds($backoffSeconds));
        }

        return true;
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
     *
     * The batch model may be stale by the time this runs (a webhook can
     * confirm the payment in between), so the attempt is re-read under a
     * row lock and the release decision is re-taken against the fresh
     * status and age. Nothing is written when the outcome is already
     * decided — Succeeded attempts, a Paid cycle and an already extended
     * subscription are never overwritten. Attempt, cycle and subscription
     * changes commit atomically in one transaction.
     *
     * Stays a local release: PaymentTransitionService::transition() would
     * claim a provider event and therefore fabricate a webhook, which the
     * provider never sent. The lock order (attempt → cycle) matches the
     * transition path, so the two cannot interleave into a lost update.
     */
    private function ageRelease(
        PaymentAttempt $attempt,
        int $maxAgeWithProviderId,
        int $maxAgeWithoutProviderId,
    ): void {
        DB::transaction(function () use ($attempt, $maxAgeWithProviderId, $maxAgeWithoutProviderId) {
            $fresh = PaymentAttempt::lockForUpdate()
                ->whereKey($attempt->getKey())
                ->first();

            if ($fresh === null
                || ! $this->isAgeReleaseEligible($fresh, $maxAgeWithProviderId, $maxAgeWithoutProviderId)) {
                return;
            }

            $cycle = $fresh->billing_cycle_id === null
                ? null
                : BillingCycle::lockForUpdate()->whereKey($fresh->billing_cycle_id)->first();

            if ($cycle !== null && $cycle->status === BillingCycleStatus::Paid) {
                // The renewal already succeeded — never downgrade Paid and
                // never open a technical grace on a renewed subscription.
                return;
            }

            $fresh->update([
                'status' => PaymentAttemptStatus::FailedTerminal,
                'failure_category' => 'reconciliation_timeout',
                'finished_at' => now(),
            ]);

            if ($cycle !== null && $cycle->status !== BillingCycleStatus::Failed) {
                $cycle->update(['status' => BillingCycleStatus::Failed]);
            }

            // Same timeout + bounded technical grace rules as before, but
            // applied to the locked row from this transaction.
            $this->transitionService->applyTechnicalRenewalGrace($fresh);

            Log::info('Age-released payment attempt', [
                'attempt_id' => $fresh->id,
                'provider_payment_id' => $fresh->provider_payment_id,
                'age_hours' => $fresh->created_at->diffInHours(now()),
            ]);
        });
    }

    /**
     * Re-evaluate the age-release decision against the fresh row: the
     * attempt must still be in a batch-eligible status and already older
     * than the age limit that applies to its current provider_payment_id.
     */
    private function isAgeReleaseEligible(
        PaymentAttempt $attempt,
        int $maxAgeWithProviderId,
        int $maxAgeWithoutProviderId,
    ): bool {
        if (! in_array($attempt->status, [
            PaymentAttemptStatus::Created,
            PaymentAttemptStatus::Processing,
            PaymentAttemptStatus::Unknown,
        ], true)) {
            return false;
        }

        return $this->shouldAgeRelease($attempt, $maxAgeWithProviderId, $maxAgeWithoutProviderId);
    }

    /**
     * Update poll metadata with backoff info.
     *
     * Runs after the provider HTTP call, never during it: the row is
     * re-read under a lock inside a short transaction and only the two
     * polling-owned keys are rewritten, so a marker or flag written in the
     * meantime (charge_dispatch_started_at, technical_retry, …) survives
     * and the stale batch snapshot is not written back.
     */
    private function updatePollMetadata(PaymentAttempt $attempt): void
    {
        DB::transaction(function () use ($attempt) {
            $fresh = PaymentAttempt::lockForUpdate()
                ->whereKey($attempt->getKey())
                ->first();

            if ($fresh === null) {
                return;
            }

            $metadata = $fresh->metadata ?? [];
            $metadata['poll_count'] = (int) ($metadata['poll_count'] ?? 0) + 1;
            $metadata['last_polled_at'] = now()->toIso8601String();

            $fresh->update(['metadata' => $metadata]);
        });
    }
}
