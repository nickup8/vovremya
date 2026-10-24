<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_backups', function (Blueprint $table) {
            // Явное состояние «ожидает очистки»: S3 delete не удался, объекты
            // этого исполнения ещё лежат в бакете. backups:recover повторяет
            // очистку ограниченное число раз (cleanup_attempts / cleanup_retry_max).
            $table->timestamp('cleanup_pending_at')->nullable()->after('prune_error_message');
            $table->unsignedTinyInteger('cleanup_attempts')->default(0)->after('cleanup_pending_at');
        });
    }

    public function down(): void
    {
        Schema::table('database_backups', function (Blueprint $table) {
            $table->dropColumn(['cleanup_pending_at', 'cleanup_attempts']);
        });
    }
};
