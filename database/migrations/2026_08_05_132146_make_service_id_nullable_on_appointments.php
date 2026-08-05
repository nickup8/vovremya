<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->uuid('service_id')->nullable()->change();
            $table->foreign('service_id')->references('id')->on('services')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->uuid('service_id')->nullable(false)->change();
            $table->foreign('service_id')->references('id')->on('services')->restrictOnDelete();
        });
    }
};
