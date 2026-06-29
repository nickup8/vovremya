<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'tariff_plan_id',
        'period_months',
        'amount_paid',
        'status',
        'starts_at',
        'expires_at',
        'payment_id',
    ];

    protected function casts(): array
    {
        return [
            'period_months' => 'integer',
            'amount_paid' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tariffPlan(): BelongsTo
    {
        return $this->belongsTo(TariffPlan::class);
    }
}
