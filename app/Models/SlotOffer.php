<?php

namespace App\Models;

use App\Enums\SlotInvalidationReason;
use App\Enums\SlotOfferStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlotOffer extends Model
{
    use HasUuids;

    protected $fillable = [
        'slot_request_id',
        'slot_opportunity_id',
        'status',
        'expires_at',
        'accepted_at',
        'declined_at',
        'expired_at',
        'invalidated_at',
        'invalidation_reason',
        'sent_at',
        'delivery_mid',
    ];

    protected function casts(): array
    {
        return [
            'status' => SlotOfferStatus::class,
            'invalidation_reason' => SlotInvalidationReason::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'expired_at' => 'datetime',
            'invalidated_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    protected $attributes = [
        'status' => 'pending',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(SlotRequest::class, 'slot_request_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(SlotOpportunity::class, 'slot_opportunity_id');
    }
}
