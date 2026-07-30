<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('master_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->decimal('price', 10, 2)->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['master_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_service');
    }
};
