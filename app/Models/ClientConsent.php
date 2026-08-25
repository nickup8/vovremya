<?php

namespace App\Models;

use App\Enums\ConsentAction;
use App\Enums\ConsentType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientConsent extends Model
{
    use HasUuids;

    protected $fillable = [
        'client_id',
        'workspace_id',
        'master_id',
        'type',
        'action',
        'version',
        'source',
        'channel',
        'consent_text',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ConsentType::class,
            'action' => ConsentAction::class,
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
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

    public function master(): BelongsTo
    {
        return $this->belongsTo(User::class, 'master_id');
    }
}
