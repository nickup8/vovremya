<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Версия акта разрешения ВК: растёт при каждом granted=true,
            // чтобы job мог отличить новое разрешение от старого при CAS 901.
            $table->unsignedBigInteger('vk_permission_version')
                ->default(0)
                ->after('vk_messages_allowed');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('vk_permission_version');
        });
    }
};
