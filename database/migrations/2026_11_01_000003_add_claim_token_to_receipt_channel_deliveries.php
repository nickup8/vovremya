<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipt_channel_deliveries', function (Blueprint $table) {
            // Ownership of the current claim. Written on EVERY capture — the
            // first insert and the takeover of an expired lease — so the
            // success write and the release of a superseded worker can be
            // conditioned on its own token and never touch the new owner's
            // row. Nullable only for rows created before this column existed.
            $table->uuid('claim_token')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('receipt_channel_deliveries', function (Blueprint $table) {
            $table->dropColumn('claim_token');
        });
    }
};
