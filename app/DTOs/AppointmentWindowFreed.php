<?php

namespace App\DTOs;

use App\Enums\SlotOpportunitySourceType;
use DateTimeInterface;

readonly class AppointmentWindowFreed
{
    public function __construct(
        public string $originEventId,
        public ?string $chainId,
        public string $workspaceId,
        public string $masterId,
        public string $masterServiceId,
        public ?string $sourceAppointmentId,
        public SlotOpportunitySourceType $sourceType,
        public DateTimeInterface $startTime,
        public int $duration,
    ) {}
}
