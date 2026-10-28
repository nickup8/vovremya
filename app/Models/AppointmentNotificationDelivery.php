<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Поканальная запись доставки одного события уведомления о записи.
 *
 * pending  — worker держит claim и может быть внутри HTTP;
 * delivered — канал принял сообщение (provider_message_id);
 * failed   — ограниченные ретраи исчерпаны или терминальная ошибка API;
 * no_channel — нет доступного канала у клиента (без HTTP);
 * superseded — событие устарело (версия записи ушла вперёд), не отправлялось.
 *
 * @property string $id
 * @property string $appointment_notification_event_id
 * @property string $channel
 * @property string $status
 * @property string|null $claim_token
 * @property Carbon|null $lease_expires_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $failed_at
 */
class AppointmentNotificationDelivery extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NO_CHANNEL = 'no_channel';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'appointment_notification_event_id',
        'channel',
        'status',
        'claim_token',
        'lease_expires_at',
        'provider_message_id',
        'failure_reason',
        'delivered_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'lease_expires_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AppointmentNotificationEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(AppointmentNotificationEvent::class, 'appointment_notification_event_id');
    }
}
