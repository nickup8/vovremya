<?php

namespace App\Services\Payment;

use App\Enums\BillingCycleOrigin;
use App\Enums\PaymentEnvironment;
use App\Enums\PaymentReceiptStatus;
use App\Jobs\SendReceiptUrlNotificationJob;
use App\Models\PaymentAttempt;
use App\Models\PaymentReceipt;
use App\Models\ReceiptDeliveryEvent;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\SuperAdminAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Manual NPD receipt bookkeeping.
 *
 * A receipt row is created idempotently inside the same DB transaction as
 * the successful payment transition (no external calls here). Attaching or
 * changing the lknpd.nalog.ru link is an explicit superadmin action: the
 * URL is stored exactly as entered, validated syntactically, and never
 * fetched over the network. A database-only notification with a structured
 * action link is written atomically with the link change (row lock dedupes
 * concurrent identical saves); messenger delivery intent is written as a
 * receipt_delivery_events outbox row in that same transaction and the job
 * is enqueued only after the commit (recovered by
 * receipts:recover-delivery-events when the enqueue itself fails).
 */
class PaymentReceiptService
{
    public function __construct(
        private NpdReceiptUrlValidator $urlValidator,
    ) {}

    /**
     * Create the awaiting_manual receipt row for a just-succeeded live
     * tbank attempt. Must run inside the transition transaction.
     *
     * Only:
     * - provider = tbank;
     * - payment_env = live (the snapshot stored on the attempt);
     * - positive amount;
     * - cycle origin payment or renewal.
     *
     * Historic payments are never backfilled. Idempotent via the
     * UNIQUE(payment_attempt_id) constraint + firstOrCreate.
     */
    public function ensureForSuccessfulAttempt(PaymentAttempt $attempt): ?PaymentReceipt
    {
        if (! $this->isEligible($attempt)) {
            return null;
        }

        return PaymentReceipt::firstOrCreate(
            ['payment_attempt_id' => $attempt->id],
            ['status' => PaymentReceiptStatus::AwaitingManual],
        );
    }

    /**
     * A real refund transition (full or partial, when it reaches this
     * service) marks an existing receipt for manual review. The link and
     * history are kept; the FNS receipt itself is never annulled by ИРСИ.
     *
     * Runs inside the transition transaction; the row lock keeps the read
     * consistent with a concurrent markRefundReviewed (same lock order).
     * Callers must not invoke this on a repeat same-status refund event —
     * that would re-open a review the superadmin already closed.
     */
    public function markRefundReview(PaymentAttempt $attempt): void
    {
        $receipt = PaymentReceipt::where('payment_attempt_id', $attempt->id)
            ->lockForUpdate()
            ->first();

        if ($receipt === null || $receipt->refund_review_required) {
            return;
        }

        $receipt->update(['refund_review_required' => true]);
    }

