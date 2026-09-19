<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add recurring_appointments to existing Pro tariff plans
        $proPlans = DB::table('tariff_plans')->where('code', 'pro')->get();

        foreach ($proPlans as $plan) {
            $features = json_decode($plan->features, true) ?? [];

            if (! in_array('recurring_appointments', $features, true)) {
                $features[] = 'recurring_appointments';
                DB::table('tariff_plans')
                    ->where('id', $plan->id)
                    ->update(['features' => json_encode($features)]);
            }
        }
    }

    public function down(): void
    {
        $proPlans = DB::table('tariff_plans')->where('code', 'pro')->get();

        foreach ($proPlans as $plan) {
            $features = json_decode($plan->features, true) ?? [];
            $features = array_values(array_filter($features, fn ($f) => $f !== 'recurring_appointments'));

            DB::table('tariff_plans')
                ->where('id', $plan->id)
                ->update(['features' => json_encode($features)]);
        }
    }
};
