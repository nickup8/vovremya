<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('master_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('token')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('master_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_links');
    }
};
