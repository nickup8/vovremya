<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Поканальная запись доставки одного события уведомления о записи.
 *
 * pending  — worker держит claim и может быть внутри HTTP;
 * delivered — канал принял сообщение (provider_message_id);
 * failed   — ограниченные ретраи исчерпаны или терминальная ошибка API;
 * no_channel — нет доступного канала у клиента (без HTTP);
 * superseded — событие устарело (версия записи ушла вперёд), не отправлялось.
 *
 * claim_token переписывается при каждом takeover просроченного lease;
 * success/release CASятся только по своему токену.
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

    public function event(): BelongsTo
    {
        return $this->belongsTo(AppointmentNotificationEvent::class, 'appointment_notification_event_id');
    }
}
