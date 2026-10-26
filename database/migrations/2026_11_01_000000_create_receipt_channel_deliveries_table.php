<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_channel_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_receipt_id')->constrained('payment_receipts')->cascadeOnDelete();
            // max | vk — the two messenger channels available today.
            $table->string('channel', 16);
            // Event identity: (receipt, url_version) is one real link change.
            // Dedupe happens per event — A → B → A is version 1, 2, 3, so
            // returning to an earlier URL is messaged again, while a retry of
            // the same event is not.
            $table->unsignedInteger('url_version');
            // The exact URL this event carries (equals the receipt URL while
            // the event is current).
            $table->text('receipt_url');
            // pending  = a worker holds the claim and may be inside HTTP;
            // delivered = the channel accepted the message (provider_message_id).
            $table->string('status', 16)->default('pending');
            // Claim TTL: after it expires a pending row may be reclaimed, so a
            // crashed worker cannot strand the event. TTL > job timeout (30s)
            // and > API client HTTP timeout (10s), so a live claimer is never
            // preempted mid-request.
            $table->timestamp('lease_expires_at')->nullable();
            // Message id returned by MAX/VK — proof of the accepted send.
            $table->string('provider_message_id')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            // One row per event per channel: the claim is taken BEFORE the
            // HTTP call, so two overlapping jobs serialize here.
            $table->unique(
                ['payment_receipt_id', 'channel', 'url_version'],
                'receipt_channel_delivery_unique'
            );
            $table->index(['status', 'lease_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_channel_deliveries');
    }
};
