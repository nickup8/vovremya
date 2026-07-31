<?php

use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $service = app(WorkspaceService::class);

        User::whereNull('workspace_id')
            ->get()
            ->each(function (User $user) use ($service) {
                $service->createForUser($user);
            });
    }

    public function down(): void
    {
        // Backfill — не откатываем
    }
};
