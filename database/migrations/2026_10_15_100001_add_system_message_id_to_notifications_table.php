<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->uuid('system_message_id')->nullable()->after('read_at');
            $table->foreign('system_message_id')->references('id')->on('system_notification_messages');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->index('system_message_id');
            $table->index(['system_message_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropForeign(['system_message_id']);
            $table->dropIndex(['system_message_id', 'read_at']);
            $table->dropIndex(['system_message_id']);
            $table->dropColumn('system_message_id');
        });
    }
};
