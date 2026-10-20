<?php

namespace App\Models;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Declared explicitly: larastan reads casts() only with parseModelCastsMethod
 * enabled and otherwise falls back to the migration column type (string),
 * which hides the enum/datetime attributes at analysis time.
 *
 * @property BillingCycleOrigin $origin
 * @property BillingCycleStatus $status
 * @property Carbon $period_start
 * @property Carbon $period_end
 */
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
        'legacy_subscription_id', // historical pointer to the FIRST legacy row — do NOT overwrite on retry; attempt-level metadata holds the authoritative link
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

    /**
     * @return BelongsTo<BillingSubscription, $this>
     */
    public function billingSubscription(): BelongsTo
    {
        return $this->belongsTo(BillingSubscription::class);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<TariffPlan, $this>
     */
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
