<?php

namespace App\Events;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AppointmentStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Appointment $appointment,
        public AppointmentStatus $oldStatus,
        public AppointmentStatus $newStatus,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->appointment->master_id)];
    }

    public function broadcastAs(): string
    {
        return 'AppointmentStatusChanged';
    }

    public function broadcastWith(): array
    {
        $data = $this->appointment->toCalendarArray();
        $data['old_status'] = $this->oldStatus->value;

        return $data;
    }
}
