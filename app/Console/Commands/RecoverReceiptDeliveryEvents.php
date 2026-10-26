<?php

namespace App\Console\Commands;

use App\Jobs\SendReceiptUrlNotificationJob;
use App\Models\ReceiptDeliveryEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Recovery for the receipt delivery outbox.
 *
 * attachUrl writes the delivery intent (receipt_delivery_events) inside the
 * change transaction and enqueues the job right after the commit. If that
 * enqueue never happened — the process died between commit and dispatch, or
 * the queue push failed — the row keeps dispatched_at = NULL and this
 * command re-dispatches it, so a committed intent is never lost.
 *
 * Rows already dispatched (dispatched_at set) are owned by the queue and its
 * bounded retries; this command never re-sends those, so it cannot duplicate
 * a delivery on its own. The min-age guard simply keeps it away from rows
 * whose after-commit dispatch is still in progress.
 */
class RecoverReceiptDeliveryEvents extends Command
{
    protected $signature = 'receipts:recover-delivery-events
        {--min-age=2 : Minutes an event may sit undispatched before recovery touches it}';

    protected $description = 'Поставить в очередь события доставки чека, не отправленные после коммита';

    public function handle(): int
    {
        $events = ReceiptDeliveryEvent::query()
            ->whereNull('dispatched_at')
            ->where('created_at', '<=', now()->subMinutes(max(0, (int) $this->option('min-age'))))
            ->orderBy('created_at')
            ->get();

        $dispatched = 0;
        $failed = 0;

        foreach ($events as $event) {
            try {
                SendReceiptUrlNotificationJob::dispatch($event->getKey());
                $event->update(['dispatched_at' => now()]);
                $dispatched++;
            } catch (\Throwable $e) {
                // Queue still unavailable: dispatched_at stays NULL, the next
                // scheduled run tries again — the intent remains in the DB.
                $failed++;
                Log::error('Receipt delivery event recovery failed to enqueue', [
                    'event_id' => $event->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info(sprintf(
            'Восстановление: %d поставлено в очередь, %d ошибок (найдено %d необработанных).',
            $dispatched,
            $failed,
            $events->count(),
        ));

        return self::SUCCESS;
    }
}
