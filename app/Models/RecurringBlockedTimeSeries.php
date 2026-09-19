<?php

namespace App\Models;

use App\Enums\RecurrenceType;
use App\Enums\RecurringSeriesStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringBlockedTimeSeries extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'recurring_blocked_time_series';

    protected $fillable = [
        'workspace_id',
        'user_id',
        'title',
        'reason',
        'start_date',
        'start_time',
        'end_time',
        'recurrence_type',
        'interval',
        'weekdays',
        'ends_at',
        'timezone',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'ends_at' => 'date',
            'weekdays' => 'array',
            'interval' => 'integer',
            'status' => RecurringSeriesStatus::class,
            'recurrence_type' => RecurrenceType::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(RecurringBlockedTimeException::class, 'series_id');
    }

    public function isActive(): bool
    {
        return $this->status === RecurringSeriesStatus::Active;
    }
}
