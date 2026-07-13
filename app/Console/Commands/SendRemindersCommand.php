<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Jobs\SendAppointmentReminderJob;
use App\Models\Appointment;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

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

        $appointments = Appointment::with(['master', 'service', 'client'])
            ->where('status', AppointmentStatus::Booked)
            ->whereNull('reminder_24h_sent_at')
            ->whereBetween('start_time', [
                $now->copy()->addHours(23),
                $now->copy()->addHours(25),
            ])
            ->get();

        $dispatched = 0;

        foreach ($appointments as $appointment) {
            if (! $appointment->client) {
                continue;
            }

            if (! $appointment->client->telegram_id && ! $appointment->client->max_id) {
                continue;
            }

            $appointment->update(['reminder_24h_sent_at' => $now]);
            SendAppointmentReminderJob::dispatch($appointment, '24h');
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} 24h reminders.");
    }

    private function sendFinalReminders(): void
    {
        $now = Carbon::now();

        $appointments = Appointment::with(['master', 'service', 'client'])
            ->where('status', AppointmentStatus::Booked)
            ->whereNull('reminder_final_sent_at')
            ->where('start_time', '<=', $now->copy()->addHours(3))
            ->get();

        $dispatched = 0;

        foreach ($appointments as $appointment) {
            if (! $appointment->client) {
                continue;
            }

            if (! $appointment->client->telegram_id && ! $appointment->client->max_id) {
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

            $appointment->update(['reminder_final_sent_at' => $now]);
            SendAppointmentReminderJob::dispatch($appointment, 'final');
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} final reminders.");
    }
}
