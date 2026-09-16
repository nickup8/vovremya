<?php

namespace App\Services;

use App\Models\SuperAdminAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class SuperAdminAuditLogger
{
    public function log(
        User $superAdmin,
        string $action,
        ?Model $target = null,
        array $before = [],
        array $after = [],
        array $metadata = [],
    ): SuperAdminAuditLog {
        return SuperAdminAuditLog::create([
            'super_admin_id' => $superAdmin->id,
            'action' => $action,
            'target_type' => $target ? $target->getMorphClass() : null,
            'target_id' => $target?->getKey(),
            'before' => $before ?: null,
            'after' => $after ?: null,
            'metadata' => $metadata ?: null,
        ]);
    }
}
