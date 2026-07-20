<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    Log::info('Channel auth attempt', [
        'user_authenticated' => $user !== null,
        'user_id' => $user?->id,
        'channel_id' => $id,
        'match' => (string) ($user?->id ?? '') === (string) $id,
    ]);

    return (string) $user->id === (string) $id;
});
