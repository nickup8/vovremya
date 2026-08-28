<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slot_offers', function (Blueprint $table) {
            $table->timestamp('sent_at')->nullable()->after('invalidated_at');
            $table->string('delivery_mid', 255)->nullable()->after('sent_at');
            $table->string('invalidation_reason', 50)->nullable()->after('delivery_mid');
        });

        Schema::table('slot_opportunities', function (Blueprint $table) {
            $table->string('invalidation_reason', 50)->nullable()->after('invalidated_at');
        });
    }

    public function down(): void
    {
        Schema::table('slot_offers', function (Blueprint $table) {
            $table->dropColumn(['sent_at', 'delivery_mid', 'invalidation_reason']);
        });

        Schema::table('slot_opportunities', function (Blueprint $table) {
            $table->dropColumn(['invalidation_reason']);
        });
    }
};
