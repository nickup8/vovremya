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

// Восстановление outbox-событий доставки чека: коммит прошёл, а постановка
// в очередь упала/не случилась (dispatched_at IS NULL) — не потерять намерение.
Schedule::command('receipts:recover-delivery-events')->everyFiveMinutes()->withoutOverlapping();

// То же для клиентских уведомлений о записи (создание/отмена/перенос/серии).
Schedule::command('appointments:recover-notification-events')->everyFiveMinutes()->withoutOverlapping();

// Резервные копии БД: 03:30 Europe/Moscow; включение — BACKUP_SCHEDULED_ENABLED
// (сама команда выходит без действий, если флаг выключен).
Schedule::command('backups:schedule-daily')
    ->dailyAt((string) config('backups.scheduled.time', '03:30'))
    ->timezone((string) config('backups.scheduled.timezone', 'Europe/Moscow'))
    ->withoutOverlapping();
// Восстановление активных состояний (stale running, потерянные queued)
// идёт всегда — не зависит от BACKUP_SCHEDULED_ENABLED.
Schedule::command('backups:recover')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('backups:cleanup-tmp')->hourly()->withoutOverlapping();
