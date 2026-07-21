<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tariff_plans', function (Blueprint $table) {
            $table->unsignedInteger('max_appointments_per_month')->nullable()->after('price_monthly');
            $table->unsignedInteger('max_masters')->nullable()->default(1)->after('max_appointments_per_month');
        });
    }

    public function down(): void
    {
        Schema::table('tariff_plans', function (Blueprint $table) {
            $table->dropColumn(['max_appointments_per_month', 'max_masters']);
        });
    }
};
