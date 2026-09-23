<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->unique(['billing_cycle_id', 'attempt_number'], 'payment_attempts_cycle_attempt_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropUnique('payment_attempts_cycle_attempt_number_unique');
        });
    }
};
