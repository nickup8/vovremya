<?php

namespace App\Services\Notification;

use App\Enums\AppointmentNotificationEventType;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Составление текстов клиентских уведомлений о записи (MAX/ВК).
 *
 * Текст фиксируется в payload события на момент изменения: позднейшая
 * правка каталога или карточки мастера не меняет уже зафиксированное
 * сообщение. Telegram-клавиатуры/кнопки подтверждения сюда не входят —
 * это текстовые уведомления без client-side actions.
 */
class AppointmentNotificationComposer
{
    /**
     * @param  array<string, mixed>  $payload  снимок изменения из recorder'а
     * @return string|null null — для этого типа/снимка сообщение не требуется
     */
    public function compose(
        Appointment $appointment,
        AppointmentNotificationEventType $type,
        array $payload,
    ): ?string {
        $appointment->loadMissing(['master', 'client', 'masterService.catalog']);

        return match ($type) {
            AppointmentNotificationEventType::CreatedByMaster => $this->createdByMaster($appointment),
            AppointmentNotificationEventType::CancelledByMaster => $this->cancelledByMaster($appointment, $payload),
            AppointmentNotificationEventType::Rescheduled => $this->rescheduled($appointment, $payload),
            AppointmentNotificationEventType::FieldsChanged => $this->fieldsChanged($appointment, $payload),
            AppointmentNotificationEventType::SeriesCreated => $this->seriesCreated($appointment, $payload),
            AppointmentNotificationEventType::SeriesUpdated => $this->seriesUpdated($appointment, $payload),
            AppointmentNotificationEventType::SeriesCancelled => $this->seriesCancelled($appointment, $payload),
            AppointmentNotificationEventType::BookingConfirmed => $this->bookingConfirmed($appointment),
        };
    }

    private function createdByMaster(Appointment $appointment): string
    {
        $master = $appointment->master;
        $tz = $master?->getTimezone() ?? 'UTC';
        $start = $appointment->start_time->timezone($tz);

        return __('bot.client.created_by_master', [
            'master' => $master?->name ?? __('bot.fallback.master_name'),
            'service' => $appointment->display_name,
            'date' => $start->format('d.m.Y'),
            'time' => $start->format('H:i'),
            'duration' => $appointment->display_duration,
            'price' => $this->formatPrice($appointment->display_price),
            'address' => $master?->address ?: __('bot.fallback.address'),
        ]);
    }

    private function cancelledByMaster(Appointment $appointment, array $payload): string
    {
        $master = $appointment->master;
        $tz = $master?->getTimezone() ?? 'UTC';

        $start = isset($payload['start_time'])
            ? Carbon::parse($payload['start_time'])->timezone($tz)
            : $appointment->start_time->timezone($tz);

        return __('bot.client.cancelled_by_master', [
            'service' => $payload['service_name'] ?? $appointment->display_name,
            'date' => $start->format('d.m.Y'),
            'time' => $start->format('H:i'),
        ]);
    }

    private function rescheduled(Appointment $appointment, array $payload): string
    {
        $master = $appointment->master;
        $tz = $master?->getTimezone() ?? 'UTC';

        $oldStart = Carbon::parse($payload['old_start_time'])->timezone($tz);
        $newStart = isset($payload['new_start_time'])
            ? Carbon::parse($payload['new_start_time'])->timezone($tz)
            : $appointment->start_time->timezone($tz);

        $params = [
            'service' => $payload['service_name'] ?? $appointment->display_name,
            'old_date' => $oldStart->format('d.m.Y'),
            'old_time' => $oldStart->format('H:i'),
            'new_date' => $newStart->format('d.m.Y'),
            'new_time' => $newStart->format('H:i'),
        ];

        $oldMasterId = $payload['old_master_id'] ?? null;
        $newMasterId = $payload['new_master_id'] ?? $appointment->master_id;

        if ($oldMasterId !== null && (string) $oldMasterId !== (string) $newMasterId) {
            $params['old_master'] = $this->masterName($oldMasterId);
            $params['new_master'] = $master?->name ?? $this->masterName($newMasterId);

            return __('bot.client.rescheduled_master_changed', $params);
        }

        return __('bot.client.rescheduled', $params);
    }

