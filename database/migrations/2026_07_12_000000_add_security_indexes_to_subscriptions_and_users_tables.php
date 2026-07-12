<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->index('payment_id', 'subscriptions_payment_id_idx');
            $table->index('status', 'subscriptions_status_idx');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('tariff', 'users_tariff_idx');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex('subscriptions_payment_id_idx');
            $table->dropIndex('subscriptions_status_idx');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_tariff_idx');
        });
    }
};
