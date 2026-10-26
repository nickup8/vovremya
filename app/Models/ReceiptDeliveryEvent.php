<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Transactional outbox row: one messenger delivery intent per real receipt
 * link change (receipt_id + url_version).
 *
 * Written in the same transaction as the URL, the database notification and
 * the audit record, so rollback discards the intent together with the change
 * and a committed change always has a durable intent. dispatched_at is set
 * only after the job was actually enqueued; rows with NULL dispatched_at are
 * re-dispatched by the receipts:recover-delivery-events command.
 *
 * @property Carbon|null $dispatched_at
 */
class ReceiptDeliveryEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'payment_receipt_id',
        'url_version',
        'receipt_url',
        'dispatched_at',
    ];

    protected function casts(): array
    {
        return [
            'url_version' => 'integer',
            'dispatched_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PaymentReceipt, $this>
     */
    public function paymentReceipt(): BelongsTo
    {
        return $this->belongsTo(PaymentReceipt::class);
    }
}
