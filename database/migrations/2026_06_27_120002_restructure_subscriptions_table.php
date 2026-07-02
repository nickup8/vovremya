<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasData = DB::table('subscriptions')->count() > 0;

        $rows = [];
        if ($hasData) {
            $rows = DB::table('subscriptions')->get()->toArray();
        }

        Schema::dropIfExists('subscriptions');

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('tariff_plan_id')->nullable();
            $table->unsignedInteger('period_months')->default(1);
            $table->unsignedInteger('amount_paid')->default(0);
            $table->string('status', 20)->default('pending');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('payment_id')->nullable();
            $table->timestamps();
        });

        foreach ($rows as $row) {
            DB::table('subscriptions')->insert((array) $row);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_transaction_id')->nullable();
            $table->decimal('amount', 10, 2);
            $table->enum('period', ['monthly', 'yearly']);
            $table->enum('status', ['pending', 'paid', 'failed', 'refunded'])->default('pending');
            $table->timestamps();
        });
    }
};
