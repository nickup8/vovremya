<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slot_opportunities', function (Blueprint $table) {
            $table->timestamp('master_notification_attempted_at')->nullable()->after('invalidation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('slot_opportunities', function (Blueprint $table) {
            $table->dropColumn('master_notification_attempted_at');
        });
    }
};
