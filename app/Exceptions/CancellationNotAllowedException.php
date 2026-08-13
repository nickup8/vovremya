<?php

namespace App\Exceptions;

class CancellationNotAllowedException extends \DomainException
{
    public function __construct(
        private string $reason,
        private ?int $deadlineHours = null,
    ) {
        parent::__construct("Cancellation not allowed: {$reason}");
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getDeadlineHours(): ?int
    {
        return $this->deadlineHours;
    }
}
