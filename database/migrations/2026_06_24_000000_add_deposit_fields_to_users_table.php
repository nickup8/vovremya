<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('require_deposit')->default(false)->after('address');
            $table->decimal('deposit_amount', 10, 2)->nullable()->after('require_deposit');
            $table->string('deposit_type')->default('fixed')->after('deposit_amount');
            $table->integer('cancel_window_hours')->default(24)->after('deposit_type');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'require_deposit', 'deposit_amount', 'deposit_type', 'cancel_window_hours',
            ]);
        });
    }
};
