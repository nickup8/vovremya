<?php

namespace App\Services\Payment;

use App\Enums\BillingCycleStatus;
use App\Enums\PaymentAttemptStatus;
use App\Models\BillingCycle;
use App\Models\PaymentAttempt;
use App\Services\Payment\DTOs\ProviderStatusUpdate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
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

    /**
     * TTL of the per-attempt status-check lock: it has to cover the WHOLE
     * GetState call with a margin, because one call may legally spend the
     * entire global HTTP retry budget — up to 6 attempts of 20s response
     * timeout plus 9.3s of retry delays (AppServiceProvider), ≈ 130s. Half
     * of that wedges the attempt for another party while a check is still
     * running; 180s leaves ~50s of headroom and still frees an attempt
     * within minutes if the process dies mid-check. The retry policy itself
     * is not owned here and stays untouched.
     */
    private const CHECK_LOCK_TTL_SECONDS = 180;

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
     * Manual GetState for a SINGLE attempt — the shared mechanism behind the
     * POST check button. It asks the very same per-attempt lock, cooldown and
     * fresh-row re-read as both automatic queues, so a click, a tab and a cron
     * pass can never produce two GetState calls for one attempt.
     *
     * This path never writes the automatic queue's poll metadata: poll_count
     * is neither advanced nor reset by a manual request. Null, transport
     * errors and non-final bank answers (NEW / AUTHORIZED) keep the attempt
     * waiting exactly as it was — only a final verdict goes through the
     * existing PaymentTransitionService.
     *
     * @return array{attempt: PaymentAttempt, checked: bool}
     *                                                       `checked` = an actual GetState happened (false =
     *                                                       lock held, cooldown active, or nothing to check).
     */
    public function checkAttemptNow(PaymentAttempt $attempt): array
    {
        if ($attempt->provider_payment_id === null || $attempt->provider_payment_id === '') {
            return ['attempt' => $attempt, 'checked' => false];
        }

        if (! $this->gatewayManager->hasGateway($attempt->provider)) {
            return ['attempt' => $attempt, 'checked' => false];
        }

        return $this->runStatusCheck(
            $attempt,
            due: fn (PaymentAttempt $fresh): bool => $this->isStatusCheckEligible($fresh)
                && $fresh->provider_payment_id !== null
                && $fresh->provider_payment_id !== ''
                && $this->statusCheckCooldownExpired($fresh),
            handle: function (PaymentAttempt $fresh, ?ProviderStatusUpdate $statusUpdate): void {
                $this->applyVerdictOnly($statusUpdate);
            },
        );
    }

    /**
     * One coordinated status check for a SINGLE attempt: the ONLY place in
     * this service that talks to a gateway, shared by the manual POST check,
     * the post-expiry bypass and both automatic queues.
     *
     * Everything runs under the per-attempt lock, in this order:
     *
     * 1. re-read the attempt from the database — the caller's conditions are
     *    re-checked against that FRESH row, so a stale batch snapshot or a
     *    stale manual request can neither start a second check nor poll an
     *    attempt another actor has just resolved or just checked;
     * 2. provider HTTP, outside every DB transaction;
     * 3. every write this check owes — cooldown marker, poll metadata, the
     *    SBP expiry one-shot — committed BEFORE the lock is released;
     * 4. the caller's status handling, under the same lock, so a concurrent
     *    check never meets a half-finished one.
     *
     * A skip (lock held, cooldown still running, row no longer eligible)
     * performs no HTTP and consumes nothing — the one-shot marker included.
     *
     * @param  callable(PaymentAttempt): bool  $due  path conditions, always evaluated on the fresh row
     * @param  callable(PaymentAttempt, ?ProviderStatusUpdate): void  $handle  what an answer means for this path
     * @return array{attempt: PaymentAttempt, checked: bool}
     *                                                       `checked` = an actual GetState happened.
     */
    private function runStatusCheck(
        PaymentAttempt $attempt,
        callable $due,
        callable $handle,
        bool $advancePollMetadata = false,
        bool $consumeSbpExpiry = false,
        bool $rethrow = false,
    ): array {
        $lock = Cache::lock(
            'billing:payment-status-check:'.$attempt->getKey(),
            self::CHECK_LOCK_TTL_SECONDS,
        );

        if (! $lock->get()) {
            // Another check of this attempt is in flight right now
            return ['attempt' => $attempt->refresh(), 'checked' => false];
        }

        try {
            $fresh = PaymentAttempt::query()->whereKey($attempt->getKey())->first();

            if ($fresh === null) {
                return ['attempt' => $attempt, 'checked' => false];
            }

            if (! $due($fresh) || ! $this->gatewayManager->hasGateway($fresh->provider)) {
                // The snapshot the caller held is no longer actionable
                return ['attempt' => $fresh, 'checked' => false];
            }

            $gateway = $this->gatewayManager->getGateway($fresh->provider);

            try {
                // Provider HTTP — outside any DB transaction
                $statusUpdate = $gateway->getPaymentStatus(
                    $fresh->provider_payment_id,
                    $fresh->internal_order_id,
                );
            } catch (\Throwable $e) {
                // The GetState really happened: what this call owes is still
                // written before the lock goes, and an error is never a
                // verdict — the attempt keeps waiting.
                $this->recordStatusCheck($fresh, $advancePollMetadata, $consumeSbpExpiry);

                if ($rethrow) {
                    throw $e;
                }

                return ['attempt' => $fresh->refresh(), 'checked' => true];
            }

            // Exactly one set of writes per actual GetState, whatever it said
            $this->recordStatusCheck($fresh, $advancePollMetadata, $consumeSbpExpiry);

            $handle($fresh, $statusUpdate);

            return ['attempt' => $fresh->refresh(), 'checked' => true];
        } finally {
            $lock->release();
        }
    }

    /**
     * Every metadata write an ACTUAL GetState owes, in one short
     * transaction, always before the shared lock is released:
     *
     * - `last_status_check_at` — the cooldown marker shared by every path;
     * - `poll_count` / `last_polled_at` — the automatic queue's backoff,
     *   incremented exactly once per actual call and never reset;
     * - `sbp_expiry_polled_at` — the post-expiry one-shot, consumed only by
     *   the bypass that really reached the bank.
     *
     * The row is re-read under a lock and only these keys are rewritten, so
     * a marker or flag written in the meantime (charge_dispatch_started_at,
     * technical_retry, …) survives, the stale snapshot is never written back
     * and metadata is merged rather than replaced.
     */
    private function recordStatusCheck(
        PaymentAttempt $attempt,
        bool $advancePollMetadata,
        bool $consumeSbpExpiry,
    ): void {
        DB::transaction(function () use ($attempt, $advancePollMetadata, $consumeSbpExpiry) {
            $fresh = PaymentAttempt::lockForUpdate()
                ->whereKey($attempt->getKey())
                ->first();

            if ($fresh === null) {
                return;
            }

            $metadata = $this->attemptMetadata($fresh);
            $metadata['last_status_check_at'] = now()->toIso8601String();

            if ($advancePollMetadata) {
                $metadata['poll_count'] = (int) ($metadata['poll_count'] ?? 0) + 1;
                $metadata['last_polled_at'] = now()->toIso8601String();
            }

            if ($consumeSbpExpiry) {
                $metadata['sbp_expiry_polled_at'] = now()->toIso8601String();
            }

            $fresh->update(['metadata' => $metadata]);
        });
    }

    /**
     * What an answer means for the manual check and for the post-expiry
     * bypass: ONLY a final bank verdict goes through the existing
     * PaymentTransitionService. Null, transport errors and in-flight
     * answers leave the status, the checkout block and the waiting state
     * untouched — a local expiry is never mistaken for a bank refusal.
     */
    private function applyVerdictOnly(?ProviderStatusUpdate $statusUpdate): void
    {
        if ($statusUpdate !== null
            && in_array($statusUpdate->normalizedStatus, self::FINAL_VERDICTS, true)) {
            $this->transitionService->transition($statusUpdate);
        }
    }

    /**
     * Whether the bank may still be asked about this attempt at all: either
     * the ordinary queue owns it (still in flight) or the timeout queue does
     * (age-released, but with a provider payment id to ask about). A
     * resolved attempt — Succeeded, Refunded, a confirmed refusal — is never
     * re-checked, whatever snapshot the caller still holds.
     */
    private function isStatusCheckEligible(PaymentAttempt $attempt): bool
    {
        return $this->isOrdinaryQueueAttempt($attempt)
            || $this->isTimeoutQueueAttempt($attempt);
    }

    /**
     * A row of the ordinary queue: still in flight at the bank.
     */
    private function isOrdinaryQueueAttempt(PaymentAttempt $attempt): bool
    {
        return in_array($attempt->status, [
            PaymentAttemptStatus::Created,
            PaymentAttemptStatus::Processing,
            PaymentAttemptStatus::Unknown,
        ], true);
    }

    /**
     * A row of the timeout queue: age-released to failed_terminal with the
     * local reconciliation_timeout category, still carrying a provider
     * payment id the bank can be asked about.
     */
    private function isTimeoutQueueAttempt(PaymentAttempt $attempt): bool
    {
        return $attempt->status === PaymentAttemptStatus::FailedTerminal
            && $attempt->failure_category === 'reconciliation_timeout'
            && $attempt->provider_payment_id !== null
            && $attempt->provider_payment_id !== '';
    }

    /**
     * Reconcile a single payment attempt of the ordinary queue.
     *
     * The batch snapshot only decides whether the row is worth a closer
     * look — age release, the post-expiry bypass and the backoff window are
     * cheap pre-checks. The GetState itself goes through runStatusCheck,
     * which re-reads the row and repeats those conditions under the shared
     * lock, so a snapshot that has aged out meanwhile fires no HTTP at all.
     *
     * @param  array<int, int>  $backoff
     */
    private function reconcileAttempt(
        PaymentAttempt $attempt,
        array $backoff,
        int $maxAgeWithProviderId,
        int $maxAgeWithoutProviderId,
    ): void {
        // Check age release
        if ($this->shouldAgeRelease($attempt, $maxAgeWithProviderId, $maxAgeWithoutProviderId)) {
            $this->ageRelease($attempt, $maxAgeWithProviderId, $maxAgeWithoutProviderId);

            return;
        }

        // ── Post-expiry one-shot ──
        // An SBP attempt whose link has already expired gets ONE timely
        // GetState through the shared lock+cooldown, whatever the old
        // backoff says — the bank's answer arrives while the user is still
        // waiting, even with the browser closed. poll_count is never reset:
        // after this poll the ordinary backoff continues from the advanced
        // metadata, and the consumed one-shot marker keeps every following
        // pass out of the bank. A skip (lock held / cooldown still running)
        // reaches no HTTP and therefore consumes no marker.
        if ($this->isSbpExpiryBypassDue($attempt)) {
            $this->runStatusCheck(
                $attempt,
                due: fn (PaymentAttempt $fresh): bool => $this->isOrdinaryQueueAttempt($fresh)
                    && $this->isSbpExpiryBypassDue($fresh)
                    && $this->statusCheckCooldownExpired($fresh),
                handle: function (PaymentAttempt $fresh, ?ProviderStatusUpdate $statusUpdate): void {
                    $this->applyVerdictOnly($statusUpdate);
                },
                advancePollMetadata: true,
                consumeSbpExpiry: true,
            );

            return;
        }

        // Check backoff - skip if not yet time
        if (! $this->isPollDue($attempt, $backoff)) {
            return; // Not yet time to poll
        }

        // Resolve gateway
        if (! $this->gatewayManager->hasGateway($attempt->provider)) {
            Log::warning('Unknown gateway for reconciliation', [
                'attempt_id' => $attempt->id,
                'provider' => $attempt->provider,
            ]);

            return;
        }

        $this->runStatusCheck(
            $attempt,
            due: fn (PaymentAttempt $fresh): bool => $this->isOrdinaryQueueAttempt($fresh)
                && $this->statusCheckCooldownExpired($fresh)
                && $this->isPollDue($fresh, $backoff),
            handle: function (PaymentAttempt $fresh, ?ProviderStatusUpdate $statusUpdate): void {
                if ($statusUpdate === null) {
                    // No answer: the backoff above already advanced, the
                    // attempt keeps waiting exactly as it was.
                    return;
                }

                // T-Bank GetState for an in-flight recurring payment (Status=NEW)
                // normalizes as Unknown while the local attempt is Processing.
                // processing -> unknown is not an allowed transition, so skip the
                // state machine entirely: keep Processing, the poll metadata is
                // already advanced, and create no provider event.
                if ($fresh->status === PaymentAttemptStatus::Processing
                    && $statusUpdate->normalizedStatus === PaymentAttemptStatus::Unknown) {
                    return;
                }

                // Provider returned a status - transition
                $result = $this->transitionService->transition($statusUpdate);

                if ($result['success']) {
                    Log::info('Reconciliation resolved attempt', [
                        'attempt_id' => $fresh->id,
                        'new_status' => $statusUpdate->normalizedStatus->value,
                    ]);
                } else {
                    Log::warning('Reconciliation transition failed', [
                        'attempt_id' => $fresh->id,
                        'error' => $result['error'],
                    ]);
                }
            },
            advancePollMetadata: true,
            // As before: the transport failure is handed back to reconcile(),
            // which counts it in errors and logs it — the backoff has already
            // moved on by then.
            rethrow: true,
        );
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
     * Whether the per-attempt status-check cooldown has elapsed.
     *
     * Missing or malformed metadata → allowed (fail-open, same as the
     * backoff parser). The cooldown is measured from the last ACTUAL
     * GetState of this attempt, manual or background alike.
     */
    private function statusCheckCooldownExpired(PaymentAttempt $attempt): bool
    {
        $cooldownSeconds = (int) config(
            'billing.reconciliation.status_check_cooldown_seconds',
            60,
        );

        $lastCheckedAt = $this->parseLastPolledAt(
            $this->attemptMetadata($attempt)['last_status_check_at'] ?? null,
        );

        if ($lastCheckedAt === null) {
            return true;
        }

        return now()->gte($lastCheckedAt->addSeconds($cooldownSeconds));
    }

    /**
     * The post-expiry bypass is a ONE-SHOT per attempt: due once the stored
     * absolute sbp_expires_at has passed and the consuming marker
     * (sbp_expiry_polled_at) is still absent. In-flight SBP attempts with a
     * provider payment id only — a released attempt lives in the timeout
     * queue, and an attempt without a provider id has nothing to ask the
     * bank about. Re-checked on the fresh row under the lock, so a marker
     * consumed meanwhile ends the bypass for this pass.
     */
    private function isSbpExpiryBypassDue(PaymentAttempt $attempt): bool
    {
        $metadata = $this->attemptMetadata($attempt);

        if (($metadata['payment_method'] ?? null) !== 'sbp') {
            return false;
        }

        if ($attempt->provider_payment_id === null || $attempt->provider_payment_id === '') {
            return false;
        }

        if (array_key_exists('sbp_expiry_polled_at', $metadata)) {
            // Already consumed — the ordinary backoff rules from here on
            return false;
        }

        $expiresAt = $metadata['sbp_expires_at'] ?? null;

        if (! is_string($expiresAt) || $expiresAt === '') {
            return false;
        }

        try {
            $deadline = Carbon::parse($expiresAt);
        } catch (\Throwable) {
            return false;
        }

        return now()->gte($deadline);
    }

    /**
     * GetState for an attempt that was already age-released to
     * failed_terminal + reconciliation_timeout and still carries a provider
     * payment id.
     *
     * No age-release here — it has already run and must not run again — and
     * the answer handling is its own: only a final bank verdict leaves the
     * local timeout, everything else keeps it and its checkout block. The
     * call itself goes through runStatusCheck: backoff and row state are
     * re-checked under the shared lock, the HTTP stays outside any
     * transaction and the poll metadata is merged (one increment per actual
     * call, exception included) before the lock is released.
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

        $this->runStatusCheck(
            $attempt,
            due: fn (PaymentAttempt $fresh): bool => $this->isTimeoutQueueAttempt($fresh)
                && $this->statusCheckCooldownExpired($fresh)
                && $this->isPollDue($fresh, $backoff),
            handle: function (PaymentAttempt $fresh, ?ProviderStatusUpdate $statusUpdate): void {
                if ($statusUpdate === null) {
                    // No verdict: the local timeout, its checkout block and
                    // the undefined_outcome flag all stay untouched.
                    return;
                }

                if (! in_array($statusUpdate->normalizedStatus, self::FINAL_VERDICTS, true)) {
                    // Still in flight at the bank (NEW / AUTHORIZED): a
                    // non-terminal status is never sent to transition() —
                    // the attempt keeps its timeout until a real verdict
                    // arrives.
                    return;
                }

                // Confirmed success or refusal — the existing transition
                // path, which resolves the local timeout in place under its
                // own lock/validation.
                $result = $this->transitionService->transition($statusUpdate);

                if ($result['success']) {
                    Log::info('Reconciliation resolved attempt', [
                        'attempt_id' => $fresh->id,
                        'new_status' => $statusUpdate->normalizedStatus->value,
                    ]);
                } else {
                    Log::warning('Reconciliation transition failed', [
                        'attempt_id' => $fresh->id,
                        'error' => $result['error'] ?? null,
                    ]);
                }
            },
            advancePollMetadata: true,
            // The failure stays visible to the caller (errors counter) and
            // the backoff has already moved on before it is handed back.
            rethrow: true,
        );
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
     * Whether an attempt of either automatic queue may be polled right now.
     *
     * A row never polled, or one whose poll metadata cannot be read, is due
     * (the same fail-open the poll itself relies on); otherwise the existing
     * backoff indexed by poll_count must have elapsed since last_polled_at.
     * Evaluated on the batch snapshot as a cheap pre-check and again on the
     * fresh row under the shared lock, so a row polled in the meantime never
     * fires an early GetState.
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
        if (! $this->isOrdinaryQueueAttempt($attempt)) {
            return false;
        }

        return $this->shouldAgeRelease($attempt, $maxAgeWithProviderId, $maxAgeWithoutProviderId);
    }
}
