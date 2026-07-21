<?php

namespace App\Services\Billing;

use App\Enums\AppointmentStatus;
use App\Models\Workspace;
use Carbon\CarbonInterface;

class TariffLimitService
{

    public function canCreateAppointment(Workspace $workspace): bool
    {
        $limit = $this->getMonthlyLimit($workspace);

        if ($limit === PHP_INT_MAX) {
            return true;
        }

        $cycleStart = $this->getCycleStart($workspace);

        $usedCount = $this->countAppointmentsInCycle($workspace, $cycleStart);

        return $usedCount < $limit;
    }

    public function getRemainingCount(Workspace $workspace): int
    {
        $limit = $this->getMonthlyLimit($workspace);

        if ($limit === PHP_INT_MAX) {
            return PHP_INT_MAX;
        }

        $cycleStart = $this->getCycleStart($workspace);

        $usedCount = $this->countAppointmentsInCycle($workspace, $cycleStart);

        return max(0, $limit - $usedCount);
    }

    public function getMonthlyLimit(Workspace $workspace): int
    {
        $activeSubscription = $workspace->activeSubscription();

        if (! $activeSubscription || ! $activeSubscription->tariffPlan) {
            return 30; // Fallback to 'start' plan limits
        }

        return $activeSubscription->tariffPlan->max_appointments_per_month ?? PHP_INT_MAX;
    }

    public function getUsedCount(Workspace $workspace): int
    {
        $limit = $this->getMonthlyLimit($workspace);

        if ($limit === PHP_INT_MAX) {
            return 0;
        }

        $cycleStart = $this->getCycleStart($workspace);

        return $this->countAppointmentsInCycle($workspace, $cycleStart);
    }

    private function getCycleStart(Workspace $workspace): CarbonInterface
    {
        $registrationDay = $workspace->created_at->day;

        $cycleStart = now()->day >= $registrationDay
            ? now()->startOfMonth()->addDays($registrationDay - 1)
            : now()->subMonth()->startOfMonth()->addDays($registrationDay - 1);

        return $cycleStart->startOfDay();
    }

    private function countAppointmentsInCycle(Workspace $workspace, CarbonInterface $cycleStart): int
    {
        $masterIds = $workspace->users()->pluck('id');

        return \App\Models\Appointment::whereIn('master_id', $masterIds)
            ->whereNotIn('status', [
                AppointmentStatus::Cancelled,
            ])
            ->where('start_time', '>=', $cycleStart)
            ->count();
    }
}
