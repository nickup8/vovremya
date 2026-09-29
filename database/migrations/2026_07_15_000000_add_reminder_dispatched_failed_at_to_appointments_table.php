<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->timestamp('reminder_24h_dispatched_at')->nullable()->after('reminder_24h_sent_at');
            $table->timestamp('reminder_24h_failed_at')->nullable()->after('reminder_24h_dispatched_at');
            $table->timestamp('reminder_final_dispatched_at')->nullable()->after('reminder_final_sent_at');
            $table->timestamp('reminder_final_failed_at')->nullable()->after('reminder_final_dispatched_at');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn([
                'reminder_24h_dispatched_at',
                'reminder_24h_failed_at',
                'reminder_final_dispatched_at',
                'reminder_final_failed_at',
            ]);
        });
    }
};
