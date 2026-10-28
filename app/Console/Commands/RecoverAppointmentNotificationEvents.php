<?php

namespace App\Console\Commands;

use App\Jobs\SendAppointmentNotificationJob;
use App\Models\AppointmentNotificationEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Recovery для outbox-событий клиентских уведомлений о записи.
 *
 * record() пишет намерение в той же транзакции, что и изменение, и ставит
 * job после commit. Если постановка не случилась (упал queue, умер процесс),
 * dispatched_at остаётся NULL — эта команда переставляет job. Строки с
 * dispatched_at уже принадлежат очереди и её ограниченным ретраям; команда
 * их не трогает. Повторная постановка безопасна: delivery-claim и версия
 * события не дают отправить дважды.
 */
class RecoverAppointmentNotificationEvents extends Command
{
    protected $signature = 'appointments:recover-notification-events
        {--min-age=2 : Minutes an event may sit undispatched before recovery touches it}';

    protected $description = 'Поставить в очередь события клиентских уведомлений, не отправленные после коммита';

    public function handle(): int
    {
        $events = AppointmentNotificationEvent::query()
            ->whereNull('dispatched_at')
            ->where('created_at', '<=', now()->subMinutes(max(0, (int) $this->option('min-age'))))
            ->orderBy('created_at')
            ->get();

        $dispatched = 0;
        $failed = 0;

        foreach ($events as $event) {
            try {
                SendAppointmentNotificationJob::dispatch($event->getKey());
                $event->update(['dispatched_at' => now()]);
                $dispatched++;
            } catch (\Throwable $e) {
                $failed++;
                Log::error('Appointment notification event recovery failed to enqueue', [
                    'event_id' => $event->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info(sprintf(
            'Восстановление уведомлений: %d поставлено в очередь, %d ошибок (найдено %d необработанных).',
            $dispatched,
            $failed,
            $events->count(),
        ));

        return self::SUCCESS;
    }
}
