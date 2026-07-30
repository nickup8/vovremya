<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('is_personal')->default(true)->after('user_id');
            $table->foreignUuid('workspace_id')->nullable()->after('is_personal')
                ->constrained('workspaces')->nullOnDelete();
            $table->index('workspace_id');
        });

        DB::statement('
            UPDATE clients SET workspace_id = (
                SELECT u.workspace_id FROM users u WHERE u.id = clients.user_id
            ) WHERE EXISTS (
                SELECT 1 FROM users u WHERE u.id = clients.user_id AND u.workspace_id IS NOT NULL
            )
        ');
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['workspace_id']);
            $table->dropColumn(['is_personal', 'workspace_id']);
        });
    }
};
