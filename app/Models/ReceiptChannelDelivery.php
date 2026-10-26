<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-channel delivery record for a receipt URL sent to a messenger.
 *
 * One row = «this exact URL was accepted by this channel». A retry job
 * consults it before sending, so a channel that already succeeded is
 * never messaged again for the same link; a changed link hashes
 * differently and therefore is delivered afresh.
 *
 * @property Carbon|null $delivered_at
 */
class ReceiptChannelDelivery extends Model
{
    use HasUuids;

    protected $fillable = [
        'payment_receipt_id',
        'channel',
        'receipt_url',
        'receipt_url_hash',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PaymentReceipt, $this>
     */
    public function paymentReceipt(): BelongsTo
    {
        return $this->belongsTo(PaymentReceipt::class);
    }

    public static function hashUrl(string $url): string
    {
        return hash('sha256', $url);
    }
}
