<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_blocked_time_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('series_id')
                ->constrained('recurring_blocked_time_series')
                ->cascadeOnDelete();
            $table->date('occurrence_date');
            $table->string('type'); // skip, override
            $table->time('override_start_time')->nullable();
            $table->time('override_end_time')->nullable();
            $table->string('override_title')->nullable();
            $table->string('override_reason')->nullable();
            $table->timestamps();

            $table->unique(['series_id', 'occurrence_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_blocked_time_exceptions');
    }
};
