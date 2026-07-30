<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_service_provider')->default(false)->after('is_bookable');
            $table->boolean('admin_can_see_finance')->default(true)->after('is_service_provider');
        });

        DB::table('users')
            ->where('is_master', true)
            ->where('is_bookable', true)
            ->update(['is_service_provider' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_service_provider', 'admin_can_see_finance']);
        });
    }
};
