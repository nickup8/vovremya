<?php

namespace App\Events;

use App\Models\Appointment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AppointmentCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Appointment $appointment) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->appointment->master_id)];
    }

    public function broadcastAs(): string
    {
        return 'AppointmentCreated';
    }

    public function broadcastWith(): array
    {
        return $this->appointment->toCalendarArray();
    }
}
