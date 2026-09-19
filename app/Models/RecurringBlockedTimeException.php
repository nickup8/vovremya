<?php

namespace App\Models;

use App\Enums\ExceptionType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringBlockedTimeException extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'recurring_blocked_time_exceptions';

    protected $fillable = [
        'series_id',
        'occurrence_date',
        'type',
        'override_start_time',
        'override_end_time',
        'override_title',
        'override_reason',
    ];

    protected function casts(): array
    {
        return [
            'occurrence_date' => 'date',
            'type' => ExceptionType::class,
        ];
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(RecurringBlockedTimeSeries::class, 'series_id');
    }
}
