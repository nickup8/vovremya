<?php

namespace App\Services\Billing;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Support\PlanDefaults;
use Carbon\CarbonInterface;

class TariffLimitService
{
    public function canCreateAppointment(Workspace $workspace, ?Subscription $subscription = null, ?CarbonInterface $forDate = null): bool
    {
        $limit = $this->getMonthlyLimit($workspace, $subscription);

        if ($limit === PHP_INT_MAX) {
            return true;
        }

        $cycleStart = $this->getCycleStart($workspace, $forDate);
        $cycleEnd = $this->getCycleEnd($workspace, $forDate);

        $usedCount = $this->countAppointmentsInCycle($workspace, $cycleStart, $cycleEnd);

        return $usedCount < $limit;
    }

    public function getRemainingCount(Workspace $workspace, ?Subscription $subscription = null): int
    {
        $limit = $this->getMonthlyLimit($workspace, $subscription);

        if ($limit === PHP_INT_MAX) {
            return PHP_INT_MAX;
        }

        $cycleStart = $this->getCycleStart($workspace);
        $cycleEnd = $this->getCycleEnd($workspace);

        $usedCount = $this->countAppointmentsInCycle($workspace, $cycleStart, $cycleEnd);

        return max(0, $limit - $usedCount);
    }

    public function getMonthlyLimit(Workspace $workspace, ?Subscription $subscription = null): int
    {
        $activeSubscription = $subscription ?? $workspace->activeSubscription();

        if (! $activeSubscription || ! $activeSubscription->tariffPlan) {
            return PlanDefaults::START_MAX_APPOINTMENTS;
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
        $cycleEnd = $this->getCycleEnd($workspace);

        return $this->countAppointmentsInCycle($workspace, $cycleStart, $cycleEnd);
    }

    public function getCycleStart(?Workspace $workspace, ?CarbonInterface $forDate = null): CarbonInterface
    {
        $tz = $workspace?->settings['timezone'] ?? 'Europe/Moscow';
        $base = $forDate ? $forDate->copy()->setTimezone($tz) : now($tz);

        return $base->startOfMonth();
    }

    public function getCycleEnd(?Workspace $workspace, ?CarbonInterface $forDate = null): CarbonInterface
    {
        $tz = $workspace?->settings['timezone'] ?? 'Europe/Moscow';
        $base = $forDate ? $forDate->copy()->setTimezone($tz) : now($tz);

        return $base->endOfMonth();
    }

    private function countAppointmentsInCycle(?Workspace $workspace, CarbonInterface $cycleStart, CarbonInterface $cycleEnd): int
    {
        if (! $workspace) {
            return 0;
        }

        $masterIds = $workspace->users()->pluck('id');

        return Appointment::whereIn('master_id', $masterIds)
            ->whereIn('status', [
                AppointmentStatus::Booked,
                AppointmentStatus::PendingPayment,
                AppointmentStatus::Prepaid,
                AppointmentStatus::Paid,
                AppointmentStatus::NoShow,
            ])
            ->whereBetween('start_time', [$cycleStart, $cycleEnd])
            ->count();
    }
}
