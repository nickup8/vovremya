<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanPrice extends Model
{
    use HasUuids;

    protected $fillable = [
        'tariff_plan_id',
        'period_months',
        'base_amount',
        'discount_percent',
        'final_amount',
        'currency',
        'version',
        'valid_from',
        'valid_to',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'period_months' => 'integer',
            'base_amount' => 'integer',
            'discount_percent' => 'integer',
            'final_amount' => 'integer',
            'version' => 'integer',
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function tariffPlan(): BelongsTo
    {
        return $this->belongsTo(TariffPlan::class);
    }
}
