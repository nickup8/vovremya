<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DiscountRule extends Model
{
    use HasUuids;

    protected $fillable = [
        'period_months',
        'discount_percent',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'discount_percent' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
