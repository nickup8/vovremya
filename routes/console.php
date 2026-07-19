<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('appointments:reminders')->everyMinute()->withoutOverlapping();
Schedule::command('appointments:cancel-unpaid')->everyMinute();
Schedule::command('appointments:cleanup-drafts')->everyFiveMinutes();
Schedule::command('subscriptions:check-expirations')->dailyAt('00:00');
