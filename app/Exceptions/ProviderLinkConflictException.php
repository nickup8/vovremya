<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Существующая привязка мессенджера принадлежит другой карточке.
 * Чужие идентификаторы в сообщение не раскрываются.
 */
class ProviderLinkConflictException extends RuntimeException
{
    public static function forProvider(string $provider): self
    {
        $labels = [
            'telegram' => 'Telegram',
            'max' => 'MAX',
            'vk' => 'VK',
        ];

        $label = $labels[$provider] ?? strtoupper($provider);

        return new self("Этот аккаунт {$label} уже привязан к другому профилю. Обратитесь к мастеру.");
    }
}
