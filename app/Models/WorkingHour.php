<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkingHour extends Model
{
    use HasFactory, HasUuids;
    protected $fillable = [
        'user_id',
        'day_of_week',
        'start_time',
        'end_time',
        'break_start_time',
        'break_end_time',
        'is_working',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'is_working' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasBreak(): bool
    {
        return $this->break_start_time !== null && $this->break_end_time !== null;
    }
}
