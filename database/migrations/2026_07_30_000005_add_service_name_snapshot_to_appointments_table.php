<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('service_name')->nullable()->after('duration');
        });

        DB::statement('
            UPDATE appointments SET service_name = (
                SELECT s.title FROM services s WHERE s.id = appointments.service_id
            ) WHERE service_id IS NOT NULL AND service_name IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('service_name');
        });
    }
};
