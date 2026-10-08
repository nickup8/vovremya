<?php

namespace App\Models;

use App\Enums\PaymentAttemptStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Declared explicitly: larastan reads casts() only with parseModelCastsMethod
 * enabled and otherwise falls back to the migration column type (string),
 * which hides the enum this attribute actually is at runtime.
 *
 * @property PaymentAttemptStatus $status
 */
class PaymentAttempt extends Model
{
    use HasUuids;

    protected $fillable = [
        'billing_cycle_id',
        'payment_method_id',
        'provider',
        'attempt_number',
        'amount',
        'currency',
        'internal_order_id',
        'provider_payment_id',
        'status',
        'failure_code',
        'failure_category',
        'failure_message',
        'initiated_at',
        'finished_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'amount' => 'integer',
            'status' => PaymentAttemptStatus::class,
            'initiated_at' => 'datetime',
            'finished_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function billingCycle(): BelongsTo
    {
        return $this->belongsTo(BillingCycle::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
