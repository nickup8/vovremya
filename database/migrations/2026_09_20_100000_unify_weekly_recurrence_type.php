<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A. Convert custom_weekly → weekly (weekdays already stored)
        DB::table('recurring_blocked_time_series')
            ->where('recurrence_type', 'custom_weekly')
            ->update(['recurrence_type' => 'weekly']);

        // B. Fill weekdays for legacy weekly where null:
        //    derive single ISO weekday from start_date
        $legacyWeekly = DB::table('recurring_blocked_time_series')
            ->where('recurrence_type', 'weekly')
            ->whereNull('weekdays')
            ->get();

        foreach ($legacyWeekly as $series) {
            $dayOfWeekIso = (int) date('N', strtotime($series->start_date));
            DB::table('recurring_blocked_time_series')
                ->where('id', $series->id)
                ->update(['weekdays' => json_encode([$dayOfWeekIso])]);
        }
    }

    public function down(): void
    {
        // No safe down-migration (we can't distinguish old custom_weekly from weekly)
    }
};
