<?php

namespace App\Notifications\Channels;

use App\Models\SystemNotificationMessage;
use App\Notifications\SystemNotification;
use Illuminate\Notifications\Notification;

class SystemNotificationDatabaseChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notification instanceof SystemNotification) {
            return;
        }

        $data = $notification->toArray($notifiable);
        $systemMessageId = $notification->getSystemMessageId();

        // Validate that the parent exists and is not soft-deleted
        if ($systemMessageId) {
            $exists = SystemNotificationMessage::withTrashed()->where('id', $systemMessageId)->exists();
            $isDeleted = $exists && SystemNotificationMessage::onlyTrashed()->where('id', $systemMessageId)->exists();
            if (! $exists || $isDeleted) {
                $systemMessageId = null;
            }
        }

        $notifiable->notifications()->create([
            'id' => \Illuminate\Support\Str::uuid(),
            'type' => get_class($notification),
            'data' => $data,
            'system_message_id' => $systemMessageId,
        ]);
    }
}
