<?php

namespace App\Console\Commands;

use App\Services\Payment\PaymentReconciliationService;
use Illuminate\Console\Command;

class ReconcilePaymentAttempts extends Command
{
    protected $signature = 'billing:reconcile-payment-attempts';

    protected $description = 'Reconcile stale payment attempts with provider status';

    public function handle(PaymentReconciliationService $reconciliationService): int
    {
        $this->info('Starting payment reconciliation...');

        $result = $reconciliationService->reconcile();

        $this->info("Reconciliation complete: {$result['processed']} processed, {$result['errors']} errors");

        return $result['errors'] > 0 ? 1 : 0;
    }
}
