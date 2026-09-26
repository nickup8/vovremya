<?php

namespace App\Services\Payment;

use App\Enums\BillingCycleStatus;
use App\Enums\PaymentAttemptStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\PaymentAttempt;
use App\Models\ProviderEvent;
use App\Models\Subscription;
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
            // 1. Provider event claim/dedup
            $event = $this->claimProviderEvent($update, $signatureMetadata);
            if ($event === null) {
                // Duplicate event - already processed
                return ['success' => true];
            }

            // 2. Locate + lock PaymentAttempt FOR UPDATE
            $attempt = $this->locateAttempt($update);
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

            // 10. Link ProviderEvent → PaymentAttempt
            $event->update(['payment_attempt_id' => $attempt->id]);

            // 11. Mark event processed
            return $this->markEventProcessed($event, $attempt);
        });
    }

    /**
     * Claim provider event with idempotency.
     */
    private function claimProviderEvent(
        ProviderStatusUpdate $update,
        ?string $signatureMetadata,
    ): ?ProviderEvent {
        $dedupKey = $this->buildDedupKey($update);

        // Check if event already exists (avoid unique violation in transaction)
        $existing = ProviderEvent::where('dedup_key', $dedupKey)->first();
        if ($existing !== null) {
            Log::info('Duplicate provider event', [
                'provider' => $update->provider,
                'dedup_key' => $dedupKey,
            ]);

            return null;
        }

        // Create event
        return ProviderEvent::create([
            'provider' => $update->provider,
            'provider_event_id' => $update->providerEventId,
            'event_type' => $update->normalizedStatus->value,
            'dedup_key' => $dedupKey,
            'payload' => $update->raw,
            'signature_metadata' => $signatureMetadata ? ['signature' => $signatureMetadata] : null,
            'received_at' => now(),
        ]);
    }

    /**
     * Build dedup key for provider event.
     */
    private function buildDedupKey(ProviderStatusUpdate $update): string
    {
        // Primary: (provider, provider_event_id) if available
        if ($update->providerEventId !== null) {
            return "{$update->provider}:{$update->providerEventId}";
        }

        // Fallback: deterministic key with payment/order identity + status
        $identity = $update->providerPaymentId ?? $update->internalOrderId ?? 'unknown';

        return "{$update->provider}:{$identity}:{$update->normalizedStatus->value}";
    }

    /**
     * Locate PaymentAttempt by provider payment ID or internal order ID.
     */
    private function locateAttempt(ProviderStatusUpdate $update): ?PaymentAttempt
    {
        // 1. Primary lookup: provider_payment_id (regardless of provider for now)
        if ($update->providerPaymentId !== null) {
            $attempt = PaymentAttempt::lockForUpdate()
                ->where('provider_payment_id', $update->providerPaymentId)
                ->first();

            if ($attempt !== null) {
                return $attempt;
            }
        }

        // 2. Secondary lookup: internal_order_id
        if ($update->internalOrderId !== null) {
            $attempt = PaymentAttempt::lockForUpdate()
                ->where('internal_order_id', $update->internalOrderId)
                ->first();

            if ($attempt !== null) {
                // Attach provider_payment_id if missing
                if ($attempt->provider_payment_id === null && $update->providerPaymentId !== null) {
                    $attempt->update(['provider_payment_id' => $update->providerPaymentId]);
                }

                return $attempt;
            }
        }

        // 3. Legacy lookup - only for mirror/history, not for transition authority
        if ($update->providerPaymentId !== null) {
            $legacy = Subscription::where('payment_id', $update->providerPaymentId)->first();
            if ($legacy !== null) {
                Log::info('Legacy subscription found for provider payment ID', [
                    'provider_payment_id' => $update->providerPaymentId,
                    'legacy_subscription_id' => $legacy->id,
                ]);
                // Don't use legacy for transition authority - return null
            }
        }

        return null;
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
            $updateData['failure_category'] = 'provider_failed';
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

        // Mirror to legacy
        $this->mirrorToLegacy($attempt, $update);
    }

    /**
     * Mirror Core status to legacy subscription.
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
        } catch (\Throwable $e) {
            // Mirror failure must NOT rollback Core transition
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
}
