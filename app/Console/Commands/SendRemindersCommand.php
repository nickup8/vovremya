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
            ->where('reminder_24h_sent', false)
            ->whereBetween('start_time', [
                $now->copy()->addHours(23),
                $now->copy()->addHours(25),
            ])
            ->get();

        foreach ($appointments as $appointment) {
            if ($appointment->client && ($appointment->client->telegram_id || $appointment->client->max_id)) {
                SendAppointmentReminderJob::dispatch($appointment, '24h');
            }
        }

        $this->info("Dispatched {$appointments->count()} 24h reminders.");
    }

    private function sendFinalReminders(): void
    {
        $now = Carbon::now();

        $appointments = Appointment::with(['master', 'service', 'client'])
            ->where('status', AppointmentStatus::Booked)
            ->where('reminder_final_sent', false)
            ->whereBetween('start_time', [
                $now->copy()->addHours(1),
                $now->copy()->addHours(3),
            ])
            ->get();

        foreach ($appointments as $appointment) {
            if ($appointment->client && ($appointment->client->telegram_id || $appointment->client->max_id)) {
                SendAppointmentReminderJob::dispatch($appointment, 'final');
            }
        }

        $this->info("Dispatched {$appointments->count()} final reminders.");
    }
}
