<?php

namespace App\Console\Commands;

use App\Enums\BillingSubscriptionStatus;
use App\Models\BillingSubscription;
use App\Services\Billing\RenewalPaymentExecutor;
use App\Services\Billing\RenewalService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Manual orchestration for billing renewals (A2.4a). No scheduler entry —
 * every mutation / external HTTP call requires an explicit --execute.
 *
 * --retry-technical switches the candidate set to PastDue subscriptions
 * inside an active technical grace window and creates exactly one safe
 * technical retry attempt (v1) instead of a regular renewal prepare.
 */
class ProcessBillingRenewals extends Command
{
    protected $signature = 'billing:process-renewals
        {--execute : Actually run renewal prepare + charge}
        {--retry-technical : Retry a technical renewal failure once while the grace window is open}
        {--subscription= : Process only one BillingSubscription}
        {--limit=50 : Max subscriptions per run}';

    protected $description = 'Process due auto-renewals manually (dry-run without --execute)';

    public function handle(
        RenewalService $renewalService,
        RenewalPaymentExecutor $executor,
    ): int {
        $retryTechnical = (bool) $this->option('retry-technical');
        $query = $retryTechnical ? $this->technicalRetryCandidates() : $this->dueCandidates();

        if (! $this->option('execute')) {
            $this->info('Candidates: '.$query->count());

            return self::SUCCESS;
        }

        $subscriptions = $query
            ->orderBy($retryTechnical ? 'grace_until' : 'next_charge_at')
            ->limit((int) $this->option('limit'))
            ->get();

        $processed = 0;
        $noop = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($subscriptions as $subscription) {
            try {
                $attempt = $retryTechnical
                    ? ($renewalService->prepareTechnicalRetry($subscription)
                        ?? $renewalService->resumeTechnicalRetry($subscription))
                    : $renewalService->prepare($subscription);

                if ($attempt === null) {
                    $skipped++;
                    continue;
                }

                $result = $executor->execute($attempt);

                if (($result['noop'] ?? false) === true) {
                    $noop++;
                } else {
                    $processed++;
                }
            } catch (Throwable $e) {
                $failed++;
                Log::error('Billing renewal processing failed', [
                    'billing_subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
                // One failing subscription must not stop the batch.
            }
        }

        $this->info('Candidates: '.$subscriptions->count());
        $this->info("Processed: {$processed}");
        $this->info("No-op: {$noop}");
        $this->info("Skipped: {$skipped}");
        $this->info("Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function dueCandidates(): Builder
    {
        $query = BillingSubscription::query()
            ->where('status', BillingSubscriptionStatus::Active)
            ->whereNotNull('auto_renew_consent_at')
            ->whereNotNull('renewal_period_months')
            ->where('cancel_at_period_end', false)
            ->whereNotNull('next_charge_at')
            ->where('next_charge_at', '<=', now());

        return $this->applySubscriptionOption($query);
    }

    /**
     * PastDue subscriptions still inside the technical grace window — the
     * only set prepareTechnicalRetry() may ever consider.
     */
    private function technicalRetryCandidates(): Builder
    {
        $query = BillingSubscription::query()
            ->where('status', BillingSubscriptionStatus::PastDue)
            ->whereNotNull('grace_until')
            ->where('grace_until', '>', now())
            ->where('cancel_at_period_end', false);

        return $this->applySubscriptionOption($query);
    }

    private function applySubscriptionOption(Builder $query): Builder
    {
        if ($this->option('subscription') !== null) {
            $subscriptionId = (string) $this->option('subscription');

            // Malformed ID can't match a row — guard against a uuid cast error.
            $query->when(
                Str::isUuid($subscriptionId),
                fn (Builder $q) => $q->whereKey($subscriptionId),
                fn (Builder $q) => $q->whereRaw('1 = 0'),
            );
        }

        return $query;
    }
}
