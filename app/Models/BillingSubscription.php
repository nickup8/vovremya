<?php

namespace App\Models;

use App\Enums\BillingSubscriptionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingSubscription extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'tariff_plan_id',
        'status',
        'renewal_period_months',
        'current_period_start',
        'current_period_end',
        'next_charge_at',
        'cancel_at_period_end',
        'grace_until',
        'plan_price_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => BillingSubscriptionStatus::class,
            'renewal_period_months' => 'integer',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'next_charge_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'grace_until' => 'datetime',
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

    public function planPrice(): BelongsTo
    {
        return $this->belongsTo(PlanPrice::class);
    }

    public function billingCycles(): HasMany
    {
        return $this->hasMany(BillingCycle::class);
    }
}
