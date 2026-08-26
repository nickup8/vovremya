<?php

namespace App\Models;

use App\Enums\SlotRequestDeliveryChannel;
use App\Enums\SlotRequestSource;
use App\Enums\SlotRequestStatus;
use App\Enums\SlotRequestType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlotRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'master_id',
        'client_id',
        'appointment_id',
        'master_service_id',
        'type',
        'status',
        'request_source',
        'delivery_channel',
        'date_from',
        'date_to',
        'time_from',
        'time_to',
        'timezone',
        'appointment_start_time_snapshot',
        'expires_at',
        'fulfilled_at',
        'cancelled_at',
        'expired_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => SlotRequestType::class,
            'status' => SlotRequestStatus::class,
            'request_source' => SlotRequestSource::class,
            'delivery_channel' => SlotRequestDeliveryChannel::class,
            'date_from' => 'date',
            'date_to' => 'date',
            'time_from' => 'datetime',
            'time_to' => 'datetime',
            'appointment_start_time_snapshot' => 'datetime',
            'expires_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    protected $attributes = [
        'status' => 'active',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(User::class, 'master_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function masterService(): BelongsTo
    {
        return $this->belongsTo(MasterService::class);
    }
}
