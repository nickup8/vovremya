<?php

namespace App\Models;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingCycle extends Model
{
    use HasUuids;

    protected $fillable = [
        'billing_subscription_id',
        'workspace_id',
        'tariff_plan_id',
        'plan_price_id',
        'period_start',
        'period_end',
        'status',
        'amount',
        'currency',
        'price_snapshot',
        'origin',
        'legacy_subscription_id',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'status' => BillingCycleStatus::class,
            'amount' => 'integer',
            'price_snapshot' => 'array',
            'origin' => BillingCycleOrigin::class,
        ];
    }

    public function billingSubscription(): BelongsTo
    {
        return $this->belongsTo(BillingSubscription::class);
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

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }
}
