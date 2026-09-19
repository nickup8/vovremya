<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignUuid('recurring_series_id')
                ->nullable()
                ->constrained('recurring_appointment_series')
                ->nullOnDelete();
            $table->date('recurring_occurrence_date')->nullable();

            $table->unique(['recurring_series_id', 'recurring_occurrence_date'], 'appointments_series_occurrence_unique');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropUnique('appointments_series_occurrence_unique');
            $table->dropColumn(['recurring_series_id', 'recurring_occurrence_date']);
        });
    }
};
