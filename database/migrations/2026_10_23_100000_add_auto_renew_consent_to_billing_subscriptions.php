<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table) {
            $table->timestamp('auto_renew_consent_at')->nullable();
            $table->string('auto_renew_consent_version', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['auto_renew_consent_at', 'auto_renew_consent_version']);
        });
    }
};
