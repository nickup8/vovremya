<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'require_deposit', 'deposit_amount', 'deposit_type', 'cancel_window_hours',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('soft_deposit')->default(false);
            $table->integer('deposit_timeout')->default(15);
            $table->integer('deposit_percent')->default(30);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['soft_deposit', 'deposit_timeout', 'deposit_percent']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('require_deposit')->default(false);
            $table->decimal('deposit_amount', 10, 2)->nullable();
            $table->string('deposit_type')->default('fixed');
            $table->integer('cancel_window_hours')->default(24);
        });
    }
};
