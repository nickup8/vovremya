<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Нормализуем role: 'staff' → 'master'
        DB::table('users')
            ->where('role', 'staff')
            ->update(['role' => 'master']);

        // Добавляем parent_workspace_id для связи мастерских
        Schema::table('workspaces', function (Blueprint $table) {
            $table->foreignUuid('parent_workspace_id')->nullable()->after('owner_id')->constrained('workspaces')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropForeign(['parent_workspace_id']);
            $table->dropColumn('parent_workspace_id');
        });
    }
};
