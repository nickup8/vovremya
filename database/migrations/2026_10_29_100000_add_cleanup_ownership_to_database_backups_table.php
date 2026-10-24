<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_backups', function (Blueprint $table) {
            // Отложенная очистка принадлежит КОНКРЕТНОМУ исполнению и
            // КОНКРЕТНЫМ ключам: пока строку могло перехватить новое
            // исполнение (у него свои object_key/manifest_key), recover
            // повторяет удаление только по записанным ключам владельца
            // и никогда не берёт текущие ключи чужой (успешной) записи.
            $table->json('cleanup_keys')->nullable()->after('cleanup_attempts');
            $table->string('cleanup_execution_token', 64)->nullable()->after('cleanup_keys');
        });
    }

    public function down(): void
    {
        Schema::table('database_backups', function (Blueprint $table) {
            $table->dropColumn(['cleanup_keys', 'cleanup_execution_token']);
        });
    }
};
