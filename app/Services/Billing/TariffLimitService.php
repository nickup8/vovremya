<?php

namespace App\Services\Billing;

use App\Enums\AppointmentStatus;
use App\Models\User;
use Illuminate\Support\Carbon;

class TariffLimitService
{

    public function canCreateAppointment(User $master): bool
    {
        if (! $master->isFreeTariff()) {
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

        return $usedCount < config('booking.free_monthly_limit');
    }

    public function getRemainingCount(User $master): int
    {
        if (! $master->isFreeTariff()) {
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

        return max(0, config('booking.free_monthly_limit') - $usedCount);
    }

    public function getMonthlyLimit(User $master): int
    {
        return match ($master->tariff) {
            'free' => config('booking.free_monthly_limit'),
            'pro' => PHP_INT_MAX,
            'studio' => PHP_INT_MAX,
            default => config('booking.free_monthly_limit'),
        };
    }
}
