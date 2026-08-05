<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement("
            ALTER TABLE appointments
            ADD CONSTRAINT appointments_no_overlap
            EXCLUDE USING gist (
                master_id WITH =,
                tsrange(start_time, start_time + (COALESCE(duration, 60) * INTERVAL '1 minute')) WITH &&
            )
            WHERE (status IN ('booked','pending_payment','prepaid','paid'))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_no_overlap');
    }
};
