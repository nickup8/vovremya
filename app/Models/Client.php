<?php

namespace App\Models;

use App\Traits\SearchableByProvider;
use Illuminate\Database\Eloquent\Builder;
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
        'is_personal',
        'workspace_id',
        'phone',
        'telegram_id',
        'max_id',
        'max_chat_id',
        'vk_id',
        'vk_chat_id',
        'name',
        'avatar_url',
        'auth_token',
        'cabinet_message_id',
        'max_cabinet_message_id',
        'pdn_consent_at',
        'pdn_consent_version',
        'is_blocked',
        'notes',
    ];

    protected $attributes = [
        'is_personal' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
            'is_blocked' => 'boolean',
            'pdn_consent_at' => 'datetime',
        ];
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
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
        return (bool) $this->is_blocked;
    }

    public static function generateAuthToken(): string
    {
        return Str::random(64);
    }

    public function scopeForWorkspaceOrMaster(Builder $query, User $user): Builder
    {
        return $query->where(function ($q) use ($user) {
            if ($user->workspace_id !== null) {
                $q->where('workspace_id', $user->workspace_id);
            }

            $q->orWhere(function ($sub) use ($user) {
                $sub->whereNull('workspace_id')->where('user_id', $user->id);
            });
        });
    }
}
