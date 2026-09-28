<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('appointments:reminders')->everyMinute()->withoutOverlapping();
Schedule::command('appointments:cancel-unpaid')->everyMinute()->withoutOverlapping();
Schedule::command('appointments:cleanup-drafts')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('subscriptions:check-expirations')->dailyAt('00:00');
Schedule::command('subscriptions:check-limits')->hourly()->withoutOverlapping();
Schedule::command('subscriptions:cleanup-pending')->hourly()->withoutOverlapping();
Schedule::command('billing:reconcile-payment-attempts')->everyMinute()->withoutOverlapping();
Schedule::command('publications:cleanup-expired')->dailyAt('03:00')->withoutOverlapping();
