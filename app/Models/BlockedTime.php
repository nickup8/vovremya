<?php

namespace App\Models;

use App\Enums\BlockedTimeReason;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockedTime extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'start_datetime',
        'end_datetime',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'start_datetime' => 'datetime',
            'end_datetime' => 'datetime',
            'reason' => BlockedTimeReason::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
