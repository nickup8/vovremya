<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slot_offers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('slot_request_id')->constrained('slot_requests');
            $table->foreignUuid('slot_opportunity_id')->constrained('slot_opportunities');
            $table->string('status')->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamps();

            $table->unique(['slot_request_id', 'slot_opportunity_id']);
            $table->index(['status', 'expires_at']);
        });

        // Status allowed values
        DB::statement("
            ALTER TABLE slot_offers
            ADD CONSTRAINT slot_offers_status_valid
            CHECK (status IN ('pending', 'accepted', 'declined', 'expired', 'invalidated'))
        ");

        // One pending offer per request
        DB::statement('
            CREATE UNIQUE INDEX slot_offers_pending_request
            ON slot_offers (slot_request_id)
            WHERE status = \'pending\'
        ');

        // One pending offer per opportunity
        DB::statement('
            CREATE UNIQUE INDEX slot_offers_pending_opportunity
            ON slot_offers (slot_opportunity_id)
            WHERE status = \'pending\'
        ');

        // Timestamp-state invariant:
        // Each resolved status has exactly one corresponding timestamp,
        // and pending has none.
        DB::statement("
            ALTER TABLE slot_offers
            ADD CONSTRAINT slot_offers_timestamp_state
            CHECK (
                (status = 'pending'
                    AND accepted_at IS NULL
                    AND declined_at IS NULL
                    AND expired_at IS NULL
                    AND invalidated_at IS NULL)
                OR
                (status = 'accepted'
                    AND accepted_at IS NOT NULL
                    AND declined_at IS NULL
                    AND expired_at IS NULL
                    AND invalidated_at IS NULL)
                OR
                (status = 'declined'
                    AND declined_at IS NOT NULL
                    AND accepted_at IS NULL
                    AND expired_at IS NULL
                    AND invalidated_at IS NULL)
                OR
                (status = 'expired'
                    AND expired_at IS NOT NULL
                    AND accepted_at IS NULL
                    AND declined_at IS NULL
                    AND invalidated_at IS NULL)
                OR
                (status = 'invalidated'
                    AND invalidated_at IS NOT NULL
                    AND accepted_at IS NULL
                    AND declined_at IS NULL
                    AND expired_at IS NULL)
            )
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE slot_offers DROP CONSTRAINT IF EXISTS slot_offers_timestamp_state');
        DB::statement('DROP INDEX IF EXISTS slot_offers_pending_opportunity');
        DB::statement('DROP INDEX IF EXISTS slot_offers_pending_request');
        DB::statement('ALTER TABLE slot_offers DROP CONSTRAINT IF EXISTS slot_offers_status_valid');
        Schema::dropIfExists('slot_offers');
    }
};
