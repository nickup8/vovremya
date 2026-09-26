<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tariff_plans')
            ->whereIn('code', ['studio', 'salon'])
            ->update(['is_active' => false]);
    }

    /**
     * Legacy plans intentionally remain disabled because automatic
     * reactivation could return discontinued products to checkout.
     */
    public function down(): void
    {
        //
    }
};
