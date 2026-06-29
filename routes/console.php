<?php

use App\Jobs\SendAppointmentReminderJob;
use App\Models\Appointment;
use App\Enums\AppointmentStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('appointments:reminders')->everyMinute();
Schedule::command('subscriptions:check-expirations')->dailyAt('00:00');
