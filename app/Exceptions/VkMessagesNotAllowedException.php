<?php

namespace App\Exceptions;

class VkMessagesNotAllowedException extends \RuntimeException
{
    public function __construct(string $message = 'VK messages not allowed', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
