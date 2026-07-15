<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('is_blocked')
            ->update(['is_blocked' => false]);

        DB::table('users')
            ->whereNull('is_super_admin')
            ->update(['is_super_admin' => false]);

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_blocked')->default(false)->nullable(false)->change();
            $table->boolean('is_super_admin')->default(false)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_blocked')->default(false)->nullable()->change();
            $table->boolean('is_super_admin')->default(false)->nullable()->change();
        });
    }
};
