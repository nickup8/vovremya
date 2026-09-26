<?php

namespace App\Services\Billing;

use App\Models\TariffPlan;
use Carbon\CarbonInterface;
use App\Support\PlanDefaults;

final readonly class PlanDescriptor
{
    private function __construct(
        public string $code,
        public string $name,
        public array $features,
        public int $maxMasters,
        public ?int $monthlyLimit,
        public ?CarbonInterface $expiresAt,
    ) {}

    public static function fromTariffPlan(TariffPlan $plan, ?CarbonInterface $expiresAt = null): self
    {
        return new self(
            code: $plan->code,
            name: $plan->name,
            features: $plan->features ?? [],
            maxMasters: $plan->max_masters ?? PHP_INT_MAX,
            monthlyLimit: $plan->max_appointments_per_month,
            expiresAt: $expiresAt,
        );
    }

    public static function start(?CarbonInterface $expiresAt = null): self
    {
        return new self(
            code: 'start',
            name: 'Старт',
            features: PlanDefaults::START_FEATURES,
            maxMasters: PlanDefaults::START_MAX_MASTERS,
            monthlyLimit: PlanDefaults::START_MAX_APPOINTMENTS,
            expiresAt: $expiresAt,
        );
    }
}
