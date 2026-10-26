<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            // Monotonic identity of one real link attach/change: bumped on
            // every change, never reused. (receipt_id, url_version) is the
            // messenger delivery event key; re-saving the same URL does not
            // bump it (no-op), returning to an older URL does.
            $table->unsignedInteger('url_version')->default(0)->after('receipt_url');
        });

        // Rows that already carry a link sit at version 1 — their next real
        // change becomes version 2 and is messaged as «Ссылка на чек обновлена».
        DB::table('payment_receipts')
            ->whereNotNull('receipt_url')
            ->update(['url_version' => 1]);
    }

    public function down(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->dropColumn('url_version');
        });
    }
};
