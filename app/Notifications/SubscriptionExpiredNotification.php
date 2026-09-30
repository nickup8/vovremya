<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SubscriptionExpiredNotification extends Notification
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'subscription_expired',
            'title' => 'Подписка истекла',
            'body' => 'Ваша подписка истекла. Вы переведены на бесплатный тариф. Обновите подписку для продолжения работы.',
        ];
    }
}
