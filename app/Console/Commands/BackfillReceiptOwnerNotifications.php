<?php

namespace App\Console\Commands;

use App\Enums\PaymentEnvironment;
use App\Enums\PaymentReceiptStatus;
use App\Models\PaymentReceipt;
use App\Notifications\SystemNotification;
use Illuminate\Console\Command;

/**
 * One-off migration aid: after the cabinet receipt list is removed, owners
 * of previously attached receipts must still reach their link — this gives
 * each such receipt a database notification with the current FNS URL.
 *
 * Idempotent by receipt id (data.receipt_id), never by title:
 * - existing owner notifications (old text, other kinds) are untouched;
 * - a re-run creates nothing for receipts already notified;
 * - messenger channels are never touched — no mass sends for old receipts.
 *
 * Do NOT run on production without review; see the delivery report for the
 * application order. --dry-run only lists what would be created.
 */
class BackfillReceiptOwnerNotifications extends Command
{
    protected $signature = 'receipts:backfill-owner-notifications {--dry-run}';

    protected $description = 'Создать владельцам database-уведомления со ссылкой для ранее прикреплённых чеков';

    public function handle(): int
    {
        $receipts = PaymentReceipt::query()
            ->with('paymentAttempt.billingCycle.workspace.owner')
            ->where('status', PaymentReceiptStatus::Attached)
            ->whereNotNull('receipt_url')
            ->whereHas('paymentAttempt', function ($q) {
                $q->where('provider', 'tbank')
                    ->where('payment_env', PaymentEnvironment::Live);
            })
            ->orderBy('created_at')
            ->get();

        $created = 0;
        $skippedNoOwner = 0;
        $skippedAlreadyNotified = 0;

        foreach ($receipts as $receipt) {
            $owner = $receipt->paymentAttempt?->billingCycle?->workspace?->owner;

            if ($owner === null || $receipt->receipt_url === null) {
                $skippedNoOwner++;

                continue;
            }

            // Deduplication by receipt id — titles are shared across
            // notifications and must never be used as a key. notifications
            // .data is a TEXT column, so the JSON check happens in PHP.
            $alreadyNotified = $owner->notifications()
                ->where('type', SystemNotification::class)
                ->cursor()
                ->contains(fn ($n) => ($n->data['receipt_id'] ?? null) === $receipt->getKey());

            if ($alreadyNotified) {
                $skippedAlreadyNotified++;

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line(sprintf(
                    '[dry-run] would notify %s (receipt %s): %s',
                    $owner->email,
                    $receipt->getKey(),
                    $receipt->receipt_url,
                ));
                $created++;

                continue;
            }

            $owner->notify(new SystemNotification(
                title: 'Чек об оплате подписки',
                body: 'Чек готов. Откройте его по ссылке ниже',
                systemMessageId: null,
                actionUrl: $receipt->receipt_url,
                actionLabel: 'Открыть чек',
                receiptId: $receipt->getKey(),
            ));

            $created++;
            $this->line("✓ {$owner->email} ← receipt {$receipt->getKey()}");
        }

        $this->info(sprintf(
            '%s: %d %s, %d уже уведомлены, %d без владельца (всего найдено %d).',
            $this->option('dry-run') ? 'Dry-run' : 'Готово',
            $created,
            $this->option('dry-run') ? 'будет создано' : 'создано',
            $skippedAlreadyNotified,
            $skippedNoOwner,
            $receipts->count(),
        ));

        return self::SUCCESS;
    }
}
