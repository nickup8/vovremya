<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Partial unique index on payment_attempts: (provider, provider_payment_id) WHERE provider_payment_id IS NOT NULL
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->unique(['provider', 'provider_payment_id'], 'payment_attempts_provider_payment_id_unique')
                ->where('provider_payment_id', 'IS NOT NULL');
        });

        // Partial unique index on provider_events: (provider, provider_event_id) WHERE provider_event_id IS NOT NULL
        Schema::table('provider_events', function (Blueprint $table) {
            $table->unique(['provider', 'provider_event_id'], 'provider_events_provider_event_id_unique')
                ->where('provider_event_id', 'IS NOT NULL');
        });
    }

    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropIndex('payment_attempts_provider_payment_id_unique');
        });

        Schema::table('provider_events', function (Blueprint $table) {
            $table->dropIndex('provider_events_provider_event_id_unique');
        });
    }
};
