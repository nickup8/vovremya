<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $master_id
 * @property string|null $catalog_id
 * @property-read float|null $effective_price
 * @property-read int|null $effective_duration
 */
class MasterService extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'master_service';

    protected $fillable = [
        'master_id',
        'catalog_id',
        'price_override',
        'duration_override',
        'is_active',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'price_override' => 'decimal:2',
            'duration_override' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function master(): BelongsTo
    {
        return $this->belongsTo(User::class, 'master_id');
    }

    /**
     * @return BelongsTo<ServiceCatalog, $this>
     */
    public function catalog(): BelongsTo
    {
        return $this->belongsTo(ServiceCatalog::class, 'catalog_id');
    }

    protected function effectivePrice(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->price_override ?? $this->catalog?->base_price,
        );
    }

    protected function effectiveDuration(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->duration_override ?? $this->catalog?->base_duration,
        );
    }
}
