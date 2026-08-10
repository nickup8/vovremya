<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id',
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

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function tariffPlan(): BelongsTo
    {
        return $this->belongsTo(TariffPlan::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    public function daysLeft(): int
    {
        if ($this->expires_at === null || $this->expires_at->isPast()) {
            return 0;
        }

        return (int) ceil(now()->diffInDays($this->expires_at, absolute: false));
    }
}
