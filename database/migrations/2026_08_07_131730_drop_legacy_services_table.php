<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign('appointments_service_id_foreign');
        });

        Schema::dropIfExists('services');
        // Колонка appointments.service_id ОСТАВЛЕНА (двухфазно, dropColumn = D3)
    }

    public function down(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->decimal('price', 10, 2);
            $table->integer('duration_minutes');
            $table->timestamps();
            $table->index('user_id');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreign('service_id')->references('id')->on('services')->nullOnDelete();
        });
    }
};
