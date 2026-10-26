<?php

namespace App\Notifications;

use App\Notifications\Channels\SystemNotificationDatabaseChannel;
use App\Services\Payment\NpdReceiptUrlValidator;
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
        private readonly ?string $actionUrl = null,
        private readonly ?string $actionLabel = null,
        private readonly ?string $receiptId = null,
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
        $data = [
            'kind' => 'system',
            'title' => $this->title,
            'body' => $this->body,
        ];

        // Structured action, never HTML in body. The URL is guarded here as
        // well: only the FNS receipt host may ever reach the frontend props.
        if ($this->actionUrl !== null
            && $this->actionUrl !== ''
            && $this->actionLabel !== null
            && $this->actionLabel !== ''
            && self::isAllowedActionUrl($this->actionUrl)) {
            $data['action'] = [
                'url' => $this->actionUrl,
                'label' => $this->actionLabel,
            ];
        }

        // Deduplication key for the backfill command — receipt-scoped, not
        // title-scoped: titles are shared by design across notifications.
        if ($this->receiptId !== null && $this->receiptId !== '') {
            $data['receipt_id'] = $this->receiptId;
        }

        return $data;
    }

    /**
     * Last-line guard before a URL becomes an <a href>: https + exact NPD
     * host only. The full NpdReceiptUrlValidator (seller INN, path shape)
     * runs at attach time and again in the Inertia middleware.
     */
    public static function isAllowedActionUrl(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return false;
        }

        if (strtolower($parts['host']) !== NpdReceiptUrlValidator::HOST) {
            return false;
        }

        return ! array_intersect_key($parts, array_flip(['user', 'pass', 'port', 'query', 'fragment']));
    }
}
