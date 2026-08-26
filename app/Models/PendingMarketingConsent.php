<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingMarketingConsent extends Model
{
    use HasUuids;

    protected $fillable = [
        'client_id',
        'workspace_id',
        'legal_version',
        'rendered_consent_text',
        'source',
        'channel',
        'shown_at',
        'expires_at',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'shown_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->lte(now());
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }
}
