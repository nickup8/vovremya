<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    protected $fillable = [
        'workspace_id',
        'type',
        'period_key',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public static function markSent(string $workspaceId, string $type, string $periodKey): void
    {
        static::firstOrCreate([
            'workspace_id' => $workspaceId,
            'type' => $type,
            'period_key' => $periodKey,
        ], [
            'sent_at' => now(),
        ]);
    }

    public static function hasBeenSent(string $workspaceId, string $type, string $periodKey): bool
    {
        return static::where('workspace_id', $workspaceId)
            ->where('type', $type)
            ->where('period_key', $periodKey)
            ->exists();
    }
}
