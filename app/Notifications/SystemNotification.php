<?php

namespace App\Notifications;

use App\Notifications\Channels\SystemNotificationDatabaseChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SystemNotification extends Notification
{
    use Queueable;

    private ?string $systemMessageId = null;

    public function __construct(
        private readonly string $title,
        private readonly string $body,
        ?string $systemMessageId = null,
    ) {
        $this->systemMessageId = $systemMessageId;
    }

    public function via(object $notifiable): array
    {
        return [SystemNotificationDatabaseChannel::class];
    }

    public function getSystemMessageId(): ?string
    {
        return $this->systemMessageId;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'system',
            'title' => $this->title,
            'body' => $this->body,
        ];
    }
}
