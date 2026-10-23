<?php

namespace App\Models;

use App\Enums\BackupStatus;
use App\Enums\BackupTrigger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property BackupTrigger $trigger_type
 * @property BackupStatus $status
 * @property CarbonImmutable|null $requested_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int|null $size_bytes
 * @property string|null $object_key
 * @property string|null $manifest_key
 * @property string|null $sha256
 * @property string|null $pg_version
 * @property string|null $app_version
 * @property string|null $error_message
 * @property string|null $prune_error_message
 * @property User|null $requester
 */
class DatabaseBackup extends Model
{
    use HasUuids;

    protected $fillable = [
        'trigger_type',
        'status',
        'requested_by',
        'requested_at',
        'started_at',
        'finished_at',
        'dedup_key',
        'object_key',
        'manifest_key',
        'size_bytes',
        'sha256',
        'pg_version',
        'app_version',
        'error_message',
        'prune_error_message',
    ];

    protected function casts(): array
    {
        return [
            'trigger_type' => BackupTrigger::class,
            'status' => BackupStatus::class,
            'requested_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Копия висит дольше stale_after (воркер мог быть убит без освобождения
     * job): разрешён повторный запуск с пометкой такой копии прерванной.
     */
    public function isStale(): bool
    {
        $checkpoint = $this->started_at ?? $this->requested_at;

        if (! $checkpoint instanceof CarbonImmutable) {
            return true;
        }

        return $checkpoint->lt(now()->subSeconds((int) config('backups.stale_after', 1800)));
    }
}
