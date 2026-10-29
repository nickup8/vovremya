<?php

namespace App\Console\Commands;

use App\Events\AppointmentNotificationUpdated;
use App\Jobs\SendAppointmentNotificationJob;
use App\Models\Appointment;
use App\Models\AppointmentNotificationDelivery;
use App\Models\AppointmentNotificationEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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
 *
 * Дополнительно: терминализирует осиротевшие pending-строки доставки —
 * claim_token остался, lease истёк, активного преемника в очереди нет
 * (окончательный timeout убил исполнителя до release). Истечение lease
 * само по себе статус не меняет, поэтому без этого sweep строка висела
 * бы в pending вечно, а Summary показывало «Ожидает отправки». После
 * терминализации рассылается AppointmentNotificationUpdated, чтобы UI
 * обновил сводку без ручного обновления страницы.
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

        $orphans = $this->failOrphanedPendingDeliveries();

        $this->info(sprintf(
            'Восстановление уведомлений: %d поставлено в очередь, %d ошибок (найдено %d необработанных); осиротевших claim\'ов терминализировано: %d.',
            $dispatched,
            $failed,
            $events->count(),
            $orphans,
        ));

        return self::SUCCESS;
    }

    /**
     * Pending-строки с истёкшим lease и без активного преемника в очереди:
     * владение осиротело (executor умер по timeout до release) —
     * терминализируем, чтобы строка не висела в pending вечно, и
     * сообщаем UI обновлением сводки.
     */
    private function failOrphanedPendingDeliveries(): int
    {
        $rows = AppointmentNotificationDelivery::query()
            ->where('status', AppointmentNotificationDelivery::STATUS_PENDING)
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', now())
            ->get();

        $failedCount = 0;

        foreach ($rows as $row) {
            // Активный преемник в очереди — его владение не трогаем.
            if ($this->hasActiveJobFor((string) $row->appointment_notification_event_id)) {
                continue;
            }

            // CAS: lease мог обновиться между чтением и UPDATE.
            $updated = AppointmentNotificationDelivery::query()
                ->whereKey($row->getKey())
                ->where('status', AppointmentNotificationDelivery::STATUS_PENDING)
                ->where('claim_token', (string) $row->claim_token)
                ->where('lease_expires_at', '<', now())
                ->update([
                    'status' => AppointmentNotificationDelivery::STATUS_FAILED,
                    'claim_token' => null,
                    'lease_expires_at' => null,
                    'failure_reason' => 'orphaned_claim',
                    'failed_at' => now(),
                ]);

            if ($updated === 1) {
                $failedCount++;
                $this->broadcastUpdated($row->appointment_notification_event_id);
            }
        }

        return $failedCount;
    }

    /**
     * Терминальный исход зафиксирован — сообщаем календарю, чтобы сводка
     * «Уведомления клиенту» обновилась без ручного обновления страницы.
     */
    private function broadcastUpdated(int|string $eventId): void
    {
        try {
            $event = AppointmentNotificationEvent::find($eventId);

            if ($event === null) {
                return;
            }

            $appointment = Appointment::find($event->appointment_id);

            if ($appointment === null) {
                return;
            }

            broadcast(new AppointmentNotificationUpdated(
                (string) $event->appointment_id,
                (string) $appointment->master_id,
            ));
        } catch (\Throwable $e) {
            Log::warning('AppointmentNotificationUpdated broadcast failed', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function hasActiveJobFor(string $eventId): bool
    {
        return DB::table('jobs')
            ->where('payload', 'like', '%'.addslashes($eventId).'%')
            ->exists();
    }
}
