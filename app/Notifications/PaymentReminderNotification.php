<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PaymentReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly int $days,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $dayText = $this->days === 3 ? '3 дня' : '5 дней';

        return [
            'kind' => 'payment_reminder',
            'title' => 'Скоро закончится тариф',
            'body' => "До окончания тарифа осталось {$dayText}.",
        ];
    }
}
