<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Transactional outbox for receipt messenger delivery: one row per
        // real link change, written in the SAME transaction as the URL, the
        // database notification and the audit record. Rollback removes it
        // together with the change, so a rolled-back change can never be
        // messaged; after a successful commit the row is the durable delivery
        // intent until a worker job is enqueued (dispatched_at).
        Schema::create('receipt_delivery_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_receipt_id')->constrained('payment_receipts')->cascadeOnDelete();
            // Snapshot of the event: identity (version) + the URL the message
            // must carry. While the receipt is still at this version, the
            // snapshot equals the current URL; a newer version makes the event
            // stale and it is skipped instead of re-sending.
            $table->unsignedInteger('url_version');
            $table->text('receipt_url');
            // NULL = intent committed but the job was never enqueued (crash
            // between commit and dispatch, or queue failure) — recovery
            // re-dispatches exactly these rows. Once set, the queue owns the
            // job and its bounded retries; recovery never re-sends.
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();

            $table->unique(['payment_receipt_id', 'url_version']);
            $table->index('dispatched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_delivery_events');
    }
};
