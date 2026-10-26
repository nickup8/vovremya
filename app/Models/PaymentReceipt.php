<?php

namespace App\Models;

use App\Enums\PaymentReceiptStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Manual NPD («Моё налоговое дело») receipt tracking for a live tbank payment.
 *
 * Created idempotently inside the successful payment transition transaction.
 * The receipt URL is stored exactly as the administrator entered it — never
 * fetched or verified over the network by ИРСИ.
 *
 * Declared explicitly: larastan reads casts() only with parseModelCastsMethod
 * enabled and otherwise falls back to the migration column type (string),
 * which hides the enum/datetime attributes at analysis time.
 *
 * @property PaymentReceiptStatus $status
 * @property Carbon|null $attached_at
 * @property bool $refund_review_required
 * @property Carbon|null $refund_reviewed_at
 * @property int $url_version identity of the current link attach/change; bumped on every real change
 */
class PaymentReceipt extends Model
{
    use HasUuids;

    protected $fillable = [
        'payment_attempt_id',
        'status',
        'receipt_url',
        'url_version',
        'attached_by',
        'attached_at',
        'refund_review_required',
        'refund_reviewed_by',
        'refund_reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentReceiptStatus::class,
            'attached_at' => 'datetime',
            'refund_review_required' => 'boolean',
            'refund_reviewed_at' => 'datetime',
            'url_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PaymentAttempt, $this>
     */
    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function attachedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attached_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function refundReviewedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refund_reviewed_by');
    }
}
