<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Start: unlimited appointments
        DB::table('tariff_plans')
            ->where('code', 'start')
            ->update(['max_appointments_per_month' => null]);

        // Pro: add free_windows feature, remove recurring_blocked_times (now base feature)
        $proPlan = DB::table('tariff_plans')->where('code', 'pro')->first();

        if ($proPlan) {
            $features = is_array($proPlan->features) ? $proPlan->features : json_decode($proPlan->features, true) ?? [];

            // Remove recurring_blocked_times (now available to all plans)
            $features = array_values(array_diff($features, ['recurring_blocked_times']));

            // Add free_windows if not already present
            if (! in_array('free_windows', $features, true)) {
                $features[] = 'free_windows';
            }

            DB::table('tariff_plans')
                ->where('code', 'pro')
                ->update(['features' => $features]);
        }
    }

    public function down(): void
    {
        // Revert Start to 30 appointments/month
        DB::table('tariff_plans')
            ->where('code', 'start')
            ->update(['max_appointments_per_month' => 30]);

        // Revert Pro features
        $proPlan = DB::table('tariff_plans')->where('code', 'pro')->first();

        if ($proPlan) {
            $features = is_array($proPlan->features) ? $proPlan->features : json_decode($proPlan->features, true) ?? [];

            // Restore recurring_blocked_times
            if (! in_array('recurring_blocked_times', $features, true)) {
                $features[] = 'recurring_blocked_times';
            }

            // Remove free_windows
            $features = array_values(array_diff($features, ['free_windows']));

            DB::table('tariff_plans')
                ->where('code', 'pro')
                ->update(['features' => $features]);
        }
    }
};
