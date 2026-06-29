<?php

namespace App\Services\Billing;

use App\Enums\AppointmentStatus;
use App\Models\User;
use Illuminate\Support\Carbon;

class TariffLimitService
{
    private const FREE_MONTHLY_LIMIT = 30;

    public function canCreateAppointment(User $master): bool
    {
        if (! $master->isFreeTariff()) {
            return true;
        }

        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();

        $usedCount = $master->masterAppointments()
            ->whereIn('status', [AppointmentStatus::Confirmed, AppointmentStatus::Completed])
            ->whereBetween('start_time', [$monthStart, $monthEnd])
            ->count();

        return $usedCount < self::FREE_MONTHLY_LIMIT;
    }

    public function getRemainingCount(User $master): int
    {
        if (! $master->isFreeTariff()) {
            return PHP_INT_MAX;
        }

        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();

        $usedCount = $master->masterAppointments()
            ->whereIn('status', [AppointmentStatus::Confirmed, AppointmentStatus::Completed])
            ->whereBetween('start_time', [$monthStart, $monthEnd])
            ->count();

        return max(0, self::FREE_MONTHLY_LIMIT - $usedCount);
    }

    public function getMonthlyLimit(User $master): int
    {
        return match ($master->tariff) {
            'free' => self::FREE_MONTHLY_LIMIT,
            'pro' => PHP_INT_MAX,
            'studio' => PHP_INT_MAX,
            default => self::FREE_MONTHLY_LIMIT,
        };
    }
}
