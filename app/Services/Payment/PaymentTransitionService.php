<?php

namespace App\Services\Payment;

use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\NotificationLog;
use App\Models\PaymentAttempt;
use App\Models\ProviderEvent;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Notifications\RenewalInsufficientFundsNotification;
use App\Services\Notification\MasterNotificationService;
use App\Services\Payment\DTOs\ProviderStatusUpdate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentTransitionService
{
    /**
     * Allowed transitions for PaymentAttempt status.
     */
    private const ALLOWED_TRANSITIONS = [
        'created' => [
            'processing',
            'unknown',
            'succeeded',
            'failed_terminal',
        ],
        'processing' => [
            'succeeded',
            'failed_terminal',
            'refunded',
        ],
        'unknown' => [
            'succeeded',
            'failed_terminal',
            'refunded',
        ],
        'failed_terminal' => [
            'succeeded', // late success
        ],
        'succeeded' => [
            'succeeded', // idempotent no-op
            'refunded',
        ],
        'refunded' => [
            'refunded', // idempotent no-op
        ],
    ];

    public function __construct(
        private PaymentGatewayManager $gatewayManager,
    ) {}

    /**
     * Process a provider status update through the core transition path.
     *
     * This is the single entry point for webhooks, reconciliation, and future provider adapters.
     *
     * @return array{success: bool, error?: string}
     */
    public function transition(
        ProviderStatusUpdate $update,
        ?string $signatureMetadata = null,
    ): array {
        return DB::transaction(function () use ($update, $signatureMetadata) {
            // 1. Provider event claim/dedup (DB-safe atomic)
            $event = $this->claimProviderEvent($update, $signatureMetadata);
            if ($event === null) {
                // Duplicate event — already processed
                return ['success' => true];
            }

            // 2. Locate + lock PaymentAttempt (with identity conflict check)
            $attempt = $this->locateAttempt($update);
            if (is_string($attempt)) {
                // Identity conflict — $attempt holds the error string
                return $this->handleUnmatchedEvent($event, $attempt);
            }
            if ($attempt === null) {
                return $this->handleUnmatchedEvent($event, 'attempt_not_found');
            }

            // 3. Re-read current state (already locked)
            $attempt->refresh();

            // 4. Provider validation
            if ($attempt->provider !== $update->provider) {
                return $this->handleValidationError($event, $attempt, 'wrong_provider');
            }

            // 5. Amount/currency validation (for succeeded transitions)
            if ($update->normalizedStatus === PaymentAttemptStatus::Succeeded) {
                $validationError = $this->validateAmountCurrency($attempt, $update);
                if ($validationError !== null) {
                    return $this->handleValidationError($event, $attempt, $validationError);
                }
            }

            // 6. State-machine validation
            if (! $this->isTransitionAllowed($attempt->status, $update->normalizedStatus)) {
                // A local reconciliation_timeout is not a bank verdict: a
                // confirmed provider decline resolves it in place (same
                // status) by replacing the timeout failure fields with the
                // provider's own — before the ordinary same-status no-op.
                if ($this->isReconciliationTimeoutResolution($attempt, $update)) {
                    return $this->resolveReconciliationTimeout($event, $attempt, $update);
                }

                // Idempotent no-op for same status
                if ($attempt->status === $update->normalizedStatus) {
                    return $this->markEventProcessed($event, $attempt);
                }

                return $this->handleValidationError($event, $attempt, 'invalid_transition');
            }

            // 7. Update PaymentAttempt
            $this->updateAttempt($attempt, $update);

            // 8. Update BillingCycle
            $this->updateCycle($attempt, $update);

            // 9. Sync BillingSubscription horizon
            $this->syncSubscriptionHorizon($attempt);

            // 9a. Schedule/clear auto-renew charge marker from the Core horizon
            $this->syncAutoRenewSchedule($attempt, $update);

            // 9b. First terminal failure of a renewal attempt caused by
            // insufficient funds — stop auto-renew, keep paid entitlement.
            $this->handleRenewalInsufficientFunds($attempt, $update);

            // 9c. Renewal attempt failed on a technical issue
            // (reconciliation timeout) — PastDue with a bounded grace.
            $this->applyTechnicalRenewalGrace($attempt);

            // 9d. A renewal attempt succeeded — a surviving technical grace
            // window is no longer needed.
            $this->clearRenewalGrace($attempt, $update);

            // 10. Link ProviderEvent → PaymentAttempt
            $event->update(['payment_attempt_id' => $attempt->id]);

            // 11. Mark event processed
            return $this->markEventProcessed($event, $attempt);
        });
    }

    /**
     * Claim provider event with DB-safe atomic insert.
     *
     * Uses INSERT ... ON CONFLICT DO NOTHING to avoid TOCTOU race conditions.
     * After insert, fetches the existing or newly created event.
     */
    private function claimProviderEvent(
        ProviderStatusUpdate $update,
        ?string $signatureMetadata,
    ): ?ProviderEvent {
        $dedupKey = $this->buildDedupKey($update);

        $now = now();

        $rowData = [
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'provider' => $update->provider,
            'provider_event_id' => $update->providerEventId,
            'event_type' => $update->normalizedStatus->value,
            'dedup_key' => $dedupKey,
            'payload' => json_encode($update->raw),
            'signature_metadata' => $signatureMetadata ? json_encode(['signature' => $signatureMetadata]) : null,
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // INSERT ... ON CONFLICT DO NOTHING (DB-safe, no TOCTOU)
        $affected = DB::table('provider_events')->insertOrIgnore($rowData);

        if ($affected === 1) {
            // Newly inserted — return it
            return ProviderEvent::where('dedup_key', $dedupKey)->first();
        }

        // Duplicate — find the existing event (try dedup_key first, then provider_event_id)
        $existing = ProviderEvent::where('dedup_key', $dedupKey)->first();

        if ($existing === null && $update->providerEventId !== null) {
            $existing = ProviderEvent::where('provider', $update->provider)
                ->where('provider_event_id', $update->providerEventId)
                ->first();
        }

        Log::info('Duplicate provider event', [
            'provider' => $update->provider,
            'dedup_key' => $dedupKey,
        ]);

        return $existing ?? null;
    }

    /**
     * Build dedup key for provider event.
     *
     * Primary: provider:provider_event_id (when available)
     * Fallback: provider:identity:status:fingerprint (SHA-256 of sorted payload)
     */
    private function buildDedupKey(ProviderStatusUpdate $update): string
    {
        // Primary: (provider, provider_event_id) if available
        if ($update->providerEventId !== null) {
            return "{$update->provider}:{$update->providerEventId}";
        }

        // Fallback: deterministic key with payment/order identity + status + payload fingerprint
        $identity = $update->providerPaymentId ?? $update->internalOrderId ?? 'unknown';
        $fingerprint = $this->buildPayloadFingerprint($update->raw);

        return "{$update->provider}:{$identity}:{$update->normalizedStatus->value}:{$fingerprint}";
    }

    /**
     * Build SHA-256 fingerprint of payload with recursively sorted keys.
     */
    private function buildPayloadFingerprint(array $payload): string
    {
        $canonical = $this->canonicalize($payload);

        return hash('sha256', $canonical);
    }

    /**
     * Recursively sort associative array keys and encode as canonical JSON.
     */
    private function canonicalize(mixed $value): string
    {
        if (is_array($value)) {
            // Check if associative (has string keys)
            $isAssociative = array_keys($value) !== range(0, count($value) - 1);

            if ($isAssociative) {
                ksort($value);
                $pairs = [];
                foreach ($value as $k => $v) {
                    $pairs[] = json_encode($k) . ':' . $this->canonicalize($v);
                }

                return '{' . implode(',', $pairs) . '}';
            }

            $items = [];
            foreach ($value as $v) {
                $items[] = $this->canonicalize($v);
            }

            return '[' . implode(',', $items) . ']';
        }

        if (is_string($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value === true) {
            return 'true';
        }

        if ($value === false) {
            return 'false';
        }

        if ($value === null) {
            return 'null';
        }

        return json_encode($value);
    }

    /**
     * Locate PaymentAttempt with identity conflict detection.
     *
     * @return PaymentAttempt|string|null Attempt, error string for conflict, or null if not found
     */
    private function locateAttempt(ProviderStatusUpdate $update): PaymentAttempt|string|null
    {
        $attemptByPaymentId = null;
        $attemptByOrderId = null;

        // 1. Lookup by provider_payment_id
        if ($update->providerPaymentId !== null) {
            $attemptByPaymentId = PaymentAttempt::lockForUpdate()
                ->where('provider_payment_id', $update->providerPaymentId)
                ->first();
        }

        // 2. Lookup by internal_order_id
        if ($update->internalOrderId !== null) {
            $attemptByOrderId = PaymentAttempt::lockForUpdate()
                ->where('internal_order_id', $update->internalOrderId)
                ->first();
        }

        // 3. Identity conflict: both found but different attempts
        if ($attemptByPaymentId !== null && $attemptByOrderId !== null
            && $attemptByPaymentId->id !== $attemptByOrderId->id) {
            Log::warning('Identity conflict in provider event', [
                'provider_payment_id' => $update->providerPaymentId,
                'internal_order_id' => $update->internalOrderId,
                'attempt_by_payment_id' => $attemptByPaymentId->id,
                'attempt_by_order_id' => $attemptByOrderId->id,
            ]);

            return 'identity_conflict';
        }

        // 4. Use the found attempt (prefer provider_payment_id lookup)
        $attempt = $attemptByPaymentId ?? $attemptByOrderId;

        if ($attempt === null) {
            // 5. Legacy lookup — only for mirror/history, not transition authority
            if ($update->providerPaymentId !== null) {
                $legacy = Subscription::where('payment_id', $update->providerPaymentId)->first();
                if ($legacy !== null) {
                    Log::info('Legacy subscription found for provider payment ID', [
                        'provider_payment_id' => $update->providerPaymentId,
                        'legacy_subscription_id' => $legacy->id,
                    ]);
                }
            }

            return null;
        }

        // 6. Attach provider_payment_id if found by order_id and provider matches
        if ($attempt->provider_payment_id === null
            && $update->providerPaymentId !== null
            && $attempt->provider === $update->provider) {
            $attempt->update(['provider_payment_id' => $update->providerPaymentId]);
        }

        return $attempt;
    }

    /**
     * Validate amount and currency against attempt.
     */
    private function validateAmountCurrency(
        PaymentAttempt $attempt,
        ProviderStatusUpdate $update,
    ): ?string {
        if ($update->amount === null || $update->currency === null) {
            return 'missing_amount_or_currency';
        }

        if ($update->amount !== $attempt->amount) {
            return 'amount_mismatch';
        }

        if ($update->currency !== $attempt->currency) {
            return 'currency_mismatch';
        }

        return null;
    }

    /**
     * Check if transition is allowed.
     */
    private function isTransitionAllowed(
        PaymentAttemptStatus $from,
        PaymentAttemptStatus $to,
    ): bool {
        return in_array($to->value, self::ALLOWED_TRANSITIONS[$from->value] ?? [], true);
    }

    /**
     * A confirmed provider decline (FailedTerminal event from webhook or
     * reconciliation — the local age-release never enters transition())
     * may resolve an attempt still carrying the local reconciliation_timeout.
     */
    private function isReconciliationTimeoutResolution(
        PaymentAttempt $attempt,
        ProviderStatusUpdate $update,
    ): bool {
        return $attempt->status === PaymentAttemptStatus::FailedTerminal
            && $attempt->failure_category === 'reconciliation_timeout'
            && $update->normalizedStatus === PaymentAttemptStatus::FailedTerminal;
    }

    /**
     * Resolve a local reconciliation_timeout in place: the provider's
     * confirmed decline replaces the timeout failure fields while the status
     * stays failed_terminal. Deliberately skips steps 8–9d of the core path:
     * horizon, renewal markers, grace and notifications already ran when the
     * timeout was released, so re-running them would repeat side effects and
     * re-open or extend the grace window. Attempt metadata and the auto-renew
     * dispatch marker stay untouched. The timeout category itself is never
     * treated as a bank confirmation — only this provider verdict replaces it.
     */
    private function resolveReconciliationTimeout(
        ProviderEvent $event,
        PaymentAttempt $attempt,
        ProviderStatusUpdate $update,
    ): array {
        $this->updateAttempt($attempt, $update);

        return $this->markEventProcessed($event, $attempt);
    }

    /**
     * Update PaymentAttempt with new status.
     */
    private function updateAttempt(
        PaymentAttempt $attempt,
        ProviderStatusUpdate $update,
    ): void {
        $updateData = [
            'status' => $update->normalizedStatus,
            'finished_at' => now(),
        ];

        // Attach provider_payment_id if missing
        if ($attempt->provider_payment_id === null && $update->providerPaymentId !== null) {
            $updateData['provider_payment_id'] = $update->providerPaymentId;
        }

        // Set failure info for terminal failures
        if ($update->normalizedStatus === PaymentAttemptStatus::FailedTerminal) {
            $updateData['failure_code'] = $update->failureCode;
            $updateData['failure_category'] = $update->failureCategory ?? 'provider_failed';
            $updateData['failure_message'] = $update->failureMessage;
        }

        // A confirmed success supersedes any earlier local timeout or
        // decline: stale failure fields must not survive on a Paid attempt.
        if ($update->normalizedStatus === PaymentAttemptStatus::Succeeded) {
            $updateData['failure_code'] = null;
            $updateData['failure_category'] = null;
            $updateData['failure_message'] = null;
        }

        $attempt->update($updateData);
    }

    /**
     * Update BillingCycle based on attempt status.
     */
    private function updateCycle(
        PaymentAttempt $attempt,
        ProviderStatusUpdate $update,
    ): void {
        $cycle = $attempt->billingCycle;
        if ($cycle === null) {
            return;
        }

        $newCycleStatus = match ($update->normalizedStatus) {
            PaymentAttemptStatus::Succeeded => BillingCycleStatus::Paid,
            PaymentAttemptStatus::FailedTerminal => BillingCycleStatus::Failed,
            PaymentAttemptStatus::Refunded => BillingCycleStatus::Refunded,
            default => null,
        };

        if ($newCycleStatus !== null && $cycle->status !== $newCycleStatus) {
            // Check for multiple successful attempts
            if ($newCycleStatus === BillingCycleStatus::Paid && $cycle->status === BillingCycleStatus::Paid) {
                Log::warning('Multiple successful attempts in same cycle', [
                    'cycle_id' => $cycle->id,
                    'attempt_id' => $attempt->id,
                    'existing_cycle_status' => $cycle->status,
                ]);
            }

            $cycle->update(['status' => $newCycleStatus]);
        }

        // Mirror to legacy in isolated savepoint
        $this->mirrorToLegacy($attempt, $update);
    }

    /**
     * Mirror Core status to legacy subscription.
     *
     * Uses nested DB::transaction (savepoint) so that SQL failure in legacy mirror
     * rolls back only the savepoint, NOT the outer Core transition.
     */
    private function mirrorToLegacy(
        PaymentAttempt $attempt,
        ProviderStatusUpdate $update,
    ): void {
        $metadata = $attempt->metadata ?? [];
        $legacySubscriptionId = $metadata['legacy_subscription_id'] ?? null;

        if ($legacySubscriptionId === null) {
            Log::info('No legacy subscription to mirror', [
                'attempt_id' => $attempt->id,
            ]);

            return;
        }

        try {
            DB::transaction(function () use ($legacySubscriptionId, $update, $attempt) {
                $legacy = Subscription::find($legacySubscriptionId);
                if ($legacy === null) {
                    Log::warning('Legacy subscription not found for mirror', [
                        'legacy_subscription_id' => $legacySubscriptionId,
                    ]);

                    return;
                }

                $newStatus = match ($update->normalizedStatus) {
                    PaymentAttemptStatus::Succeeded => 'active',
                    PaymentAttemptStatus::FailedTerminal => 'failed',
                    PaymentAttemptStatus::Refunded => 'refunded',
                    default => null,
                };

                if ($newStatus !== null && in_array($legacy->status, ['pending', 'active', 'failed'], true)) {
                    $updateData = ['status' => $newStatus];

                    // Update payment_id on success
                    if ($update->normalizedStatus === PaymentAttemptStatus::Succeeded && $update->providerPaymentId !== null) {
                        $updateData['payment_id'] = $update->providerPaymentId;
                    }

                    $legacy->update($updateData);
                }
            });
        } catch (\Throwable $e) {
            // Mirror failure must NOT rollback Core transition
            // (only the savepoint was rolled back)
            Log::error('Legacy mirror failed', [
                'attempt_id' => $attempt->id,
                'legacy_subscription_id' => $legacySubscriptionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sync BillingSubscription horizon from cycle.
     */
    private function syncSubscriptionHorizon(PaymentAttempt $attempt): void
    {
        $cycle = $attempt->billingCycle;
        if ($cycle === null) {
            return;
        }

        $sub = $cycle->billingSubscription;
        if ($sub === null) {
            return;
        }

        $latestEnd = BillingCycle::where('billing_subscription_id', $sub->id)
            ->where('status', BillingCycleStatus::Paid)
            ->max('period_end');

        if ($latestEnd !== null) {
            $sub->update([
                'current_period_end' => $latestEnd,
                'status' => \App\Enums\BillingSubscriptionStatus::Active,
            ]);
        } else {
            // No paid cycles remain — subscription should be canceled
            $sub->update([
                'status' => \App\Enums\BillingSubscriptionStatus::Canceled,
            ]);
        }
    }

    /**
     * Maintain next_charge_at from the Billing Core horizon.
     *
     * The source of truth is the actual current_period_end (never now()+period):
     * a successful transition on a consented, non-canceled subscription schedules
     * the marker; a canceled subscription (e.g. last granting cycle refunded)
     * clears it. Payment method presence is deliberately not checked here —
     * that belongs to the renewal engine before a real charge.
     */
    private function syncAutoRenewSchedule(PaymentAttempt $attempt, ProviderStatusUpdate $update): void
    {
        $cycle = $attempt->billingCycle;
        $sub = $cycle?->billingSubscription;

        if ($sub === null) {
            return;
        }

        if ($sub->status === \App\Enums\BillingSubscriptionStatus::Canceled) {
            if ($sub->next_charge_at !== null) {
                $sub->update(['next_charge_at' => null]);
            }

            return;
        }

        if ($update->normalizedStatus !== PaymentAttemptStatus::Succeeded) {
            return;
        }

        if ($sub->auto_renew_consent_at === null
            || $sub->renewal_period_months === null
            || $sub->cancel_at_period_end !== false
            || $sub->current_period_end === null) {
            return;
        }

        if ($sub->next_charge_at === null || ! $sub->next_charge_at->equalTo($sub->current_period_end)) {
            $sub->update(['next_charge_at' => $sub->current_period_end]);
        }
    }

    /**
     * Handle unmatched event.
     */
    private function handleUnmatchedEvent(
        ProviderEvent $event,
        string $error,
    ): array {
        $event->update([
            'processing_error' => $error,
            'processed_at' => now(),
        ]);

        Log::warning('Provider event unmatched', [
            'event_id' => $event->id,
            'provider' => $event->provider,
            'error' => $error,
        ]);

        return ['success' => false, 'error' => $error];
    }

    /**
     * Handle validation error.
     */
    private function handleValidationError(
        ProviderEvent $event,
        PaymentAttempt $attempt,
        string $error,
    ): array {
        $event->update([
            'payment_attempt_id' => $attempt->id,
            'processing_error' => $error,
            'processed_at' => now(),
        ]);

        Log::warning('Provider event validation failed', [
            'event_id' => $event->id,
            'attempt_id' => $attempt->id,
            'error' => $error,
        ]);

        return ['success' => false, 'error' => $error];
    }

    /**
     * Mark event as processed.
     */
    private function markEventProcessed(
        ProviderEvent $event,
        PaymentAttempt $attempt,
    ): array {
        $event->update([
            'payment_attempt_id' => $attempt->id,
            'processed_at' => now(),
            'processing_error' => null,
        ]);

        return ['success' => true];
    }

    /**
     * Renewal attempt failed on insufficient funds (ErrorCode 103/116/1051):
     * stop auto-renew, but never revoke the already-paid entitlement early,
     * never touch current_period_end and never touch the payment method
     * (an empty card is not a revoked card). Other failure categories and
     * non-renewal attempts are out of scope — they keep legacy behavior.
     *
     * Only runs on the actual transition into failed_terminal: duplicate
     * events are dropped at claim and same-status events at the state
     * machine, both before this point.
     */
    private function handleRenewalInsufficientFunds(
        PaymentAttempt $attempt,
        ProviderStatusUpdate $update,
    ): void {
        if ($attempt->status !== PaymentAttemptStatus::FailedTerminal
            || $update->normalizedStatus !== PaymentAttemptStatus::FailedTerminal
            || ($attempt->metadata['renewal'] ?? null) !== true
            || $attempt->failure_category !== 'insufficient_funds') {
            return;
        }

        $sub = $attempt->billingCycle?->billingSubscription;
        if ($sub === null) {
            return;
        }

        $periodEnded = $sub->current_period_end !== null
            && $sub->current_period_end->lte(now());

        $updates = [
            'next_charge_at' => null,
            'cancel_at_period_end' => true,
            'grace_until' => null,
        ];

        // Paid entitlement is never revoked early: only mark expired when the
        // paid period has actually ended, otherwise leave status untouched.
        if ($periodEnded) {
            $updates['status'] = BillingSubscriptionStatus::Expired;
        }

        $sub->update($updates);

        $this->notifyRenewalInsufficientFunds($attempt);
    }

    /**
     * Renewal attempt ended as failed_terminal for a technical reason
     * (reconciliation_timeout): keep the user on Pro for a bounded window
     * without any new charge — status=PastDue, grace_until =
     * max(current_period_end, now()) + billing.renewal.technical_grace_days.
     *
     * Never touches cancel_at_period_end, current_period_start/end or the
     * payment method, never runs for checkout attempts (metadata.renewal
     * must be true) and never for other failure categories — insufficient
     * funds owns its own terminal flow above. Called both from the core
     * transition path and from reconciliation age-release.
     *
     * An already running grace window is never extended: a repeated
     * technical failure (e.g. the single technical retry failing again)
     * keeps the original grace_until so the window stays bounded.
     */
    public function applyTechnicalRenewalGrace(PaymentAttempt $attempt): void
    {
        if ($attempt->status !== PaymentAttemptStatus::FailedTerminal
            || ($attempt->metadata['renewal'] ?? null) !== true
            || $attempt->failure_category !== 'reconciliation_timeout') {
            return;
        }

        $sub = $attempt->billingCycle?->billingSubscription;
        if ($sub === null) {
            return;
        }

        $updates = [
            'status' => BillingSubscriptionStatus::PastDue,
            'next_charge_at' => null,
        ];

        if ($sub->grace_until === null) {
            $now = now();
            $base = $sub->current_period_end !== null && $sub->current_period_end->gt($now)
                ? $sub->current_period_end
                : $now;

            $updates['grace_until'] = $base->copy()->addDays((int) config('billing.renewal.technical_grace_days'));
        }

        $sub->update($updates);
    }

    /**
     * A successful renewal transition ends any technical grace window that
     * a previous technical failure opened (the horizon itself is already
     * synced above). Checkout attempts never carry metadata.renewal and are
     * therefore untouched.
     */
    private function clearRenewalGrace(
        PaymentAttempt $attempt,
        ProviderStatusUpdate $update,
    ): void {
        if ($update->normalizedStatus !== PaymentAttemptStatus::Succeeded
            || ($attempt->metadata['renewal'] ?? null) !== true) {
            return;
        }

        $sub = $attempt->billingCycle?->billingSubscription;
        if ($sub === null || $sub->grace_until === null) {
            return;
        }

        $sub->update(['grace_until' => null]);
    }

    /**
     * One notification per attempt. The transition transaction only registers
     * a DB::afterCommit callback — no external calls and no NotificationLog
     * claim inside the transaction. The callback checks NotificationLog first
     * (duplicate safety), performs the send, and records markSent only after
     * the post-commit attempt completed: rollback → no row and no send,
     * committed row always means a real send attempt, never a pre-commit claim.
     */
    private function notifyRenewalInsufficientFunds(PaymentAttempt $attempt): void
    {
        $workspaceId = $attempt->billingCycle?->workspace_id;
        if ($workspaceId === null) {
            return;
        }

        $type = 'renewal_insufficient_funds';
        $periodKey = (string) $attempt->id;

        DB::afterCommit(function () use ($workspaceId, $type, $periodKey): void {
            if (NotificationLog::hasBeenSent($workspaceId, $type, $periodKey)) {
                return;
            }

            try {
                $owner = Workspace::find($workspaceId)?->owner;
                if ($owner === null) {
                    return;
                }

                app(MasterNotificationService::class)
                    ->sendToMaster($owner, RenewalInsufficientFundsNotification::TEXT);

                if (! $owner->is_blocked) {
                    $owner->notify(new RenewalInsufficientFundsNotification);
                }

                NotificationLog::markSent($workspaceId, $type, $periodKey);
            } catch (\Throwable $e) {
                Log::error('Renewal insufficient funds notification failed', [
                    'workspace_id' => $workspaceId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
