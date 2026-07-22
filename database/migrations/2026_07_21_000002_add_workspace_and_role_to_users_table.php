<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('workspace_id')->nullable()->after('id')->constrained('workspaces')->nullOnDelete();
            $table->string('role')->default('owner')->after('workspace_id');
        });

        // Удаляем индекс по колонке tariff ПЕРЕД удалением самой колонки.
        // Без этого SQLite падает: "error in index users_tariff_idx after drop column".
        if (DB::getSchemaBuilder()->hasIndex('users', 'users_tariff_idx')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex('users_tariff_idx');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['tariff', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('tariff')->default('free')->after('slot_interval');
            $table->timestamp('expires_at')->nullable()->after('tariff');
            $table->dropForeign(['workspace_id']);
            $table->dropColumn(['workspace_id', 'role']);
        });
    }
};
