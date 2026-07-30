<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_catalog', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->timestamps();

            $table->index('workspace_id');
            $table->unique(['workspace_id', 'title']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_catalog');
    }
};
