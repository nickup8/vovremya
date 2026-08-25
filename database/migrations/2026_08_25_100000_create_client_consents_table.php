<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_consents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('clients')->nullOnDelete();
            $table->foreignUuid('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->foreignUuid('master_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->string('action');
            $table->string('version')->nullable();
            $table->string('source')->nullable();
            $table->string('channel')->nullable();
            $table->text('consent_text')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['client_id', 'type', 'occurred_at']);
            $table->index(['workspace_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_consents');
    }
};
