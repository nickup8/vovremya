<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            if (Schema::hasColumn('workspaces', 'parent_workspace_id')) {
                $table->dropForeign(['parent_workspace_id']);
                $table->dropColumn('parent_workspace_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            if (! Schema::hasColumn('workspaces', 'parent_workspace_id')) {
                $table->foreignUuid('parent_workspace_id')
                    ->nullable()
                    ->after('owner_id')
                    ->constrained('workspaces')
                    ->nullOnDelete();
            }
        });
    }
};
