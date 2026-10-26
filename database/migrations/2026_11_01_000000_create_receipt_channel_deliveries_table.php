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
            // The exact URL the message contained, so a changed link is a
            // fresh delivery target while a retry of the same URL is not.
            $table->text('receipt_url');
            // sha256 hex of receipt_url: keeps the unique index small
            // (the full URL may reach 2048 chars — too long for btree).
            $table->char('receipt_url_hash', 64);
            $table->timestamp('delivered_at');
            $table->timestamps();

            $table->unique(
                ['payment_receipt_id', 'channel', 'receipt_url_hash'],
                'receipt_channel_delivery_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_channel_deliveries');
    }
};
