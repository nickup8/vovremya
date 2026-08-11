<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->timestamp('pdn_consent_at')->nullable()->after('auth_token');
            $table->string('pdn_consent_version')->nullable()->after('pdn_consent_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('pdn_consent_at')->nullable();
            $table->string('pdn_consent_version')->nullable()->after('pdn_consent_at');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['pdn_consent_at', 'pdn_consent_version']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pdn_consent_at', 'pdn_consent_version']);
        });
    }
};
