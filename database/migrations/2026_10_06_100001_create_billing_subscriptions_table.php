<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('tariff_plan_id')->constrained('tariff_plans')->restrictOnDelete();
            $table->string('status', 30)->default('pending_initial');
            $table->unsignedInteger('renewal_period_months')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('next_charge_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('grace_until')->nullable();
            $table->foreignUuid('plan_price_id')->nullable()->constrained('plan_prices')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['workspace_id', 'tariff_plan_id'],
                'billing_subscriptions_workspace_plan_unique',
            );

            $table->index('status');
            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_subscriptions');
    }
};
