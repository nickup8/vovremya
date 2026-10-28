<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Jobs\SendAppointmentReminderJob;
use App\Models\Appointment;
use App\Services\Notification\AppointmentChannelResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Постановка задач напоминаний (24h и final).
 *
 * Статусы: Booked и Prepaid — будущие действующие визиты. Cancelled, NoShow,
 * Paid и неподтверждённые черновики (PendingPayment без привязанного клиента
 * / widget-черновики) исключаются. PendingPayment автоматически не добавляется:
 * это статус до предоплаты/подтверждения брони, контракт бронирования его
 * для напоминаний не предусматривает (исторически reminders шли только по Booked).
 *
 * Claim — атомарный UPDATE dispatched_at; после переноса/отмены поля сбрасываются,
 * поэтому новая версия расписания снова может получить свои напоминания.
 */
class SendRemindersCommand extends Command
{
    protected $signature = 'appointments:reminders';

    protected $description = 'Send appointment reminders (24h and final)';

    public function handle(): int
    {
        $this->send24hReminders();
        $this->sendFinalReminders();

        return self::SUCCESS;
    }

    private function send24hReminders(): void
    {
        $now = Carbon::now();

        $appointments = Appointment::with(['master', 'client'])
            ->whereIn('status', [
                AppointmentStatus::Booked,
                AppointmentStatus::Prepaid,
            ])
            ->whereNull('reminder_24h_sent_at')
            ->whereNull('reminder_24h_dispatched_at')
            ->whereNull('reminder_24h_failed_at')
            ->whereBetween('start_time', [
                $now->copy()->addHours(23),
                $now->copy()->addHours(25),
            ])
            ->get();

        $dispatched = 0;
        $resolver = app(AppointmentChannelResolver::class);

        foreach ($appointments as $appointment) {
            if (! $appointment->client) {
                continue;
            }

            if (! $resolver->hasAnyChannel($appointment)) {
                continue;
            }

            $claimed = DB::table('appointments')
                ->where('id', $appointment->id)
                ->whereNull('reminder_24h_sent_at')
                ->whereNull('reminder_24h_dispatched_at')
                ->whereNull('reminder_24h_failed_at')
                ->update(['reminder_24h_dispatched_at' => now()]);

            if ($claimed !== 1) {
                continue;
            }

            try {
                SendAppointmentReminderJob::dispatch($appointment, '24h');
                $dispatched++;
            } catch (\Throwable $e) {
                DB::table('appointments')
                    ->where('id', $appointment->id)
                    ->whereNull('reminder_24h_sent_at')
                    ->whereNull('reminder_24h_failed_at')
                    ->update(['reminder_24h_dispatched_at' => null]);

                Log::warning('Failed to dispatch 24h reminder', [
                    'appointment_id' => $appointment->id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }

        $this->info("Dispatched {$dispatched} 24h reminders.");
    }

    private function sendFinalReminders(): void
    {
        $now = Carbon::now();

        $appointments = Appointment::with(['master', 'client'])
            ->whereIn('status', [
                AppointmentStatus::Booked,
                AppointmentStatus::Prepaid,
            ])
            ->whereNull('reminder_final_sent_at')
            ->whereNull('reminder_final_dispatched_at')
            ->whereNull('reminder_final_failed_at')
            ->where('start_time', '>', $now->copy())
            ->where('start_time', '<=', $now->copy()->addHours(12))
            ->get();

        $dispatched = 0;
        $resolver = app(AppointmentChannelResolver::class);

        foreach ($appointments as $appointment) {
            if (! $appointment->client) {
                continue;
            }

            if (! $resolver->hasAnyChannel($appointment)) {
                continue;
            }

            $hoursBeforeFinal = $appointment->master->getReminderHoursBeforeFinal();

            if ($hoursBeforeFinal === 0) {
                continue;
            }

            $windowStart = $appointment->start_time->copy()->subHours($hoursBeforeFinal);

            if ($now->lt($windowStart)) {
                continue;
            }

            $claimed = DB::table('appointments')
                ->where('id', $appointment->id)
                ->whereNull('reminder_final_sent_at')
                ->whereNull('reminder_final_dispatched_at')
                ->whereNull('reminder_final_failed_at')
                ->update(['reminder_final_dispatched_at' => now()]);

            if ($claimed !== 1) {
                continue;
            }

            try {
                SendAppointmentReminderJob::dispatch($appointment, 'final');
                $dispatched++;
            } catch (\Throwable $e) {
                DB::table('appointments')
                    ->where('id', $appointment->id)
                    ->whereNull('reminder_final_sent_at')
                    ->whereNull('reminder_final_failed_at')
                    ->update(['reminder_final_dispatched_at' => null]);

                Log::warning('Failed to dispatch final reminder', [
                    'appointment_id' => $appointment->id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }

        $this->info("Dispatched {$dispatched} final reminders.");
    }
}
