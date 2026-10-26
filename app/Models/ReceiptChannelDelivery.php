<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-channel delivery record for one receipt URL-change event.
 *
 * One row = one (receipt event, channel) pair. The row is created as a
 * claim (status pending, claim_token, lease_expires_at) BEFORE the HTTP call
 * and only flips to delivered once the API accepted the message — so two
 * workers overlapping on the same event serialize on this row instead of
 * both reaching HTTP, and a failed send is never recorded as a success.
 *
 * claim_token identifies the capture that currently owns the claim: it is
 * rewritten on every takeover of an expired lease, and the owner's success
 * write / release only ever match rows still carrying its own token.
 *
 * @property string $status pending|delivered
 * @property string|null $claim_token
 * @property Carbon|null $lease_expires_at
 * @property Carbon|null $delivered_at
 */
class ReceiptChannelDelivery extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERED = 'delivered';

    protected $fillable = [
        'payment_receipt_id',
        'channel',
        'url_version',
        'receipt_url',
        'status',
        'claim_token',
        'lease_expires_at',
        'provider_message_id',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'url_version' => 'integer',
            'lease_expires_at' => 'datetime',
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
}
