<?php

namespace App\Services\Notification;

use App\Enums\AppointmentNotificationEventType;
use App\Jobs\SendAppointmentNotificationJob;
use App\Models\Appointment;
use App\Models\AppointmentNotificationDelivery;
use App\Models\AppointmentNotificationEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Запись outbox-события клиентского уведомления и постановка job'а.
 *
 * record*() вызывается ВНУТРИ той же транзакции, что и изменение записи:
 * rollback убирает и намерение; commit оставляет устойчивую строку.
 * HTTP-постановка в очередь — только через DB::afterCommit.
 *
 * Идентичность события: (appointment, event_type, event_version), где
 * event_version = appointments.notification_version ПОСЛЕ bump'а изменения.
 * Повтор того же запроса без фактических изменений версию не двигает —
 * unique-индекс отклонит дубль, и второй job не создаётся.
 */
class AppointmentNotificationRecorder
{
    public function __construct(
        private AppointmentNotificationComposer $composer,
    ) {}

    /**
     * Увеличить версию изменения. Вызывать внутри транзакции изменения
     * ДО создания события. Без изменений не вызывать — версия не растёт.
     */
    public function bumpVersion(Appointment $appointment): int
    {
        $affected = Appointment::query()
            ->whereKey($appointment->getKey())
            ->update([
                'notification_version' => DB::raw('notification_version + 1'),
            ]);

        if ($affected !== 1) {
            // Запись исчезла из-под транзакции — событие не фиксируем.
            return (int) $appointment->notification_version;
        }

        $fresh = $appointment->fresh();
        $appointment->notification_version = $fresh !== null
            ? (int) $fresh->notification_version
            : ((int) $appointment->notification_version + 1);

        return (int) $appointment->notification_version;
    }

    /**
     * Записать событие и поставить job после коммита.
     *
     * @param  array<string, mixed>  $payload  снимок изменения
     */
    public function record(
        Appointment $appointment,
        AppointmentNotificationEventType $type,
        array $payload = [],
        ?int $eventVersion = null,
        ?string $recurringSeriesId = null,
    ): ?AppointmentNotificationEvent {
        $version = $eventVersion ?? (int) $appointment->notification_version;

        // Создание ещё не доставлено — правка не должна молча подавить
        // первое полное уведомление: объединяем недоставленное создание
        // с изменениями в одно понятное сообщение.
        //
        // Считается ПОДТВЕРЖДЁННАЯ доставка (status=delivered), а не
        // наличие любой delivery-строки: после неудачной отправки
        // pending-delivery сохраняется, но клиент полного контекста
        // не получил. Живой claim (lease в будущем) = первый исполнитель
        // в полёте — full_notice не ставим, чтобы не задублировать
        // контекст, который он вот-вот доставит.
        //
        // Флаг вычисляется до compose(): текст зависит от него.
        if (in_array($type, [
            AppointmentNotificationEventType::FieldsChanged,
            AppointmentNotificationEventType::Rescheduled,
        ], true)
            && ! $this->creationFullyDeliveredOrInFlight($appointment, $version)) {
            $payload['full_notice'] = true;
        }

        $text = $this->composer->compose($appointment, $type, $payload);

        if ($text === null) {
            return null;
        }

        $payload['text'] = $text;

        // Получатель фиксируется в событии: job сверяет его со свежим
        // appointment->client_id и не уходит старым снимком новому клиенту.
        if (! array_key_exists('client_id', $payload)) {
            $payload['client_id'] = $appointment->client_id;
        }

        try {
            // Вложенный DB::transaction даёт SAVEPOINT в PostgreSQL:
            // unique-конфликт откатывается только до savepoint'а и не
            // переводит внешнюю транзакцию в aborted-состояние.
            $event = DB::transaction(static function () use ($appointment, $type, $payload, $version, $recurringSeriesId): AppointmentNotificationEvent {
                return AppointmentNotificationEvent::create([
                    'appointment_id' => $appointment->getKey(),
                    'recurring_series_id' => $recurringSeriesId,
                    'event_type' => $type,
                    'event_version' => $version,
                    'payload' => $payload,
                ]);
            });
        } catch (\Throwable $e) {
            // Уже созданное событие той же идентичности (повтор запроса при
            // гонке) — не ошибка, второй job не нужен. В PostgreSQL unique
            // violation переводит транзакцию в aborted-состояние, поэтому
            // ловим и UniqueConstraintViolationException, и QueryException.
            $isUnique = $e instanceof UniqueConstraintViolationException
                || str_contains($e->getMessage(), 'unique')
                || str_contains($e->getMessage(), '23505')
                || ($e->errorInfo[0] ?? '') === '23505';

            if ($isUnique) {
                Log::info('Appointment notification event already exists; skip duplicate', [
                    'appointment_id' => $appointment->getKey(),
                    'event_type' => $type->value,
                    'event_version' => $version,
                ]);

                return null;
            }

            throw $e;
        }

        $this->dispatchAfterCommit($event);

        return $event;
    }

    /**
     * Переставить job для строк с dispatched_at IS NULL (после commit-сбоя).
     * Используется recovery-командой; идемпотентность — на стороне job'а.
     */
    public function dispatchAfterCommit(AppointmentNotificationEvent $event): void
    {
        DB::afterCommit(static function () use ($event): void {
            try {
                SendAppointmentNotificationJob::dispatch($event->getKey());
                AppointmentNotificationEvent::whereKey($event->getKey())
                    ->update(['dispatched_at' => now()]);
            } catch (\Throwable $e) {
                Log::error('Appointment notification event not enqueued; recovery will retry', [
                    'event_id' => $event->getKey(),
                    'appointment_id' => $event->appointment_id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Создание уже доставлено клиенту ИЛИ первый исполнитель в полёте
     * (живой claim). В обоих случаях полный контекст клиента достигнет
     * или уже достиг — full_notice не нужен. После неудачной попытки
     * (pending без живого lease, нет delivered) — full_notice обязателен.
     */
    private function creationFullyDeliveredOrInFlight(Appointment $appointment, int $currentVersion): bool
    {
        $creation = AppointmentNotificationEvent::query()
            ->where('appointment_id', $appointment->getKey())
            ->where('event_type', AppointmentNotificationEventType::CreatedByMaster)
            ->where('event_version', '<', $currentVersion)
            ->orderByDesc('event_version')
            ->first();

        if ($creation === null) {
            // Нет события создания — нечего объединять.
            return true;
        }

        // Подтверждённая доставка: клиент уже получил полный контекст.
        $delivered = $creation->deliveries()
            ->where('status', AppointmentNotificationDelivery::STATUS_DELIVERED)
            ->exists();

        if ($delivered) {
            return true;
        }

        // Живой claim: первый исполнитель внутри HTTP — не дублируем.
        $inFlight = $creation->deliveries()
            ->where('status', AppointmentNotificationDelivery::STATUS_PENDING)
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '>', now())
            ->exists();

        return $inFlight;
    }
}
