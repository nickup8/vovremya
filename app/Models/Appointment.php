<?php

namespace App\Models;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'master_id',
        'client_id',
        'master_service_id',
        'price',
        'duration',
        'start_time',
        'status',
        'source',
        'service_name',
        'provider',
        'reminder_24h_sent',
        'reminder_final_sent',
        'reminder_24h_sent_at',
        'reminder_final_sent_at',
        'cancelled_at',
        'cancelled_by',
        'client_confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'status' => AppointmentStatus::class,
            'source' => AppointmentSource::class,
            'price' => 'decimal:2',
            'duration' => 'integer',
            'reminder_24h_sent' => 'boolean',
            'reminder_final_sent' => 'boolean',
            'reminder_24h_sent_at' => 'datetime',
            'reminder_final_sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'client_confirmed_at' => 'datetime',
        ];
    }

    protected function displayName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->service_name
                ?? $this->masterService?->catalog?->title
                ?? 'Услуга удалена',
        );
    }

    protected function displayDuration(): Attribute
    {
        return Attribute::make(
            get: fn () => (int) ($this->duration
                ?? $this->masterService?->effective_duration
                ?? 0),
        );
    }

    protected function displayPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => (float) ($this->price
                ?? $this->masterService?->effective_price
                ?? 0),
        );
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(User::class, 'master_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function masterService(): BelongsTo
    {
        return $this->belongsTo(MasterService::class, 'master_service_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function toCalendarArray(): array
    {
        $master = $this->master;
        $tz = $master?->getTimezone() ?? 'UTC';

        return [
            'id' => $this->id,
            'client_name' => $this->client?->name ?? 'Клиент не указан',
            'client_phone' => $this->client?->phone,
            'client_avatar_url' => $this->client?->avatar_url,
            'service' => $this->display_name,
            'duration' => $this->display_duration,
            'price' => $this->display_price,
            'time' => $this->start_time->timezone($tz)->format('H:i'),
            'date' => $this->start_time->timezone($tz)->format('Y-m-d'),
            'status' => $this->status,
            'client_confirmed_at' => $this->client_confirmed_at?->toIso8601String(),
        ];
    }
}
