<?php

use App\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            Workspace::whereNull('slug')
                ->orWhere('slug', '')
                ->each(fn (Workspace $workspace) => $workspace->ensureSlug());
        });
    }

    public function down(): void
    {
        // slug обратно не обнуляем
    }
};
