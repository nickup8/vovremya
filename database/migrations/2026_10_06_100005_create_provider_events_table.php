<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 50);
            $table->string('provider_event_id')->nullable();
            $table->string('event_type')->nullable();
            $table->string('dedup_key')->unique();
            $table->foreignUuid('payment_attempt_id')->nullable()->constrained('payment_attempts')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->json('signature_metadata')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamps();

            $table->index('provider');
            $table->index('payment_attempt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_events');
    }
};
