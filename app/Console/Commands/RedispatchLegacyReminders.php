<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Jobs\SendAppointmentReminderJob;
use App\Models\Appointment;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ограниченная стратегия выкладки для legacy-напоминаний.
 *
 * Контекст: SendAppointmentReminderJob теперь требует schedule_version
 * (или снимок start_time) для верификации актуальности. Legacy-payload
 * без этих полей намеренно ничего не отправляет — отказ от
 * недоказуемо актуальной задачи. Но reminder_*_dispatched_at у записи
 * уже заполнен, scheduler повторно её не подберёт → действующее
 * напоминание теряется.
 *
 * Эта команда безопасно перевыставляет ТОЛЬКО необходимые актуальные
 * напоминания: сбрасывает dispatched_at для записей, у которых
 * напоминание ещё в окне и не подтверждено/не провалено. Scheduler
 * затем поставит job с актуальным schedule_version.
 *
 * Не очищает очередь целиком, не рассылает историю, не отправляет
 * повторно уже подтверждённые напоминания.
 */
class RedispatchLegacyReminders extends Command
{
    protected $signature = 'appointments:reminders:redispatch-legacy';

    protected $description = 'Сбросить dispatched_at у legacy-напоминаний, ещё находящихся в окне, для повторной постановки scheduler';

    public function handle(): int
    {
        $reset24h = $this->resetPendingWindow(
            dispatchedField: 'reminder_24h_dispatched_at',
            sentField: 'reminder_24h_sent_at',
            failedField: 'reminder_24h_failed_at',
            window: fn (Carbon $now) => [
                $now->copy()->addHours(23),
                $now->copy()->addHours(25),
            ],
        );

        $resetFinal = $this->resetPendingWindow(
            dispatchedField: 'reminder_final_dispatched_at',
            sentField: 'reminder_final_sent_at',
            failedField: 'reminder_final_failed_at',
            window: fn (Carbon $now) => [
                $now->copy(),
                $now->copy()->addHours(12),
            ],
        );

        $this->info(sprintf(
            'Legacy-напоминания: сброшено dispatched_at — 24h: %d, final: %d.',
            $reset24h,
            $resetFinal,
        ));

        return self::SUCCESS;
    }

    /**
     * Сбросить dispatched_at у записей, напоминание которых ещё в окне,
     * не подтверждено и не провалено.
     *
     * @param  callable(Carbon): array{0: Carbon, 1: Carbon}  $window
     */
    private function resetPendingWindow(
        string $dispatchedField,
        string $sentField,
        string $failedField,
        callable $window,
    ): int {
        $now = Carbon::now();
        [$windowStart, $windowEnd] = $window($now);

        $appointments = Appointment::query()
            ->whereIn('status', [
                AppointmentStatus::Booked,
                AppointmentStatus::Prepaid,
            ])
            ->whereNotNull($dispatchedField)
            ->whereNull($sentField)
            ->whereNull($failedField)
            ->where('start_time', '>=', $windowStart)
            ->where('start_time', '<=', $windowEnd)
            ->whereHas('client', function ($query) {
                $query->whereNotNull('max_id')
                    ->orWhereNotNull('vk_id')
                    ->orWhereNotNull('telegram_id');
            })
            ->get();

        $reset = 0;

        foreach ($appointments as $appointment) {
            // Условный сброс: только если поля ещё не изменились
            // (гонка с параллельной отправкой).
            $updated = DB::table('appointments')
                ->where('id', $appointment->id)
                ->whereNotNull($dispatchedField)
                ->whereNull($sentField)
                ->whereNull($failedField)
                ->update([$dispatchedField => null]);

            if ($updated === 1) {
                $reset++;
            }
        }

        if ($reset > 0) {
            Log::info('Legacy reminders redispatch: dispatched_at reset', [
                'field' => $dispatchedField,
                'count' => $reset,
            ]);
        }

        return $reset;
    }
}
