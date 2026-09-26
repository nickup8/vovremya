<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            CREATE UNIQUE INDEX payment_attempts_provider_payment_id_unique
            ON payment_attempts (provider, provider_payment_id)
            WHERE provider_payment_id IS NOT NULL
        ');

        DB::statement('
            CREATE UNIQUE INDEX provider_events_provider_event_id_unique
            ON provider_events (provider, provider_event_id)
            WHERE provider_event_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payment_attempts_provider_payment_id_unique');
        DB::statement('DROP INDEX IF EXISTS provider_events_provider_event_id_unique');
    }
};
