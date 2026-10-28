<?php

namespace App\Models;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Enums\SlotRequestStatus;
use App\Enums\SlotRequestType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Запись о визите.
 *
 * @property string $id
 * @property string|null $client_id
 * @property string|null $recurring_series_id
 * @property AppointmentStatus $status
 * @property AppointmentSource|null $source
 * @property Carbon $start_time
 * @property Carbon|null $reminder_24h_sent_at
 * @property Carbon|null $reminder_24h_dispatched_at
 * @property Carbon|null $reminder_24h_failed_at
 * @property Carbon|null $reminder_final_sent_at
 * @property Carbon|null $reminder_final_dispatched_at
 * @property Carbon|null $reminder_final_failed_at
 * @property Carbon|null $client_confirmed_at
 * @property Carbon|null $cancelled_at
 * @property int $notification_version
 * @property int $schedule_version
 * @property-read string $display_name
 * @property-read int $display_duration
 * @property-read float $display_price
 */
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
        'tracking_link_id',
        'completed_at',
        'service_name',
        'provider',
        'reminder_24h_sent',
        'reminder_final_sent',
        'reminder_24h_sent_at',
        'reminder_24h_dispatched_at',
        'reminder_24h_failed_at',
        'reminder_final_sent_at',
        'reminder_final_dispatched_at',
        'reminder_final_failed_at',
        'cancelled_at',
        'cancelled_by',
        'client_confirmed_at',
        'recurring_series_id',
        'recurring_occurrence_date',
        'vk_confirmation_sent_at',
        'notification_version',
        'schedule_version',
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
            'reminder_24h_dispatched_at' => 'datetime',
            'reminder_24h_failed_at' => 'datetime',
            'reminder_final_sent_at' => 'datetime',
            'reminder_final_dispatched_at' => 'datetime',
            'reminder_final_failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
            'client_confirmed_at' => 'datetime',
            'vk_confirmation_sent_at' => 'datetime',
            'notification_version' => 'integer',
            'schedule_version' => 'integer',
        ];
    }

    /**
     * Поколение расписания: инкремент при изменении времени, мастера или
     * получателя (клиента). Напоминания несут снимок версии на момент
     * постановки job'а и сверяются с ней перед отправкой и при записи
     * результата. Изменение цены/длительности/услуги поколение не двигает —
     * оно не влияет на отправляемое расписание.
     */
    protected static function booted(): void
    {
        static::updating(function (Appointment $appointment): void {
            if ($appointment->isDirty(['start_time', 'master_id', 'client_id'])) {
                $appointment->schedule_version = (int) $appointment->schedule_version + 1;
            }
        });
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function master(): BelongsTo
    {
        return $this->belongsTo(User::class, 'master_id');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * @return BelongsTo<MasterService, $this>
     */
    public function masterService(): BelongsTo
    {
        return $this->belongsTo(MasterService::class, 'master_service_id');
    }

    /**
     * @return BelongsTo<TrackingLink, $this>
     */
    public function trackingLink(): BelongsTo
    {
        return $this->belongsTo(TrackingLink::class, 'tracking_link_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * @return BelongsTo<RecurringAppointmentSeries, $this>
     */
    public function recurringSeries(): BelongsTo
    {
        return $this->belongsTo(RecurringAppointmentSeries::class, 'recurring_series_id');
    }

    /**
     * @return HasOne<SlotRequest, $this>
     */
    public function activeSlotRequest(): HasOne
    {
        return $this->hasOne(SlotRequest::class, 'appointment_id')
            ->where('type', SlotRequestType::Earlier)
            ->where('status', SlotRequestStatus::Active);
    }

    public function toCalendarArray(): array
    {
        $master = $this->master;
        $tz = $master?->getTimezone() ?? 'UTC';

        return [
            'id' => $this->id,
            'master_id' => $this->master_id,
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
            'reminder_24h_sent_at' => $this->reminder_24h_sent_at?->toIso8601String(),
        ];
    }
}
