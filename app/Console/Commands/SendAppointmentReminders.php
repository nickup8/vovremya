<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Jobs\SendAppointmentReminderJob;
use App\Models\Appointment;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:reminders';

    protected $description = 'Send reminders for upcoming appointments';

    public function handle(): int
    {
        $this->send24hReminders();
        $this->sendFinalReminders();

        return self::SUCCESS;
    }

    private function send24hReminders(): void
    {
        $windowStart = Carbon::now()->addHours(23);
        $windowEnd = Carbon::now()->addHours(25);

        $appointments = Appointment::with(['master', 'service', 'client'])
            ->where('status', AppointmentStatus::Confirmed)
            ->where('reminder_24h_sent', false)
            ->whereBetween('start_time', [$windowStart, $windowEnd])
            ->get();

        foreach ($appointments as $appointment) {
            SendAppointmentReminderJob::dispatch($appointment, '24h');
        }

        $this->info("Dispatched {$appointments->count()} 24h reminders.");
    }

    private function sendFinalReminders(): void
    {
        $windowStart = Carbon::now()->addHours(1);
        $windowEnd = Carbon::now()->addHours(3);

        $appointments = Appointment::with(['master', 'service', 'client'])
            ->where('status', AppointmentStatus::Confirmed)
            ->where('reminder_final_sent', false)
            ->whereBetween('start_time', [$windowStart, $windowEnd])
            ->get();

        foreach ($appointments as $appointment) {
            SendAppointmentReminderJob::dispatch($appointment, 'final');
        }

        $this->info("Dispatched {$appointments->count()} final reminders.");
    }
}
