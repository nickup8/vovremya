<?php

namespace App\Models;

use App\Enums\PlatformPermission;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformAdminAccess extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'permissions',
        'is_active',
        'granted_by',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function hasPermission(string|PlatformPermission $permission): bool
    {
        $value = $permission instanceof PlatformPermission ? $permission->value : $permission;

        return in_array($value, $this->permissions ?? [], true);
    }
}
