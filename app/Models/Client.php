<?php

namespace App\Models;

use App\Traits\SearchableByProvider;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

class Client extends Authenticatable
{
    use HasFactory, HasUuids, SearchableByProvider;

    protected $fillable = [
        'user_id',
        'phone',
        'telegram_id',
        'max_id',
        'max_chat_id',
        'name',
        'avatar_url',
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
