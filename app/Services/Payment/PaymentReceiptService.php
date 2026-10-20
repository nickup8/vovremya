<?php

namespace App\Services\Payment;

use App\Enums\BillingCycleOrigin;
use App\Enums\PaymentEnvironment;
use App\Enums\PaymentReceiptStatus;
use App\Models\PaymentAttempt;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\SuperAdminAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manual NPD receipt bookkeeping.
 *
 * A receipt row is created idempotently inside the same DB transaction as
 * the successful payment transition (no external calls here). Attaching or
 * changing the lknpd.nalog.ru link is an explicit superadmin action: the
 * URL is stored exactly as entered, validated syntactically, and never
 * fetched over the network. A database-only notification to the workspace
 * owner is written atomically with the link change (row lock dedupes
 * concurrent identical saves).
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
     * A refund (full or partial, when it reaches this service) marks an
     * existing receipt for manual review. The link and history are kept;
     * the FNS receipt itself is never annulled by ИРСИ.
     */
    public function markRefundReview(PaymentAttempt $attempt): void
    {
        $receipt = PaymentReceipt::where('payment_attempt_id', $attempt->id)->first();

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

            $fresh->update([
                'receipt_url' => $url,
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

            return $fresh;
        });
    }

    /**
     * Superadmin «Проверено»: clears the manual refund review flag.
     */
    public function markRefundReviewed(PaymentReceipt $receipt, User $admin): PaymentReceipt
    {
        return DB::transaction(function () use ($receipt, $admin) {
            $fresh = PaymentReceipt::whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();

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
     * Database-only notification to the workspace owner. No network
     * delivery — SystemNotification uses the database channel exclusively.
     */
    private function notifyWorkspaceOwner(PaymentReceipt $receipt, bool $isUpdate): void
    {
        $owner = $receipt->paymentAttempt?->billingCycle?->workspace?->owner;

        if ($owner === null) {
            return;
        }

        $owner->notify(new SystemNotification(
            'Чек об оплате подписки',
            $isUpdate
                ? 'Ссылка на чек обновлена в кабинете.'
                : 'Ссылка на чек доступна в кабинете.',
        ));
    }
}
