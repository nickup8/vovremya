<?php

namespace App\Models;

use App\Enums\SlotOpportunitySourceType;
use App\Enums\SlotOpportunityStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlotOpportunity extends Model
{
    use HasUuids;

    protected $fillable = [
        'origin_event_id',
        'chain_id',
        'workspace_id',
        'master_id',
        'master_service_id',
        'source_appointment_id',
        'source_type',
        'status',
        'start_time',
        'duration',
        'filled_by_appointment_id',
        'filled_at',
        'expired_at',
        'invalidated_at',
    ];

    protected function casts(): array
    {
        return [
            'source_type' => SlotOpportunitySourceType::class,
            'status' => SlotOpportunityStatus::class,
            'start_time' => 'datetime',
            'duration' => 'integer',
            'filled_at' => 'datetime',
            'expired_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    protected $attributes = [
        'status' => 'open',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(User::class, 'master_id');
    }

    public function masterService(): BelongsTo
    {
        return $this->belongsTo(MasterService::class);
    }

    public function sourceAppointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'source_appointment_id');
    }

    public function filledByAppointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'filled_by_appointment_id');
    }
}
