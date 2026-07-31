<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_catalog', function (Blueprint $table) {
            $table->string('category')->nullable()->after('title');
            $table->decimal('base_price', 10, 2)->nullable()->after('category');
            $table->unsignedInteger('base_duration')->nullable()->after('base_price');
            $table->boolean('is_active')->default(true)->after('base_duration');
        });
    }

    public function down(): void
    {
        Schema::table('service_catalog', function (Blueprint $table) {
            $table->dropColumn(['category', 'base_price', 'base_duration', 'is_active']);
        });
    }
};
