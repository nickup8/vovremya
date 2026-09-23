<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tariff_plan_id')->constrained('tariff_plans')->restrictOnDelete();
            $table->unsignedInteger('period_months');
            $table->unsignedInteger('base_amount');
            $table->unsignedInteger('discount_percent')->default(0);
            $table->unsignedInteger('final_amount');
            $table->string('currency', 3)->default('RUB');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('valid_from');
            $table->timestamp('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['tariff_plan_id', 'period_months', 'version'],
                'plan_prices_plan_period_version_unique',
            );

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_prices');
    }
};
