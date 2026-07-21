<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserChannelsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public User $user) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->user->id)];
    }

    public function broadcastAs(): string
    {
        return 'UserChannelsUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->user->id,
        ];
    }
}
