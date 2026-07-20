<?php

namespace App\Models;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
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
        'service_id',
        'start_time',
        'status',
        'source',
        'provider',
        'reminder_24h_sent',
        'reminder_final_sent',
        'reminder_24h_sent_at',
        'reminder_final_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'status' => AppointmentStatus::class,
            'source' => AppointmentSource::class,
            'reminder_24h_sent' => 'boolean',
            'reminder_final_sent' => 'boolean',
            'reminder_24h_sent_at' => 'datetime',
            'reminder_final_sent_at' => 'datetime',
        ];
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(User::class, 'master_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
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
            'service' => $this->service?->title ?? 'Услуга удалена',
            'duration' => $this->service?->duration_minutes ?? 0,
            'price' => (float) ($this->service?->price ?? 0),
            'time' => $this->start_time->timezone($tz)->format('H:i'),
            'date' => $this->start_time->timezone($tz)->format('Y-m-d'),
            'status' => $this->status,
        ];
    }
}
