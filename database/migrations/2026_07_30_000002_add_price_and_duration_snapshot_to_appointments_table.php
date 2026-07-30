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
            $table->decimal('price', 10, 2)->nullable()->after('service_id');
            $table->integer('duration')->nullable()->after('price');
        });

        DB::statement('
            UPDATE appointments SET
                price = (SELECT s.price FROM services s WHERE s.id = appointments.service_id),
                duration = (SELECT s.duration_minutes FROM services s WHERE s.id = appointments.service_id)
            WHERE service_id IS NOT NULL AND (price IS NULL OR duration IS NULL)
        ');
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['price', 'duration']);
        });
    }
};
