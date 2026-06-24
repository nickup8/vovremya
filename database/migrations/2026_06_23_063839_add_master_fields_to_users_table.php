<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_id')->unique()->nullable()->after('email');
            $table->string('max_id')->unique()->nullable()->after('telegram_id');
            $table->string('avatar_url')->nullable()->after('max_id');
            $table->boolean('is_master')->default(false)->after('avatar_url');
            $table->string('master_slug')->unique()->nullable()->after('is_master');
            $table->string('specialty')->nullable()->after('master_slug');
            $table->text('address')->nullable()->after('specialty');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'telegram_id', 'max_id', 'avatar_url',
                'is_master', 'master_slug', 'specialty', 'address',
            ]);
        });
    }
};
