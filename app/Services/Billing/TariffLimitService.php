<?php

namespace App\Services\Billing;

use App\Enums\AppointmentStatus;
use App\Models\User;
use Illuminate\Support\Carbon;

class TariffLimitService
{

    public function canCreateAppointment(User $master): bool
    {
        $limit = $this->getMonthlyLimit($master);

        if ($limit === PHP_INT_MAX) {
            return true;
        }

        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();

        $usedCount = $master->masterAppointments()
            ->whereIn('status', [
                AppointmentStatus::Booked,
                AppointmentStatus::PendingPayment,
                AppointmentStatus::Prepaid,
                AppointmentStatus::Paid,
            ])
            ->whereBetween('start_time', [$monthStart, $monthEnd])
            ->count();

        return $usedCount < $limit;
    }

    public function getRemainingCount(User $master): int
    {
        $limit = $this->getMonthlyLimit($master);

        if ($limit === PHP_INT_MAX) {
            return PHP_INT_MAX;
        }

        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();

        $usedCount = $master->masterAppointments()
            ->whereIn('status', [
                AppointmentStatus::Booked,
                AppointmentStatus::PendingPayment,
                AppointmentStatus::Prepaid,
                AppointmentStatus::Paid,
            ])
            ->whereBetween('start_time', [$monthStart, $monthEnd])
            ->count();

        return max(0, $limit - $usedCount);
    }

    public function getMonthlyLimit(User $master): int
    {
        $activeSubscription = $master->workspace?->activeSubscription();

        if (! $activeSubscription || ! $activeSubscription->tariffPlan) {
            // Fallback to 'start' plan limits
            return 30;
        }

        return $activeSubscription->tariffPlan->max_appointments_per_month ?? PHP_INT_MAX;
    }
}
