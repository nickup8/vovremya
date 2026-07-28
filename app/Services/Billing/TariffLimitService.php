<?php

namespace App\Services\Billing;

use App\Enums\AppointmentStatus;
use App\Models\Subscription;
use App\Models\Workspace;
use Carbon\CarbonInterface;

class TariffLimitService
{

    public function canCreateAppointment(Workspace $workspace, ?Subscription $subscription = null): bool
    {
        $limit = $this->getMonthlyLimit($workspace, $subscription);

        if ($limit === PHP_INT_MAX) {
            return true;
        }

        $cycleStart = $this->getCycleStart($workspace);

        $usedCount = $this->countAppointmentsInCycle($workspace, $cycleStart);

        return $usedCount < $limit;
    }

    public function getRemainingCount(Workspace $workspace, ?Subscription $subscription = null): int
    {
        $limit = $this->getMonthlyLimit($workspace, $subscription);

        if ($limit === PHP_INT_MAX) {
            return PHP_INT_MAX;
        }

        $cycleStart = $this->getCycleStart($workspace);

        $usedCount = $this->countAppointmentsInCycle($workspace, $cycleStart);

        return max(0, $limit - $usedCount);
    }

    public function getMonthlyLimit(Workspace $workspace, ?Subscription $subscription = null): int
    {
        $activeSubscription = $subscription ?? $workspace->activeSubscription();

        if (! $activeSubscription || ! $activeSubscription->tariffPlan) {
            return 30; // Fallback to 'start' plan limits
        }

        return $activeSubscription->tariffPlan->max_appointments_per_month ?? PHP_INT_MAX;
    }

    public function getUsedCount(Workspace $workspace, ?Subscription $subscription = null): int
    {
        $limit = $this->getMonthlyLimit($workspace, $subscription);

        if ($limit === PHP_INT_MAX) {
            return 0;
        }

        $cycleStart = $this->getCycleStart($workspace);

        return $this->countAppointmentsInCycle($workspace, $cycleStart);
    }

    private function getCycleStart(?Workspace $workspace): CarbonInterface
    {
        return now()->subDays(30)->startOfDay();
    }

    private function countAppointmentsInCycle(?Workspace $workspace, CarbonInterface $cycleStart): int
    {
        if (! $workspace) {
            return 0;
        }

        $masterIds = $workspace->users()->pluck('id');

        return \App\Models\Appointment::whereIn('master_id', $masterIds)
            ->whereIn('status', [
                AppointmentStatus::Booked,
                AppointmentStatus::PendingPayment,
                AppointmentStatus::Prepaid,
                AppointmentStatus::Paid,
                AppointmentStatus::NoShow,
            ])
            ->where('start_time', '>=', $cycleStart)
            ->count();
    }
}
