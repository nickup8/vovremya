<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_backups', function (Blueprint $table) {
            // Токен текущего исполнителя: begin() ставит его при атомарном
            // захвате, markSucceeded/markInterrupted требуют совпадения —
            // запоздавший процесс не может перезаписать результат.
            $table->uuid('execution_token')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('database_backups', function (Blueprint $table) {
            $table->dropColumn('execution_token');
        });
    }
};