    /**
     * Attach or replace the receipt link (superadmin action).
     *
     * Saves link + audit + owner notification in one DB transaction.
     * Re-saving the identical link is a no-op: no audit, no notification.
     *
     * @throws ValidationException
     */
    public function attachUrl(PaymentReceipt $receipt, string $url, User $admin): PaymentReceipt
    {
        $sellerInn = trim((string) config('billing.receipts.seller_inn', ''));
        $error = $this->urlValidator->validate($url, $sellerInn);

        if ($error !== null) {
            throw ValidationException::withMessages(['receipt_url' => $error]);
        }

        $url = trim($url);

        return DB::transaction(function () use ($receipt, $url, $admin) {
            $fresh = PaymentReceipt::whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();

            $previousUrl = $fresh->receipt_url;
            $wasAttached = $fresh->status === PaymentReceiptStatus::Attached
                && $previousUrl !== null;

            if ($wasAttached && $previousUrl === $url) {
                return $fresh;
            }

            // Every real change is a new event — including returning to an
            // older URL. Re-saving the identical URL never reaches here, so
            // the version (and the event) only ever moves forward. A change
            // always lands at version ≥ 2 (its message is «Ссылка на чек
            // обновлена»), the first attach at version ≥ 1, even for rows
            // written before url_version existed.
            $nextVersion = max($fresh->url_version + 1, $wasAttached ? 2 : 1);

            $fresh->update([
                'receipt_url' => $url,
                'url_version' => $nextVersion,
                'status' => PaymentReceiptStatus::Attached,
                'attached_by' => $admin->id,
                'attached_at' => now(),
            ]);

            app(SuperAdminAuditLogger::class)->log(
                $admin,
                $wasAttached ? 'receipt.url_changed' : 'receipt.attached',
                $fresh,
                ['receipt_url' => $previousUrl, 'status' => $wasAttached ? PaymentReceiptStatus::Attached->value : PaymentReceiptStatus::AwaitingManual->value],
                ['receipt_url' => $fresh->receipt_url, 'status' => $fresh->status->value],
                ['payment_attempt_id' => $fresh->payment_attempt_id],
            );

            $this->notifyWorkspaceOwner($fresh, $wasAttached);

            // Outbox: the delivery intent lands in the SAME transaction as the
            // URL, the database notification and the audit row. A rollback
            // discards it with the change, so a rolled-back change can never
            // produce a message; a committed change always has this durable
            // intent even when enqueueing below fails.
            $event = ReceiptDeliveryEvent::create([
                'payment_receipt_id' => $fresh->getKey(),
                'url_version' => $nextVersion,
                'receipt_url' => $fresh->receipt_url,
            ]);

            // Enqueue only after the transaction really commits: no HTTP call
            // and no queue push happen inside the attach transaction. If the
            // push fails (queue outage) or the process dies right after the
            // commit, dispatched_at stays NULL and the scheduled
            // receipts:recover-delivery-events re-dispatches those rows — the
            // committed intent is never lost. The push and the dispatched_at
            // write are separate steps, so a crash between them can make that
            // recovery enqueue the same event a SECOND time; the repeated job
            // is harmless because the per-channel claim and the delivery
            // record make it skip whatever is already delivered.
            DB::afterCommit(static function () use ($event): void {
                try {
                    SendReceiptUrlNotificationJob::dispatch($event->getKey());
                    ReceiptDeliveryEvent::whereKey($event->getKey())
                        ->update(['dispatched_at' => now()]);
                } catch (\Throwable $e) {
                    Log::error('Receipt delivery event not enqueued; recovery will retry', [
                        'event_id' => $event->getKey(),
                        'receipt_id' => $event->payment_receipt_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

            return $fresh;
        });
    }

    /**
     * Superadmin «Проверено»: clears the manual refund review flag.
     *
     * A repeat press on an already closed review is a no-op: the reviewed
     * timestamp, the reviewing admin and the audit trail stay untouched.
     */
    public function markRefundReviewed(PaymentReceipt $receipt, User $admin): PaymentReceipt
    {
        return DB::transaction(function () use ($receipt, $admin) {
            $fresh = PaymentReceipt::whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->refund_review_required) {
                return $fresh;
            }

            $before = [
                'refund_review_required' => $fresh->refund_review_required,
                'refund_reviewed_at' => $fresh->refund_reviewed_at?->toIso8601String(),
            ];

            $fresh->update([
                'refund_review_required' => false,
                'refund_reviewed_by' => $admin->id,
                'refund_reviewed_at' => now(),
            ]);

            app(SuperAdminAuditLogger::class)->log(
                $admin,
                'receipt.refund_reviewed',
                $fresh,
                $before,
                [
                    'refund_review_required' => false,
                    'refund_reviewed_at' => $fresh->refund_reviewed_at?->toIso8601String(),
                ],
                ['payment_attempt_id' => $fresh->payment_attempt_id],
            );

            return $fresh;
        });
    }

    private function isEligible(PaymentAttempt $attempt): bool
    {
        if ($attempt->provider !== 'tbank') {
            return false;
        }

        if ($attempt->payment_env !== PaymentEnvironment::Live) {
            return false;
        }

        if ($attempt->amount <= 0) {
            return false;
        }

        $origin = $attempt->billingCycle?->origin;

        return $origin === BillingCycleOrigin::Payment
            || $origin === BillingCycleOrigin::Renewal;
    }

    /**
     * Database-only notification to the workspace owner with a structured
     * «Открыть чек» action (separate fields, never HTML in the body).
     * Messenger channels are dispatched separately after commit.
     */
    private function notifyWorkspaceOwner(PaymentReceipt $receipt, bool $isUpdate): void
    {
        $owner = $receipt->paymentAttempt?->billingCycle?->workspace?->owner;

        if ($owner === null || $receipt->receipt_url === null) {
            return;
        }

        $owner->notify(new SystemNotification(
            title: $isUpdate ? 'Ссылка на чек обновлена' : 'Чек об оплате подписки',
            body: $isUpdate
                ? 'Откройте чек по обновлённой ссылке'
                : 'Чек готов. Откройте его по ссылке ниже',
            systemMessageId: null,
            actionUrl: $receipt->receipt_url,
            actionLabel: 'Открыть чек',
            receiptId: $receipt->getKey(),
        ));
    }
}
