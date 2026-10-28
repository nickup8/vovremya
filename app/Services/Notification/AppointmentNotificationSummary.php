<?php

namespace App\Services\Notification;

use App\Enums\AppointmentNotificationEventType;
use App\Models\Appointment;
use App\Models\AppointmentNotificationDelivery;
use App\Models\AppointmentNotificationEvent;
use Illuminate\Support\Collection;

/**
 * Сводка «Уведомления клиенту» для UI деталей записи.
 *
 * Показывает компактный статус последнего события: ожидает / отправлено /
 * не удалось / нет канала / устарело. «Отправлено» = принято API с
 * message_id, не прочтение клиентом. Технические токены, внутренние
 * исключения и секреты не отдаются.
 */
class AppointmentNotificationSummary
{
    /**
     * @param  Collection<int, Appointment>  $appointments
     * @return array<string, array<string, mixed>|null> appointment_id => summary|null
     */
    public function forAppointments(Collection $appointments): array
    {
        if ($appointments->isEmpty()) {
            return [];
        }

        $ids = $appointments->pluck('id')->all();

        $events = AppointmentNotificationEvent::query()
            ->whereIn('appointment_id', $ids)
            ->with('deliveries')
            ->orderByDesc('created_at')
            ->orderByDesc('event_version')
            ->get()
            ->groupBy('appointment_id');

        $result = [];

        foreach ($appointments as $appointment) {
            $latest = $events->get($appointment->getKey())?->first();
            $result[$appointment->getKey()] = $latest === null
                ? null
                : $this->summarize($latest);
        }

        return $result;
    }

    /**
     * @return array{
     *     status: string,
     *     channel: string|null,
     *     event_type: string,
     *     event_label: string,
     *     label: string,
     *     sent_at: string|null,
     *     created_at: string|null,
     * }
     */
    public function summarize(AppointmentNotificationEvent $event): array
    {
        // Отношение уже загружено в forAppointments() — повторный запрос
        // deliveries на каждое событие давал N+1 на календаре.
        $deliveries = $event->relationLoaded('deliveries')
            ? $event->getRelation('deliveries')
            : $event->deliveries()->get();

        $channel = null;
        $status = 'pending';
        $sentAt = null;

        $delivered = $deliveries->firstWhere('status', AppointmentNotificationDelivery::STATUS_DELIVERED);
        $failed = $deliveries->firstWhere('status', AppointmentNotificationDelivery::STATUS_FAILED);
        $noChannel = $deliveries->firstWhere('status', AppointmentNotificationDelivery::STATUS_NO_CHANNEL);
        $superseded = $deliveries->firstWhere('status', AppointmentNotificationDelivery::STATUS_SUPERSEDED);
        $pending = $deliveries->firstWhere('status', AppointmentNotificationDelivery::STATUS_PENDING);

        if ($delivered !== null) {
            $status = 'sent';
            $channel = $delivered->channel;
            $sentAt = $delivered->delivered_at?->toIso8601String();
        } elseif ($failed !== null) {
            $status = 'failed';
            $channel = $failed->channel;
        } elseif ($noChannel !== null) {
            $status = 'no_channel';
        } elseif ($superseded !== null) {
            $status = 'superseded';
        } elseif ($pending !== null) {
            $status = 'pending';
            $channel = $pending->channel;
        } elseif ($deliveries->isEmpty()) {
            // Событие в outbox, job ещё не создал delivery-строк.
            $status = 'pending';
        }

        $type = $event->event_type->value;

        return [
            'status' => $status,
            'channel' => $channel,
            'event_type' => $type,
            'event_label' => $this->eventLabel($type),
            'label' => $this->label($status, $channel),
            'sent_at' => $sentAt,
            'created_at' => $event->created_at?->toIso8601String(),
        ];
    }

    /**
     * Человекочитаемое имя события для UI.
     */
    private function eventLabel(string $type): string
    {
        return match ($type) {
            AppointmentNotificationEventType::CreatedByMaster->value => 'Запись создана',
            AppointmentNotificationEventType::CancelledByMaster->value => 'Отмена записи',
            AppointmentNotificationEventType::Rescheduled->value => 'Перенос',
            AppointmentNotificationEventType::FieldsChanged->value => 'Изменение полей',
            AppointmentNotificationEventType::SeriesCreated->value => 'Создание серии',
            AppointmentNotificationEventType::SeriesUpdated->value => 'Изменение серии',
            AppointmentNotificationEventType::SeriesCancelled->value => 'Отмена серии',
            AppointmentNotificationEventType::BookingConfirmed->value => 'Подтверждение брони',
            default => $type,
        };
    }

    private function label(string $status, ?string $channel): string
    {
        $channelLabel = match ($channel) {
            AppointmentChannelResolver::CHANNEL_MAX => 'MAX',
            AppointmentChannelResolver::CHANNEL_VK => 'ВК',
            AppointmentChannelResolver::CHANNEL_TELEGRAM => 'Telegram',
            default => null,
        };

        return match ($status) {
            'sent' => $channelLabel !== null
                ? "Отправлено в {$channelLabel}"
                : 'Отправлено',
            'pending' => 'Ожидает отправки',
            'failed' => $channelLabel !== null
                ? "Не удалось отправить в {$channelLabel}"
                : 'Не удалось отправить',
            'no_channel' => 'Нет доступного канала',
            'superseded' => 'Устарело / не отправлялось',
            default => 'Ожидает отправки',
        };
    }
}
