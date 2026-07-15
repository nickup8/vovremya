<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('vk_id')->nullable()->after('max_id');
            $table->string('vk_chat_id')->nullable()->after('vk_id');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->string('vk_id')->nullable()->after('max_chat_id');
            $table->string('vk_chat_id')->nullable()->after('vk_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['vk_id', 'vk_chat_id']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['vk_id', 'vk_chat_id']);
        });
    }
};
