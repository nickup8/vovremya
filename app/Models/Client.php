<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Client extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'phone',
        'telegram_id',
        'max_id',
        'name',
        'auth_token',
        'is_blocked',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_blocked' => 'boolean',
        ];
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function user(): BelongsTo
    {
        return $this->master();
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'client_id');
    }

    public function isBlocked(): bool
    {
        return (bool) ($this->is_blocked ?? false);
    }

    public static function generateAuthToken(): string
    {
        return Str::random(64);
    }
}
