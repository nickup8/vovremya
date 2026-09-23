<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('billing_cycle_id')->constrained('billing_cycles')->cascadeOnDelete();
            $table->foreignUuid('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->string('provider', 50)->nullable();
            $table->unsignedInteger('attempt_number')->default(1);
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('RUB');
            $table->string('internal_order_id')->unique();
            $table->string('provider_payment_id')->nullable();
            $table->string('status', 30)->default('created');
            $table->string('failure_code')->nullable();
            $table->string('failure_category')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('billing_cycle_id');
            $table->index('provider_payment_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
