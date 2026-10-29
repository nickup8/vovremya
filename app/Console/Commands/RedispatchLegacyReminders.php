<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
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
 * напоминания:
 * - записи с versioned-job'ом в очереди не сбрасываются — их постановка
 *   принадлежит новому поколению, сброс дал бы дубль;
 * - UPDATE пинит прочитанное значение dispatched_at — запись,
 *   изменившаяся после выборки (параллельная постановка/отправка),
 *   не сбрасывается;
 * - подтверждённые/провалённые/вне окна не трогаются.
 *
 * Scheduler после сброса поставит job с актуальным schedule_version.
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
            reminderType: '24h',
            window: fn (Carbon $now) => [
                $now->copy()->addHours(23),
                $now->copy()->addHours(25),
            ],
        );

        $resetFinal = $this->resetPendingWindow(
            dispatchedField: 'reminder_final_dispatched_at',
            sentField: 'reminder_final_sent_at',
            failedField: 'reminder_final_failed_at',
            reminderType: 'final',
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
     * не подтверждено, не провалено и не принадлежит versioned-job'у
     * в очереди.
     *
     * @param  callable(Carbon): array{0: Carbon, 1: Carbon}  $window
     */
    private function resetPendingWindow(
        string $dispatchedField,
        string $sentField,
        string $failedField,
        string $reminderType,
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
            // Versioned-job в очереди: запись обслуживается новым
            // поколением постановки — сброс создал бы дубль.
            if ($this->hasQueuedVersionedReminderJob($appointment->getKey(), $reminderType)) {
                continue;
            }

            if ($this->resetOne($appointment, $dispatchedField, $sentField, $failedField)) {
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

    /**
     * Условный сброс с пином прочитанного значения dispatched_at:
     * запись, изменившаяся после выборки (новая постановка, отправка,
     * провал), UPDATE не совпадёт и не будет сброшена.
     */
    protected function resetOne(
        Appointment $appointment,
        string $dispatchedField,
        string $sentField,
        string $failedField,
    ): bool {
        return DB::table('appointments')
            ->where('id', $appointment->id)
            ->where($dispatchedField, $appointment->{$dispatchedField})
            ->whereNull($sentField)
            ->whereNull($failedField)
            ->update([$dispatchedField => null]) === 1;
    }

    /**
     * В database-очереди есть versioned-job напоминания (payload содержит
     * зафиксированный expectedScheduleVersion) данного визита и типа.
     * Legacy-jobs (без поля или null) ничего не отправляют и запись не
     * удерживают.
     */
    private function hasQueuedVersionedReminderJob(int|string $appointmentId, string $reminderType): bool
    {
        $payloads = DB::table('jobs')
            ->where('payload', 'like', '%SendAppointmentReminderJob%')
            ->pluck('payload');

        foreach ($payloads as $payload) {
            // Payload хранится как JSON: кавычки сериализованной команды
            // экранированы — раскодируем command перед сверкой.
            $decoded = json_decode((string) $payload, true);
            $command = $decoded['data']['command'] ?? null;

            if (! is_string($command) || $command === '') {
                continue;
            }

            if (! str_contains($command, 'expectedScheduleVersion";i:')) {
                continue;
            }

            // ModelIdentifier хранит ключ в s:2:"id";i:N (число) либо
            // s:2:"id";s:L:"..." (строка). Непойманная ветка alternation
            // даёт пустую строку, а не null — сверяем обе формы отдельно.
            if (preg_match('/s:2:"id";i:(\d+);/', $command, $intMatch)) {
                $queuedId = $intMatch[1];
            } elseif (preg_match('/s:2:"id";s:\d+:"([^"]+)";/', $command, $stringMatch)) {
                $queuedId = $stringMatch[1];
            } else {
                continue;
            }

            if ((string) $queuedId !== (string) $appointmentId) {
                continue;
            }

            if (! str_contains($command, '"'.$reminderType.'"')) {
                continue;
            }

            return true;
        }

        return false;
    }
}
