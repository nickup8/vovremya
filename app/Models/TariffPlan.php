<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
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
        ];
    }

    protected function maxAppointmentsPerMonth(): Attribute
    {
        return Attribute::get(fn ($value): ?int => $value === null ? null : (int) $value);
    }

    protected function maxMasters(): Attribute
    {
        return Attribute::get(fn ($value): ?int => $value === null ? null : (int) $value);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
