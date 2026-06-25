<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('working_hours', function (Blueprint $table) {
            $table->time('break_start_time')->nullable()->after('end_time');
            $table->time('break_end_time')->nullable()->after('break_start_time');
        });

        DB::table('working_hours')
            ->where('is_working', true)
            ->whereNull('break_start_time')
            ->whereNull('break_end_time')
            ->update([
                'break_start_time' => '13:00',
                'break_end_time' => '14:00',
            ]);
    }

    public function down(): void
    {
        Schema::table('working_hours', function (Blueprint $table) {
            $table->dropColumn(['break_start_time', 'break_end_time']);
        });
    }
};
