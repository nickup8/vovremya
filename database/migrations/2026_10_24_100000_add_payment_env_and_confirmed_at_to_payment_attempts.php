<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->string('payment_env', 10)->nullable()->after('provider');
            $table->timestamp('confirmed_at')->nullable()->after('finished_at');
        });

        // Explicit allow-list: only demo/live/NULL. Invalid values must never
        // be written; the application maps unknown config to demo before save.
        DB::statement(<<<'SQL'
            ALTER TABLE payment_attempts
            ADD CONSTRAINT payment_attempts_payment_env_check
            CHECK (payment_env IS NULL OR payment_env IN ('demo', 'live'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT IF EXISTS payment_attempts_payment_env_check');

        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropColumn(['payment_env', 'confirmed_at']);
        });
    }
};
