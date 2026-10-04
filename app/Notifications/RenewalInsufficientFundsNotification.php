<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class RenewalInsufficientFundsNotification extends Notification
{
    use Queueable;

    public const TEXT = 'Не удалось продлить Профи: на карте недостаточно средств. Автопродление остановлено. Пополните карту и продлите Профи в разделе „Тарифы и оплата“.';

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
            'kind' => 'renewal_insufficient_funds',
            'title' => 'Продление Профи не удалось',
            'body' => self::TEXT,
        ];
    }
}
