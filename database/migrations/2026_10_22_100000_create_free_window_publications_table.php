<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('free_window_publications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('master_id');
            $table->string('token', 64)->unique();
            $table->string('content_hash', 64)->index();
            $table->string('mode', 16); // 'service' | 'all'
            $table->uuid('master_service_id')->nullable();
            $table->date('date_from');
            $table->date('date_to');
            $table->json('payload');
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at');
        });

        Schema::table('free_window_publications', function (Blueprint $table) {
            $table->index('master_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('free_window_publications');
    }
};
