<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceCatalog extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'service_catalog';

    protected $fillable = [
        'workspace_id',
        'title',
        'category',
        'base_price',
        'base_duration',
        'is_active',
        'reactivation_days',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'base_duration' => 'integer',
            'is_active' => 'boolean',
            'reactivation_days' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function masterServices(): HasMany
    {
        return $this->hasMany(MasterService::class, 'catalog_id');
    }
}
