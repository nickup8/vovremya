<?php

namespace App\Services\Billing;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Subscription;
use App\Models\Workspace;
use Carbon\Carbon;
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

    public function getCycleStart(?Workspace $workspace): CarbonInterface
    {
        if (! $workspace || ! $workspace->created_at) {
            return now()->startOfMonth()->startOfDay();
        }

        $anchorDay = (int) $workspace->created_at->day;
        $now = now();

        $currentDay = min($anchorDay, $now->daysInMonth);
        $candidate = Carbon::create($now->year, $now->month, $currentDay, 0, 0, 0);

        if ($candidate <= $now) {
            return $candidate->startOfDay();
        }

        $prevMonth = $now->copy()->subMonthNoOverflow();
        $prevDay = min($anchorDay, $prevMonth->daysInMonth);
        $candidate = Carbon::create($prevMonth->year, $prevMonth->month, $prevDay, 0, 0, 0);

        return $candidate->startOfDay();
    }

    private function countAppointmentsInCycle(?Workspace $workspace, CarbonInterface $cycleStart): int
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
            ->where('start_time', '>=', $cycleStart)
            ->count();
    }
}
