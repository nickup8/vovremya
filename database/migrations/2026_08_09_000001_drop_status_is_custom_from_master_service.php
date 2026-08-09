<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_service', function (Blueprint $table) {
            $table->dropColumn(['status', 'is_custom']);
        });
    }

    public function down(): void
    {
        Schema::table('master_service', function (Blueprint $table) {
            $table->boolean('is_custom')->default(false);
            $table->string('status')->default('approved');
        });
    }
};
