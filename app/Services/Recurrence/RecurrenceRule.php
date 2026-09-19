<?php

namespace App\Services\Recurrence;

use App\Enums\RecurrenceType;
use Carbon\Carbon;

readonly class RecurrenceRule
{
    public function __construct(
        public RecurrenceType $recurrenceType,
        public int $interval,
        public ?array $weekdays,
        public Carbon $startDate,
        public ?Carbon $endsAt,
        public string $timezone,
    ) {}

    /**
     * Create from an array (e.g., validated request data or model attributes).
     *
     * @param  array{recurrence_type: string, interval: int, weekdays?: array|null, start_date: string, ends_at?: string|null, timezone: string}  $data
     */
    public static function fromArray(array $data): self
    {
        $tz = $data['timezone'] ?? 'UTC';

        return new self(
            recurrenceType: RecurrenceType::from($data['recurrence_type']),
            interval: max(1, (int) ($data['interval'] ?? 1)),
            weekdays: $data['weekdays'] ?? null,
            startDate: Carbon::parse($data['start_date'], $tz)->startOfDay(),
            endsAt: isset($data['ends_at']) ? Carbon::parse($data['ends_at'], $tz)->startOfDay() : null,
            timezone: $tz,
        );
    }
}
