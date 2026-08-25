<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $pro = DB::table('tariff_plans')->where('code', 'pro')->first();

        if (! $pro) {
            return;
        }

        $features = is_string($pro->features) ? json_decode($pro->features, true) : $pro->features;

        if (! is_array($features)) {
            $features = [];
        }

        if (in_array('client_reactivation', $features, true)) {
            return;
        }

        $features[] = 'client_reactivation';

        DB::table('tariff_plans')
            ->where('code', 'pro')
            ->update(['features' => json_encode($features)]);
    }

    public function down(): void
    {
        $pro = DB::table('tariff_plans')->where('code', 'pro')->first();

        if (! $pro) {
            return;
        }

        $features = is_string($pro->features) ? json_decode($pro->features, true) : $pro->features;

        if (! is_array($features)) {
            return;
        }

        $features = array_values(array_filter($features, fn (string $f): bool => $f !== 'client_reactivation'));

        DB::table('tariff_plans')
            ->where('code', 'pro')
            ->update(['features' => json_encode($features)]);
    }
};
