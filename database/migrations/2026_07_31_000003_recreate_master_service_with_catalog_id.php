<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('master_service');

        Schema::create('master_service', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('master_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('catalog_id')->constrained('service_catalog')->cascadeOnDelete();
            $table->decimal('price_override', 10, 2)->nullable();
            $table->unsignedInteger('duration_override')->nullable();
            $table->boolean('is_custom')->default(false);
            $table->string('status')->default('approved');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['master_id', 'catalog_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_service');

        Schema::create('master_service', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('master_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('service_id')->constrained('services')->cascadeOnDelete();
            $table->decimal('price', 10, 2)->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['master_id', 'service_id']);
        });
    }
};