    private function fieldsChanged(Appointment $appointment, array $payload): string
    {
        $lines = [];

        $changes = $payload['changes'] ?? [];

        if (isset($changes['service_name'])) {
            $lines[] = __('bot.client.change.service', [
                'old' => $changes['service_name']['old'] ?? '—',
                'new' => $changes['service_name']['new'] ?? '—',
            ]);
        }

        if (isset($changes['duration'])) {
            $lines[] = __('bot.client.change.duration', [
                'old' => $changes['duration']['old'] ?? '—',
                'new' => $changes['duration']['new'] ?? '—',
            ]);
        }

        if (isset($changes['price'])) {
            $lines[] = __('bot.client.change.price', [
                'old' => $this->formatPrice((float) ($changes['price']['old'] ?? 0)),
                'new' => $this->formatPrice((float) ($changes['price']['new'] ?? 0)),
            ]);
        }

        if ($lines === []) {
            return null;
        }

        $master = $appointment->master;
        $tz = $master?->getTimezone() ?? 'UTC';
        $start = $appointment->start_time->timezone($tz);

        return __('bot.client.fields_changed', [
            'service' => $appointment->display_name,
            'date' => $start->format('d.m.Y'),
            'time' => $start->format('H:i'),
            'changes' => implode("\n", $lines),
        ]);
    }

    private function seriesCreated(Appointment $appointment, array $payload): string
    {
        $master = $appointment->master;
        $tz = $master?->getTimezone() ?? 'UTC';
        $start = $appointment->start_time->timezone($tz);

        return __('bot.client.series_created', [
            'master' => $master?->name ?? __('bot.fallback.master_name'),
            'service' => $payload['service_name'] ?? $appointment->display_name,
            'time' => $start->format('H:i'),
            'count' => $payload['affected_count'] ?? 0,
            'first_date' => $start->format('d.m.Y'),
            'address' => $master?->address ?: __('bot.fallback.address'),
        ]);
    }

    private function seriesUpdated(Appointment $appointment, array $payload): string
    {
        return __('bot.client.series_updated', [
            'service' => $payload['service_name'] ?? $appointment->display_name,
            'count' => $payload['affected_count'] ?? 0,
            'date' => $payload['from_date'] ?? $appointment->start_time->timezone(
                $appointment->master?->getTimezone() ?? 'UTC'
            )->format('d.m.Y'),
        ]);
    }

    private function seriesCancelled(Appointment $appointment, array $payload): string
    {
        return __('bot.client.series_cancelled', [
            'service' => $payload['service_name'] ?? $appointment->display_name,
            'count' => $payload['affected_count'] ?? 0,
            'date' => $payload['from_date'] ?? $appointment->start_time->timezone(
                $appointment->master?->getTimezone() ?? 'UTC'
            )->format('d.m.Y'),
        ]);
    }

    /**
     * Подтверждение брони клиентом (widget MAX/ВК): прежний текст
     * bot.booking_confirmed, зафиксированный в outbox-событии.
     */
    private function bookingConfirmed(Appointment $appointment): ?string
    {
        $master = $appointment->master;
        $tz = $master?->getTimezone() ?? 'UTC';
        $start = $appointment->start_time->timezone($tz);

        $message = __('bot.booking_confirmed', [
            'service' => $appointment->display_name,
            'date' => $start->format('d.m.Y'),
            'time' => $start->format('H:i'),
            'price' => $appointment->display_price,
        ]);

        if ($master?->address) {
            $message .= __('bot.booking_confirmed_address', ['address' => $master->address]);
        }

        $message .= __('bot.booking_confirmed_suffix');

        return $message;
    }

    private function masterName(?string $masterId): string
    {
        if ($masterId === null) {
            return __('bot.fallback.master_name');
        }

        return User::query()->whereKey($masterId)->value('name')
            ?? __('bot.fallback.master_name');
    }

    private function formatPrice(float $price): string
    {
        if ($price <= 0) {
            return '—';
        }

        return number_format($price, 0, '.', ' ').' ₽';
    }
}
