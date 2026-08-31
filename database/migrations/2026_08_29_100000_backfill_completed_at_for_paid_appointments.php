<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill completed_at для исторических Paid appointments.
 *
 * Записи со status='paid' и completed_at=NULL получают completed_at=start_time.
 * Это историческая approximation — точного момента перехода в Paid нет.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('appointments')
            ->where('status', 'paid')
            ->whereNull('completed_at')
            ->whereNotNull('start_time')
            ->update(['completed_at' => DB::raw('start_time')]);
    }

    /**
     * Обратный no-op: backfill необратимый, completed_at не обнуляем.
     */
    public function down(): void
    {
        // no-op — historical data backfill, not reversible.
    }
};
