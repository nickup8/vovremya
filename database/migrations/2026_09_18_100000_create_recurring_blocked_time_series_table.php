<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_blocked_time_series', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->string('reason')->nullable();
            $table->date('start_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('recurrence_type'); // daily, weekly, custom_weekly
            $table->unsignedInteger('interval')->default(1);
            $table->json('weekdays')->nullable();
            $table->date('ends_at')->nullable();
            $table->string('timezone')->default('Europe/Moscow');
            $table->string('status')->default('active'); // active, paused, cancelled
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_blocked_time_series');
    }
};
