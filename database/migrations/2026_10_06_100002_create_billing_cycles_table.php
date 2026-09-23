<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_cycles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('billing_subscription_id')->constrained('billing_subscriptions')->cascadeOnDelete();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('tariff_plan_id')->constrained('tariff_plans')->restrictOnDelete();
            $table->foreignUuid('plan_price_id')->nullable()->constrained('plan_prices')->nullOnDelete();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('RUB');
            $table->json('price_snapshot')->nullable();
            $table->string('origin', 30)->default('payment');
            $table->string('legacy_subscription_id')->nullable();
            $table->timestamps();

            $table->unique(
                ['billing_subscription_id', 'period_start', 'period_end'],
                'billing_cycles_subscription_period_unique',
            );

            $table->index('workspace_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_cycles');
    }
};
