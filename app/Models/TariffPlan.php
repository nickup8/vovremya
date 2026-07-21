<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TariffPlan extends Model
{
    use HasUuids;

    protected $fillable = [
        'code',
        'name',
        'price_monthly',
        'max_appointments_per_month',
        'max_masters',
        'features',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'is_active' => 'boolean',
            'price_monthly' => 'integer',
            'max_appointments_per_month' => 'integer',
            'max_masters' => 'integer',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
