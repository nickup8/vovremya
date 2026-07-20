<?php

namespace App\Events;

use App\Models\Appointment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AppointmentRescheduled implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Appointment $appointment,
        public string $oldStartTime,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->appointment->master_id)];
    }

    public function broadcastAs(): string
    {
        return 'AppointmentRescheduled';
    }

    public function broadcastWith(): array
    {
        $data = $this->appointment->toCalendarArray();
        $tz = $this->appointment->master->getTimezone();
        $oldTime = \Illuminate\Support\Carbon::parse($this->oldStartTime, 'UTC')->timezone($tz);

        $data['old_date'] = $oldTime->format('Y-m-d');
        $data['old_time'] = $oldTime->format('H:i');

        return $data;
    }
}
