<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('cancellation_deadline_hours')
                ->nullable()
                ->after('id')
                ->comment('За сколько часов до визита клиент не может отменить запись онлайн. NULL = без ограничения.');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('cancellation_deadline_hours');
        });
    }
};
