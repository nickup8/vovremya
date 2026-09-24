<?php

namespace App\Console\Commands;

use App\Enums\PaymentAttemptStatus;
use App\Models\PaymentAttempt;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanupPendingSubscriptions extends Command
{
    protected $signature = 'subscriptions:cleanup-pending';

    protected $description = 'Mark abandoned pending subscriptions as failed (older than configured hours), guarded against in-flight Core attempts';

    public function handle(): int
    {
        $hours = (int) config('booking.cleanup_pending_subscription_hours', 2);
        $threshold = now()->subHours($hours);

        $candidates = Subscription::query()
            ->where('status', 'pending')
            ->where('created_at', '<', $threshold)
            ->orderBy('created_at')
            ->cursor();

        $counters = [
            'candidates' => 0,
            'failed_safely' => 0,
            'skipped_in_flight' => 0,
            'skipped_uncertain' => 0,
            'anomalies' => 0,
            'errors' => 0,
        ];

        foreach ($candidates as $legacy) {
            $counters['candidates']++;

            try {
                $result = DB::transaction(fn () => $this->processLegacy($legacy));
                $counters[$result]++;
            } catch (\Throwable $e) {
                $counters['errors']++;
                Log::warning('cleanup-pending: error processing legacy row', [
                    'subscription_id' => $legacy->id,
                    'workspace_id' => $legacy->workspace_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('cleanup-pending: completed', $counters);

        $this->info("Candidates: {$counters['candidates']}");
        $this->info("Failed safely: {$counters['failed_safely']}");
        $this->info("Skipped in-flight: {$counters['skipped_in_flight']}");
        $this->info("Skipped uncertain: {$counters['skipped_uncertain']}");
        $this->info("Anomalies: {$counters['anomalies']}");
        $this->info("Errors: {$counters['errors']}");

        return self::SUCCESS;
    }

    private function processLegacy(Subscription $legacy): string
    {
        // Re-fetch inside transaction with lock
        $locked = Subscription::query()
            ->where('id', $legacy->id)
            ->lockForUpdate()
            ->first();

        if ($locked === null || $locked->status !== 'pending') {
            return 'skipped_in_flight';
        }

        $attempt = $this->findRelevantAttempt($locked);

        if ($attempt === null) {
            return $this->handleNoAttempt($locked);
        }

        return match ($attempt->status) {
            PaymentAttemptStatus::Succeeded => $this->handleSucceededAnomaly($locked, $attempt),
            PaymentAttemptStatus::Processing => $this->skipInFlight($locked, $attempt, 'processing'),
            PaymentAttemptStatus::Unknown => $this->skipInFlight($locked, $attempt, 'unknown'),
            PaymentAttemptStatus::Created => $this->skipInFlight($locked, $attempt, 'created'),
            PaymentAttemptStatus::FailedTerminal => $this->failLegacy($locked, $attempt),
            PaymentAttemptStatus::Refunded => $this->skipAnomaly($locked, $attempt, 'refunded'),
            PaymentAttemptStatus::FailedRetryable => $this->skipAnomaly($locked, $attempt, 'failed_retryable'),
            PaymentAttemptStatus::PartiallyRefunded => $this->skipAnomaly($locked, $attempt, 'partially_refunded'),
        };
    }

    private function findRelevantAttempt(Subscription $legacy): ?PaymentAttempt
    {
        $attempts = collect();

        // 1. provider_payment_id match
        if ($legacy->payment_id !== null) {
            $match = PaymentAttempt::where('provider_payment_id', $legacy->payment_id)->first();
            if ($match !== null) {
                $attempts->push($match);
            }

            // 2. internal_order_id match for old projected rows
            $match = PaymentAttempt::where('internal_order_id', $legacy->payment_id)->first();
            if ($match !== null) {
                $attempts->push($match);
            }
        }

        // 3. metadata.legacy_subscription_id
        $metadataMatch = PaymentAttempt::where('metadata->legacy_subscription_id', $legacy->id)->first();
        if ($metadataMatch !== null) {
            $attempts->push($metadataMatch);
        }

        // 4. billing_cycle.legacy_subscription_id link
        $cycleMatch = PaymentAttempt::whereHas('billingCycle', function ($q) use ($legacy) {
            $q->where('legacy_subscription_id', $legacy->id);
        })->first();
        if ($cycleMatch !== null) {
            $attempts->push($cycleMatch);
        }

        $attempts = $attempts->unique('id');

        if ($attempts->isEmpty()) {
            return null;
        }

        if ($attempts->count() === 1) {
            return $attempts->first();
        }

        // Multiple attempts: prefer latest by attempt_number, but a terminal succeeded wins
        $succeeded = $attempts->firstWhere('status', PaymentAttemptStatus::Succeeded);
        if ($succeeded !== null) {
            return $succeeded;
        }

        return $attempts->sortByDesc('attempt_number')->first();
    }

    private function handleNoAttempt(Subscription $legacy): string
    {
        if ($legacy->payment_id !== null) {
            Log::warning('cleanup-pending: no Core attempt found but payment_id exists (skip)', [
                'subscription_id' => $legacy->id,
                'workspace_id' => $legacy->workspace_id,
                'payment_id' => $legacy->payment_id,
            ]);

            return 'anomalies';
        }

        // Pre-Core stale pending row with no provider intent — safe to fail
        $legacy->update(['status' => 'failed']);

        return 'failed_safely';
    }

    private function handleSucceededAnomaly(Subscription $legacy, PaymentAttempt $attempt): string
    {
        Log::warning('cleanup-pending: anomaly — legacy=pending but attempt=succeeded', [
            'subscription_id' => $legacy->id,
            'workspace_id' => $legacy->workspace_id,
            'attempt_id' => $attempt->id,
            'attempt_status' => $attempt->status->value,
        ]);

        return 'anomalies';
    }

    private function skipInFlight(Subscription $legacy, PaymentAttempt $attempt, string $reason): string
    {
        Log::info("cleanup-pending: skip — {$reason}", [
            'subscription_id' => $legacy->id,
            'workspace_id' => $legacy->workspace_id,
            'attempt_id' => $attempt->id,
            'attempt_status' => $attempt->status->value,
        ]);

        return 'skipped_in_flight';
    }

    private function failLegacy(Subscription $legacy, PaymentAttempt $attempt): string
    {
        Log::info('cleanup-pending: legacy→failed (attempt failed_terminal)', [
            'subscription_id' => $legacy->id,
            'workspace_id' => $legacy->workspace_id,
            'attempt_id' => $attempt->id,
        ]);

        $legacy->update(['status' => 'failed']);

        return 'failed_safely';
    }

    private function skipAnomaly(Subscription $legacy, PaymentAttempt $attempt, string $reason): string
    {
        Log::warning("cleanup-pending: skip — {$reason}", [
            'subscription_id' => $legacy->id,
            'workspace_id' => $legacy->workspace_id,
            'attempt_id' => $attempt->id,
            'attempt_status' => $attempt->status->value,
        ]);

        return 'skipped_uncertain';
    }
}
