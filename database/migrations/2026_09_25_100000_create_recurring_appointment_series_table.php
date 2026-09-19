<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_appointment_series', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('master_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignUuid('master_service_id')->constrained('master_service')->restrictOnDelete();
            $table->date('start_date');
            $table->time('start_time');
            $table->string('recurrence_type'); // daily, weekly
            $table->unsignedInteger('interval')->default(1);
            $table->json('weekdays')->nullable();
            $table->date('ends_at')->nullable();
            $table->unsignedInteger('occurrences_count')->nullable();
            $table->string('timezone')->default('Europe/Moscow');
            $table->string('status')->default('active'); // active, cancelled
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['master_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_appointment_series');
    }
};
