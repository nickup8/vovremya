<?php

namespace App\Services;

use App\DTOs\AppointmentWindowFreed;
use App\Enums\AppointmentNotificationEventType;
use App\Enums\AppointmentStatus;
use App\Enums\SlotOpportunitySourceType;
use App\Events\AppointmentStatusChanged;
use App\Exceptions\CancellationNotAllowedException;
use App\Exceptions\InvalidStatusTransitionException;
use App\Exceptions\PastAppointmentException;
use App\Models\Appointment;
use App\Models\User;
use App\Services\Notification\AppointmentNotificationRecorder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AppointmentStatusService
{
    public function transition(
        Appointment $appointment,
        AppointmentStatus $to,
        ?Authenticatable $actor = null,
        bool $notifyClient = true,
    ): Appointment {
        $from = $appointment->status;

        if ($from === $to) {
            return $appointment;
        }

        // === ADR-9: временно́й гейт переходов ===
        $isGatedTransition =
            $to === AppointmentStatus::Cancelled                          // п.1: любой → cancelled
            || ($from === AppointmentStatus::Cancelled
                && $to === AppointmentStatus::Booked);                     // п.2: воскрешение

        if ($isGatedTransition && $appointment->start_time->isPast()) {
            $isSystem = $actor === null;                              // крон/artisan
            $isSuperAdmin = $actor instanceof User
                            && $actor->isSuperAdmin();

            if (! $isSystem && ! $isSuperAdmin) {
                throw new PastAppointmentException;
            }
        }
        // === /ADR-9 ===

        if (! $from->canTransitionTo($to)) {
            throw new InvalidStatusTransitionException($from, $to);
        }

        // AutoFill: capture freed-window snapshot BEFORE mutation
        $freedWindow = $this->captureFreedWindow($appointment, $from, $to, $actor);

        $updateData = ['status' => $to];
        if ($to === AppointmentStatus::Cancelled) {
            $updateData['cancelled_at'] = now();
            $updateData['cancelled_by'] = $actor instanceof User
                ? $actor->id
                : null;
        }

        // Жизненный цикл completed_at (аналитика завершённых услуг).
        // Вход в Paid из любого статуса → фиксируем момент завершения (в т.ч. повторный Paid).
        // Выход Paid → NoShow → услуга больше не завершена, сбрасываем.
        if ($to === AppointmentStatus::Paid) {
            $updateData['completed_at'] = now();
        } elseif ($from === AppointmentStatus::Paid && $to === AppointmentStatus::NoShow) {
            $updateData['completed_at'] = null;
        }

        // Статус + версия + outbox — одна транзакция: сбой записи
        // события откатывает и мутацию, коммит фиксирует всё разом.
        // Вложенный вызов (внешняя транзакция) работает через savepoint.
        DB::transaction(function () use (
            $appointment, $updateData, $to, $notifyClient,
        ): void {
            $appointment->update($updateData);

            if ($to === AppointmentStatus::Cancelled && $appointment->client_id !== null) {
                $recorder = app(AppointmentNotificationRecorder::class);

                // Версия бампится ВСЕГДА при отмене визита с клиентом —
                // иначе при notifyClient=false (серийные bulk-операции)
                // старые jobs остальных визитов остаются актуальными и
                // отправляют подтверждение уже отменённой записи.
                $version = $recorder->bumpVersion($appointment);

                // Отмена мастером/админом: «Мастер отменил запись».
                // Инициатор достоверен по cancelled_by (User) — отмена
                // клиентом или автоматическая (actor = null / Client)
                // индивидуального сообщения не порождают.
                // $notifyClient=false — серийные bulk-операции шлют одно
                // согласованное series-сообщение вместо десятков
                // per-appointment.
                if ($notifyClient && $appointment->cancelled_by !== null) {
                    $recorder->record(
                        $appointment,
                        AppointmentNotificationEventType::CancelledByMaster,
                        [
                            'service_name' => $appointment->display_name,
                            'start_time' => $appointment->start_time->toIso8601String(),
                            'cancelled_by' => $appointment->cancelled_by,
                        ],
                        $version,
                    );
                }
            }
        });

        // AutoFill: dispatch opportunity creation after commit
        if ($freedWindow !== null) {
            app(FreedWindowDispatcher::class)->dispatchAfterCommit($freedWindow);
        }

        broadcast(new AppointmentStatusChanged(
            $appointment->load(['client']),
            $from,
            $to,
        ));

        Log::info('Appointment status transitioned', [
            'appointment_id' => $appointment->id,
            'from' => $from->value,
            'to' => $to->value,
            'master_id' => $appointment->master_id,
        ]);

        return $appointment;
    }

    /**
     * Проверяет, можно ли отменить запись. Бросает исключение при невозможности.
     * Ничего не отменяет и не шлёт сообщений — только проверяет.
     */
    public function assertCanCancel(Appointment $appointment): void
    {
        if ($appointment->status !== AppointmentStatus::Booked || $appointment->start_time->isPast()) {
            throw new CancellationNotAllowedException('not_cancellable');
        }

        $deadlineHours = $appointment->master->cancellation_deadline_hours;

        if ($deadlineHours !== null && $deadlineHours > 0) {
            $limit = $appointment->start_time->copy()->subHours($deadlineHours);

            if (now()->gte($limit)) {
                throw new CancellationNotAllowedException('deadline_passed', $deadlineHours);
            }
        }
    }

    private function captureFreedWindow(
        Appointment $appointment,
        AppointmentStatus $from,
        AppointmentStatus $to,
        ?Authenticatable $actor = null,
    ): ?AppointmentWindowFreed {
        if ($from !== AppointmentStatus::Booked || $to !== AppointmentStatus::Cancelled) {
            return null;
        }

        if ($appointment->client_id === null && $actor === null) {
            return null;
        }

        if ($appointment->master_service_id === null) {
            return null;
        }

        $duration = $appointment->duration;

        if ($duration === null || $duration <= 0) {
            return null;
        }

        $master = $appointment->master;

        if ($master === null || ! $master->isAutoFillEnabled()) {
            return null;
        }

        return new AppointmentWindowFreed(
            originEventId: (string) Str::uuid(),
            chainId: null,
            workspaceId: $master->workspace_id,
            masterId: $appointment->master_id,
            masterServiceId: $appointment->master_service_id,
            sourceAppointmentId: $appointment->id,
            sourceType: SlotOpportunitySourceType::Cancellation,
            startTime: $appointment->start_time,
            duration: $duration,
        );
    }
}
