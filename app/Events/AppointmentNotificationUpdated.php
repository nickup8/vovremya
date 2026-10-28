<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Терминальный исход доставки клиентского уведомления о записи
 * (delivered / failed / no_channel / superseded): календарь обновляет
 * сводку «Уведомления клиенту» без ручного обновления страницы.
 */
class AppointmentNotificationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $appointmentId,
        public string $masterId,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->masterId)];
    }

    public function broadcastAs(): string
    {
        return 'AppointmentNotificationUpdated';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return ['appointment_id' => $this->appointmentId];
    }
}
