<?php

namespace App\Services\Notification;

use App\Enums\AppointmentNotificationEventType;
use App\Models\Appointment;
use App\Models\User;
use App\Support\BotText;
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
        $phone = BotText::masterPhone($master);

        return BotText::render('bot.client.created_by_master', [
            'master' => $master?->name ?? __('bot.fallback.master_name'),
            'master_phone' => $phone,
            'service' => $appointment->display_name,
            'date' => $start->format('d.m.Y'),
            'time' => $start->format('H:i'),
            'duration' => $appointment->display_duration,
            'price' => BotText::price((float) $appointment->display_price),
            'address' => $master?->address ?? '',
            'instruction' => BotText::cabinetInstruction($phone),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function cancelledByMaster(Appointment $appointment, array $payload, bool $rebook = true): string
    {
        $master = $appointment->master;
        $tz = $master?->getTimezone() ?? 'UTC';

        $start = isset($payload['start_time'])
            ? Carbon::parse($payload['start_time'])->timezone($tz)
            : $appointment->start_time->timezone($tz);

        $phone = BotText::masterPhone($master);

        return BotText::render('bot.client.cancelled_by_master', [
            'master' => $master?->name ?? __('bot.fallback.master_name'),
            'master_phone' => $phone,
            'service' => $payload['service_name'] ?? $appointment->display_name,
            'date' => $start->format('d.m.Y'),
            'time' => $start->format('H:i'),
            'instruction' => $rebook
                ? BotText::rebookInstruction('bot.instructions.cancel_rebook', $master)
                : '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function rescheduled(Appointment $appointment, array $payload): string
    {
        // Создание ещё не доставлено — текст остаётся полным, чтобы
        // клиент понимал, куда и когда он записан.
        if ($payload['full_notice'] ?? false) {
            return $this->createdByMaster($appointment).$this->changeLinesSuffix($payload);
        }

        $master = $appointment->master;
        $newTz = $master?->getTimezone() ?? 'UTC';

        $oldMasterId = $payload['old_master_id'] ?? null;
        $newMasterId = $payload['new_master_id'] ?? $appointment->master_id;
        $masterChanged = $oldMasterId !== null
            && (string) $oldMasterId !== (string) $newMasterId;

        // Прежнее время показывается в поясе прежнего мастера, новое —
        // в поясе нового. Снимка старого пояса в payload нет: берётся
        // текущий timezone строки old_master_id (композиция — на момент
        // события); если прежняя строка удалена, прежнее время показывается
        // в поясе нового мастера.
        $oldMaster = $masterChanged ? User::query()->whereKey($oldMasterId)->first() : null;
        $oldTz = $oldMaster?->getTimezone() ?? $newTz;

        $oldStart = Carbon::parse($payload['old_start_time'])->timezone($oldTz);
        $newStart = isset($payload['new_start_time'])
            ? Carbon::parse($payload['new_start_time'])->timezone($newTz)
            : $appointment->start_time->timezone($newTz);

        $phone = BotText::masterPhone($master);

        $params = [
            'master_phone' => $phone,
            'service' => $payload['service_name'] ?? $appointment->display_name,
            'old_date' => $oldStart->format('d.m.Y'),
            'old_time' => $oldStart->format('H:i'),
            'new_date' => $newStart->format('d.m.Y'),
            'new_time' => $newStart->format('H:i'),
            'instruction' => BotText::callToMaster('bot.instructions.reschedule_call', $phone),
        ];

        if ($masterChanged) {
            $params['old_master'] = $oldMaster?->name ?? $this->masterName($oldMasterId);
            $params['new_master'] = $master?->name ?? $this->masterName($newMasterId);
            // Телефон и адрес — нового мастера.
            $params['address'] = $master?->address ?? '';

            $key = 'bot.client.rescheduled_master_changed';
        } else {
            $params['master'] = $master?->name ?? __('bot.fallback.master_name');

            $key = 'bot.client.rescheduled';
        }

        $text = BotText::render($key, $params);

        // Перенос и правка полей одним сохранением — одно согласованное
        // сообщение: изменения приписываются к тексту о переносе.
        return $text.$this->changeLinesSuffix($payload);
    }

    /**
     * Приписка строк изменений полей к основному тексту сообщения.
     *
     * @param  array<string, mixed>  $payload
     */
    private function changeLinesSuffix(array $payload): string
    {
        $changes = $payload['changes'] ?? [];

        if (! is_array($changes) || $changes === []) {
            return '';
        }

        $lines = $this->changeLines($changes);

        return $lines === '' ? '' : "\n\n".$lines;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fieldsChanged(Appointment $appointment, array $payload): ?string
    {
        $changes = $payload['changes'] ?? [];

        $changeLines = is_array($changes) ? $this->changeLines($changes) : '';

        if ($changeLines === '' && ! ($payload['full_notice'] ?? false)) {
            return null;
        }

        // Создание ещё не доставлено: полное сообщение с актуальными
        // данными записи, к нему приписаны строки изменений.
        if ($payload['full_notice'] ?? false) {
            return $this->createdByMaster($appointment)
                .($changeLines === '' ? '' : "\n\n".$changeLines);
        }

        $master = $appointment->master;
        $tz = $master?->getTimezone() ?? 'UTC';
        $start = $appointment->start_time->timezone($tz);
        $phone = BotText::masterPhone($master);

        return BotText::render('bot.client.fields_changed', [
            'master' => $master?->name ?? __('bot.fallback.master_name'),
            'master_phone' => $phone,
            'date' => $start->format('d.m.Y'),
            'time' => $start->format('H:i'),
            'changes' => $changeLines,
            'instruction' => BotText::callToMaster('bot.instructions.changes_call', $phone),
        ]);
    }

    /**
     * Строки изменений полей (услуга/длительность/цена).
     *
     * @param  array<string, mixed>  $changes
     */
    private function changeLines(array $changes): string
    {
        $lines = [];

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
                'old' => BotText::price((float) ($changes['price']['old'] ?? 0)),
                'new' => BotText::price((float) ($changes['price']['new'] ?? 0)),
            ]);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function seriesCreated(Appointment $appointment, array $payload): string
    {
        $master = $appointment->master;
        $tz = $master?->getTimezone() ?? 'UTC';
        $start = $appointment->start_time->timezone($tz);

        return BotText::render('bot.client.series_created', [
            'master' => $master?->name ?? __('bot.fallback.master_name'),
            'master_phone' => BotText::masterPhone($master),
            'service' => $payload['service_name'] ?? $appointment->display_name,
            'time' => $start->format('H:i'),
            'count' => $payload['affected_count'] ?? 0,
            'first_date' => $start->format('d.m.Y'),
            'address' => $master?->address ?? '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function seriesUpdated(Appointment $appointment, array $payload): string
    {
        $master = $appointment->master;
        $tz = $master?->getTimezone() ?? 'UTC';

        // Время приёма показывается, только когда оно есть в снимке:
        // событие не подтверждает общее время для визитов с разным
        // временем (null — строка опускается).
        $time = array_key_exists('time', $payload)
            ? (string) ($payload['time'] ?? '')
            : $appointment->start_time->timezone($tz)->format('H:i');

        return BotText::render('bot.client.series_updated', [
            'master' => $master?->name ?? __('bot.fallback.master_name'),
            'master_phone' => BotText::masterPhone($master),
            'service' => $payload['service_name'] ?? $appointment->display_name,
            'count' => $payload['affected_count'] ?? 0,
            'date' => $payload['from_date'] ?? $appointment->start_time->timezone($tz)->format('d.m.Y'),
            'time' => $time,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function seriesCancelled(Appointment $appointment, array $payload, bool $rebook = true): string
    {
        $master = $appointment->master;
        $phone = BotText::masterPhone($master);

        return BotText::render('bot.client.series_cancelled', [
            'master' => $master?->name ?? __('bot.fallback.master_name'),
            'master_phone' => $phone,
            'service' => $payload['service_name'] ?? $appointment->display_name,
            'count' => $payload['affected_count'] ?? 0,
            'date' => $payload['from_date'] ?? $appointment->start_time->timezone(
                $master?->getTimezone() ?? 'UTC'
            )->format('d.m.Y'),
            'instruction' => $rebook
                ? BotText::rebookInstruction('bot.instructions.series_cancel_rebook', $master)
                : '',
        ]);
    }

    /**
     * Снимок повторной записи для событий отмены: исходный мастер,
     * сохранённый URL виджета и согласованный вариант текста без
     * инструкции (payload['text'] хранит вариант с инструкцией).
     *
     * Выбираются ОДНИМ правилом с compose(): если ссылка не формируется
     * (мастер/виджет недоступны) — пусто, текст остаётся без CTA и при
     * позднем появлении ссылки не превращается в новый. Для событий не
     * отмен и для старого формата полей не добавляется.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function rebookSnapshot(
        Appointment $appointment,
        AppointmentNotificationEventType $type,
        array $payload,
    ): array {
        if (! in_array($type, [
            AppointmentNotificationEventType::CancelledByMaster,
            AppointmentNotificationEventType::SeriesCancelled,
        ], true)) {
            return [];
        }

        $appointment->loadMissing(['master', 'client', 'masterService.catalog']);

        $url = BotText::bookingWidgetUrl($appointment->master);

        if ($url === null) {
            return [];
        }

        return [
            'rebook_master_id' => $appointment->master?->getKey(),
            'rebook_url' => $url,
            'text_no_rebook' => $type === AppointmentNotificationEventType::CancelledByMaster
                ? $this->cancelledByMaster($appointment, $payload, false)
                : $this->seriesCancelled($appointment, $payload, false),
        ];
    }

    /**
     * Подтверждение брони клиентом (widget MAX/ВК): текст
     * bot.booking_confirmed, зафиксированный в outbox-событии.
     */
    private function bookingConfirmed(Appointment $appointment): string
    {
        $master = $appointment->master;
        $tz = $master?->getTimezone() ?? 'UTC';
        $start = $appointment->start_time->timezone($tz);
        $phone = BotText::masterPhone($master);

        return BotText::render('bot.booking_confirmed', [
            'master' => $master?->name ?? __('bot.fallback.master_name'),
            'master_phone' => $phone,
            'service' => $appointment->display_name,
            'date' => $start->format('d.m.Y'),
            'time' => $start->format('H:i'),
            'price' => BotText::price((float) $appointment->display_price),
            'address' => $master?->address ?? '',
            'instruction' => BotText::cabinetInstruction($phone),
        ]);
    }

    private function masterName(?string $masterId): string
    {
        if ($masterId === null) {
            return __('bot.fallback.master_name');
        }

        return User::query()->whereKey($masterId)->value('name')
            ?? __('bot.fallback.master_name');
    }
}
