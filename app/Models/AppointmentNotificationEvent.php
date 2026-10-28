<?php

namespace App\Models;

use App\Enums\AppointmentNotificationEventType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Transactional outbox строка клиентского уведомления о записи.
 *
 * Пишется в той же транзакции, что и изменение записи. dispatched_at IS NULL
 * означает «намерение закоммичено, job не поставлен» — recovery переставит.
 * event_version связывает событие с версией записи: job перед отправкой
 * сверяет, что запись не ушла вперёд.
 *
 * @property string $id
 * @property string $appointment_id
 * @property string|null $recurring_series_id
 * @property AppointmentNotificationEventType $event_type
 * @property int $event_version
 * @property array<string, mixed> $payload
 * @property Carbon|null $dispatched_at
 */
class AppointmentNotificationEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'appointment_id',
        'recurring_series_id',
        'event_type',
        'event_version',
        'payload',
        'dispatched_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => AppointmentNotificationEventType::class,
            'event_version' => 'integer',
            'payload' => 'array',
            'dispatched_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return BelongsTo<RecurringAppointmentSeries, $this>
     */
    public function recurringSeries(): BelongsTo
    {
        return $this->belongsTo(RecurringAppointmentSeries::class);
    }

    /**
     * @return HasMany<AppointmentNotificationDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(AppointmentNotificationDelivery::class, 'appointment_notification_event_id');
    }
}
