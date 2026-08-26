<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slot_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('master_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignUuid('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->foreignUuid('master_service_id')->constrained('master_service')->cascadeOnDelete();

            $table->string('type');
            $table->string('status')->default('active');
            $table->string('request_source');
            $table->string('delivery_channel');

            $table->date('date_from');
            $table->date('date_to');
            $table->time('time_from');
            $table->time('time_to');
            $table->string('timezone');

            $table->timestamp('appointment_start_time_snapshot')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expired_at')->nullable();

            $table->timestamps();

            $table->index(['workspace_id', 'master_id', 'master_service_id', 'type', 'status']);
        });

        // date_from <= date_to
        DB::statement("
            ALTER TABLE slot_requests
            ADD CONSTRAINT slot_requests_date_order
            CHECK (date_from <= date_to)
        ");

        // time_from < time_to (no overnight windows in MVP)
        DB::statement("
            ALTER TABLE slot_requests
            ADD CONSTRAINT slot_requests_time_order
            CHECK (time_from < time_to)
        ");

        // EARLIER: appointment_id and snapshot both required
        // OPEN: appointment_id and snapshot both null
        DB::statement("
            ALTER TABLE slot_requests
            ADD CONSTRAINT slot_requests_type_appointment_invariant
            CHECK (
                (type = 'earlier' AND appointment_id IS NOT NULL AND appointment_start_time_snapshot IS NOT NULL)
                OR
                (type = 'open' AND appointment_id IS NULL AND appointment_start_time_snapshot IS NULL)
            )
        ");

        // Only one active EARLIER request per appointment
        DB::statement('
            CREATE UNIQUE INDEX slot_requests_active_earlier_per_appointment
            ON slot_requests (appointment_id)
            WHERE type = \'earlier\' AND status = \'active\'
        ');

        // Only one active OPEN request per client + master_service
        DB::statement('
            CREATE UNIQUE INDEX slot_requests_active_open_per_client_service
            ON slot_requests (client_id, master_service_id)
            WHERE type = \'open\' AND status = \'active\'
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS slot_requests_active_open_per_client_service');
        DB::statement('DROP INDEX IF EXISTS slot_requests_active_earlier_per_appointment');
        DB::statement('ALTER TABLE slot_requests DROP CONSTRAINT IF EXISTS slot_requests_type_appointment_invariant');
        DB::statement('ALTER TABLE slot_requests DROP CONSTRAINT IF EXISTS slot_requests_time_order');
        DB::statement('ALTER TABLE slot_requests DROP CONSTRAINT IF EXISTS slot_requests_date_order');
        Schema::dropIfExists('slot_requests');
    }
};
