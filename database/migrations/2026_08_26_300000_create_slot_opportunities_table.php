<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slot_opportunities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('origin_event_id')->unique();
            $table->uuid('chain_id');
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('master_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('master_service_id')->constrained('master_service')->cascadeOnDelete();
            $table->foreignUuid('source_appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->string('source_type');
            $table->string('status')->default('open');
            $table->timestamp('start_time');
            $table->integer('duration');
            $table->foreignUuid('filled_by_appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->timestamp('filled_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamps();

            $table->index('chain_id');
            $table->index('source_appointment_id');
            $table->index(['workspace_id', 'master_id', 'master_service_id', 'status', 'start_time']);
        });

        DB::statement("
            ALTER TABLE slot_opportunities
            ADD CONSTRAINT slot_opportunities_duration_positive
            CHECK (duration > 0)
        ");

        DB::statement("
            ALTER TABLE slot_opportunities
            ADD CONSTRAINT slot_opportunities_source_type_valid
            CHECK (source_type IN ('cancellation', 'reschedule', 'autofill_reschedule'))
        ");

        DB::statement("
            ALTER TABLE slot_opportunities
            ADD CONSTRAINT slot_opportunities_status_valid
            CHECK (status IN ('open', 'filled', 'expired', 'invalidated'))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE slot_opportunities DROP CONSTRAINT IF EXISTS slot_opportunities_status_valid');
        DB::statement('ALTER TABLE slot_opportunities DROP CONSTRAINT IF EXISTS slot_opportunities_source_type_valid');
        DB::statement('ALTER TABLE slot_opportunities DROP CONSTRAINT IF EXISTS slot_opportunities_duration_positive');
        Schema::dropIfExists('slot_opportunities');
    }
};
