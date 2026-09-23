<?php

namespace App\Services\Billing;

use App\Models\Subscription;
use App\Models\Workspace;
use App\Support\PlanDefaults;

class PlanAccessService
{
    public function currentPlanCode(Workspace $workspace): string
    {
        $active = $workspace->activeSubscription();

        if (! $active || ! $active->tariffPlan) {
            return 'start';
        }

        return $active->tariffPlan->code;
    }

    public function hasPro(Workspace $workspace): bool
    {
        return $this->currentPlanCode($workspace) === 'pro';
    }

    public function hasFeature(Workspace $workspace, string $feature): bool
    {
        return $workspace->hasFeature($feature);
    }

    public function getMonthlyLimit(Workspace $workspace): ?int
    {
        $active = $workspace->activeSubscription();

        if (! $active || ! $active->tariffPlan) {
            return PlanDefaults::START_MAX_APPOINTMENTS;
        }

        return $active->tariffPlan->max_appointments_per_month;
    }

    public function getMaxMasters(Workspace $workspace): int
    {
        return $workspace->maxMasters();
    }
}
