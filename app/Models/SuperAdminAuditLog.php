<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuperAdminAuditLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'super_admin_id',
        'action',
        'target_type',
        'target_id',
        'before',
        'after',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SuperAdminAuditLog $log) {
            $log->created_at = $log->created_at ?? now();
        });
    }

    public function superAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'super_admin_id');
    }

    public function target()
    {
        if (! $this->target_type || ! $this->target_id) {
            return null;
        }

        return $this->target_type::find($this->target_id);
    }
}
