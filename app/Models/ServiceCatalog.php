<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceCatalog extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'service_catalog';

    protected $fillable = [
        'workspace_id',
        'title',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    // public function masterServices(): HasMany
    // {
    //     return $this->hasMany(MasterService::class);
    // }
}
