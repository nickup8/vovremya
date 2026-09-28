<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FreeWindowPublication extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'free_window_publications';

    protected $fillable = [
        'master_id',
        'token',
        'content_hash',
        'mode',
        'master_service_id',
        'date_from',
        'date_to',
        'payload',
        'expires_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'date_from' => 'date',
            'date_to' => 'date',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(User::class, 'master_id');
    }

    public function masterService(): BelongsTo
    {
        return $this->belongsTo(MasterService::class, 'master_service_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
