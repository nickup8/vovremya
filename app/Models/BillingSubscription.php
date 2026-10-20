<?php

namespace App\Models;

use App\Enums\BillingSubscriptionStatus;
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
 * @property BillingSubscriptionStatus $status
 * @property int|null $renewal_period_months
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property Carbon|null $next_charge_at
 * @property bool $cancel_at_period_end
 * @property Carbon|null $grace_until
 * @property Carbon|null $auto_renew_consent_at
 */
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
        'auto_renew_consent_at',
        'auto_renew_consent_version',
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
            'auto_renew_consent_at' => 'datetime',
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
