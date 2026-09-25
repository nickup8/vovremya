<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\DatabaseNotification;

class Notification extends DatabaseNotification
{
    public function systemMessage(): BelongsTo
    {
        return $this->belongsTo(SystemNotificationMessage::class, 'system_message_id');
    }
}
