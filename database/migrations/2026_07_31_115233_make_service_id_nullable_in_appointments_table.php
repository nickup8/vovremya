<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('service_id')->nullable()->change();
        });

        DB::statement('UPDATE appointments SET service_id = NULL WHERE service_id NOT IN (SELECT id FROM services)');
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('service_id')->nullable(false)->change();
        });
    }
};
