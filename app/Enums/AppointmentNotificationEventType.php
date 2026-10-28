<?php

namespace App\Enums;

/**
 * Типы клиентских уведомлений о записи (строки appointment_notification_events.event_type).
 */
enum AppointmentNotificationEventType: string
{
    /** Мастер создал будущую запись с выбранным клиентом («Мастер записал вас»). */
    case CreatedByMaster = 'created_by_master';

    /** Мастер отменил запись («Мастер отменил запись»). */
    case CancelledByMaster = 'cancelled_by_master';

    /** Перенос: прежние/новые дата-время, при смене мастера — кто был/кто стал. */
    case Rescheduled = 'rescheduled';

    /** Фактическое изменение услуги/времени/длительности/цены одной записью. */
    case FieldsChanged = 'fields_changed';

    /** Ручное создание серии — одно согласованное сообщение. */
    case SeriesCreated = 'series_created';

    /** Изменение серии (edit this and future / split) — одно согласованное сообщение. */
    case SeriesUpdated = 'series_updated';

    /** Отмена серии (this-and-future / whole) — одно согласованное сообщение. */
    case SeriesCancelled = 'series_cancelled';

    /** Подтверждение брони из widget (клиент привязался) — общий путь дедупликации. */
    case BookingConfirmed = 'booking_confirmed';
}
