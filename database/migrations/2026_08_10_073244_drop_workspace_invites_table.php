<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('workspace_invites');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('workspace_invites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('role')->default('master');
            $table->string('token')->unique();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index('token');
        });
    }
};
