<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // One receipt row per payment attempt — the attempt is the
            // authoritative anchor, not the billing cycle.
            $table->foreignUuid('payment_attempt_id')->constrained('payment_attempts')->cascadeOnDelete();
            $table->string('status', 20)->default('awaiting_manual');
            $table->text('receipt_url')->nullable();
            $table->foreignUuid('attached_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('attached_at')->nullable();
            // Manual refund verification — independent from receipt_url so a
            // refund never erases the link and a re-success never clears the flag.
            $table->boolean('refund_review_required')->default(false);
            $table->foreignUuid('refund_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('refund_reviewed_at')->nullable();
            $table->timestamps();

            $table->unique('payment_attempt_id');
            $table->index('status');
            $table->index('refund_review_required');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payment_receipts
            ADD CONSTRAINT payment_receipts_status_check
            CHECK (status IN ('awaiting_manual', 'attached'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
    }
};
