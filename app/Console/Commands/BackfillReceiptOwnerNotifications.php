<?php

namespace App\Console\Commands;

use App\Enums\PaymentEnvironment;
use App\Enums\PaymentReceiptStatus;
use App\Models\PaymentReceipt;
use App\Notifications\SystemNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off migration aid: after the cabinet receipt list is removed, owners
 * of previously attached receipts must still reach their link — this gives
 * each such receipt a database notification with the current FNS URL.
 *
 * Concurrency: check-then-create runs per receipt inside one transaction
 * that first takes the receipt row lock (the same lock attachUrl uses), so a
 * concurrent attachUrl cannot slip between the dedupe check and the create:
 * either the attach commits first (its own change notification already
 * carries the receipt id and the fresh URL, so we skip), or we create first
 * and the attach then adds its own update notification. The URL is re-read
 * under the lock — never the snapshot taken before a concurrent replacement.
 *
 * Idempotency & scope:
 * - dedupe by receipt id (data.receipt_id), never by title: existing owner
 *   notifications (old text, other kinds) are untouched and never deleted;
 * - a re-run creates nothing for receipts already notified;
 * - messenger channels are never touched — no events, no jobs, no HTTP for
 *   backfilled receipts.
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
        $candidates = PaymentReceipt::query()
            ->where('status', PaymentReceiptStatus::Attached)
            ->whereNotNull('receipt_url')
            ->whereHas('paymentAttempt', function ($q) {
                $q->where('provider', 'tbank')
                    ->where('payment_env', PaymentEnvironment::Live);
            })
            ->orderBy('created_at')
            ->pluck('id');

        $created = 0;
        $skippedNoOwner = 0;
        $skippedAlreadyNotified = 0;
        $skippedVanished = 0;

        foreach ($candidates as $candidateId) {
            $result = DB::transaction(function () use ($candidateId): string {
                // Lock first, then re-read: the snapshot from the candidate
                // query may already be outdated by a concurrent attachUrl.
                $receipt = PaymentReceipt::query()
                    ->whereKey($candidateId)
                    ->lockForUpdate()
                    ->first();

                if ($receipt === null
                    || $receipt->status !== PaymentReceiptStatus::Attached
                    || $receipt->receipt_url === null) {
                    return 'vanished';
                }

                $owner = $receipt->paymentAttempt?->billingCycle?->workspace?->owner;

                if ($owner === null) {
                    return 'no_owner';
                }

                // Dedupe inside the same transaction as the create — titles
                // are shared across notifications and must never be a key;
                // notifications.data is TEXT, so the JSON check runs in PHP.
                $alreadyNotified = $owner->notifications()
                    ->where('type', SystemNotification::class)
                    ->cursor()
                    ->contains(fn ($n) => ($n->data['receipt_id'] ?? null) === $receipt->getKey());

                if ($alreadyNotified) {
                    return 'already';
                }

                $url = $receipt->receipt_url; // fresh, under the lock

                if ($this->option('dry-run')) {
                    $this->line(sprintf(
                        '[dry-run] would notify %s (receipt %s): %s',
                        $owner->email,
                        $receipt->getKey(),
                        $url,
                    ));

                    return 'would';
                }

                $owner->notify(new SystemNotification(
                    title: 'Чек об оплате подписки',
                    body: 'Чек готов. Откройте его по ссылке ниже',
                    systemMessageId: null,
                    actionUrl: $url,
                    actionLabel: 'Открыть чек',
                    receiptId: $receipt->getKey(),
                ));

                $this->line("✓ {$owner->email} ← receipt {$receipt->getKey()}");

                return 'created';
            });

            if ($result === 'created' || $result === 'would') {
                $created++;
            } elseif ($result === 'already') {
                $skippedAlreadyNotified++;
            } elseif ($result === 'no_owner') {
                $skippedNoOwner++;
            } elseif ($result === 'vanished') {
                $skippedVanished++;
            }
        }

        $this->info(sprintf(
            '%s: %d %s, %d уже уведомлены, %d без владельца, %d изменились/исчезли (всего найдено %d).',
            $this->option('dry-run') ? 'Dry-run' : 'Готово',
            $created,
            $this->option('dry-run') ? 'будет создано' : 'создано',
            $skippedAlreadyNotified,
            $skippedNoOwner,
            $skippedVanished,
            $candidates->count(),
        ));

        return self::SUCCESS;
    }
}
