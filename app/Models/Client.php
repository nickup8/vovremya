<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Client extends Model
{
    protected $fillable = [
        'user_id',
        'phone',
        'telegram_id',
        'max_id',
        'name',
        'auth_token',
    ];

    protected function casts(): array
    {
        return [];
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'client_id');
    }

    public static function generateAuthToken(): string
    {
        return Str::random(64);
    }
}
